<?php

namespace Tests\Feature;

use App\Jobs\Migrations\MonitorBatchAndStartProcessing;
use App\Jobs\Migrations\StartProcessingIntegrationMigration;
use App\Models\ActionProgress;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MonitorBatchAndStartProcessingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function monitor_batch_updates_progress_when_batch_not_found(): void
    {
        $integration = $this->makeMonzoIntegration();

        // Create initial progress record
        $progressRecord = ActionProgress::createProgress(
            $integration->user_id,
            'migration',
            "integration_{$integration->id}",
            'monitoring',
            'Monitoring batch progress...',
            40
        );

        Queue::fake();

        $job = new MonitorBatchAndStartProcessing($integration, 'non-existent-batch-id');
        $job->handle();

        // Refresh progress record
        $progressRecord->refresh();

        // Should have updated to starting processing
        $this->assertEquals('starting_processing', $progressRecord->step);
        $this->assertEquals('Starting data processing...', $progressRecord->message);
        $this->assertEquals(60, $progressRecord->progress);
        $this->assertEquals('Batch not found, proceeding to processing phase', $progressRecord->details['note']);

        // Should have dispatched StartProcessingIntegrationMigration
        Queue::assertPushed(StartProcessingIntegrationMigration::class);
    }

    #[Test]
    public function monitor_batch_updates_progress_when_batch_finished(): void
    {
        $integration = $this->makeMonzoIntegration();

        // Create initial progress record
        $progressRecord = ActionProgress::createProgress(
            $integration->user_id,
            'migration',
            "integration_{$integration->id}",
            'monitoring',
            'Monitoring batch progress...',
            40
        );

        // Create a finished batch
        $batch = Bus::batch([])->dispatch();
        $batch->cancel(); // This marks it as finished

        Queue::fake();

        $job = new MonitorBatchAndStartProcessing($integration, $batch->id);
        $job->handle();

        // Refresh progress record
        $progressRecord->refresh();

        // Should have updated to starting processing
        $this->assertEquals('starting_processing', $progressRecord->step);
        $this->assertEquals('Fetch completed, starting data processing...', $progressRecord->message);
        $this->assertEquals(60, $progressRecord->progress);
        $this->assertEquals($batch->id, $progressRecord->details['batch_id']);
        $this->assertTrue($progressRecord->details['batch_finished']);

        // Should have dispatched StartProcessingIntegrationMigration
        Queue::assertPushed(StartProcessingIntegrationMigration::class);
    }

    #[Test]
    public function monitor_batch_updates_progress_while_monitoring_pending_batch(): void
    {
        $integration = $this->makeMonzoIntegration();

        // Create initial progress record
        $progressRecord = ActionProgress::createProgress(
            $integration->user_id,
            'migration',
            "integration_{$integration->id}",
            'monitoring',
            'Monitoring batch progress...',
            40
        );

        // Create a pending batch with some jobs
        $batch = Bus::batch([
            // Add some dummy jobs (they won't actually run in test)
        ])->dispatch();

        Queue::fake();

        $job = new MonitorBatchAndStartProcessing($integration, $batch->id);
        $job->handle();

        // Refresh progress record
        $progressRecord->refresh();

        // Should have updated monitoring progress
        $this->assertEquals('monitoring', $progressRecord->step);
        $this->assertEquals('Monitoring batch progress...', $progressRecord->message);
        $this->assertEquals(45, $progressRecord->progress);
        $this->assertEquals($batch->id, $progressRecord->details['batch_id']);
        $this->assertArrayHasKey('batch_pending_jobs', $progressRecord->details);
        $this->assertArrayHasKey('batch_processed_jobs', $progressRecord->details);
        $this->assertArrayHasKey('batch_total_jobs', $progressRecord->details);

        // Should have dispatched itself again with delay
        Queue::assertPushed(MonitorBatchAndStartProcessing::class);
    }

    #[Test]
    public function monitor_batch_handles_missing_progress_record_gracefully(): void
    {
        $integration = $this->makeMonzoIntegration();

        // No progress record exists
        $this->assertNull(ActionProgress::getLatestProgress(
            $integration->user_id,
            'migration',
            "integration_{$integration->id}"
        ));

        Queue::fake();

        $job = new MonitorBatchAndStartProcessing($integration, 'non-existent-batch-id');

        // Should not throw an exception
        $job->handle();

        // Should have dispatched StartProcessingIntegrationMigration regardless
        Queue::assertPushed(StartProcessingIntegrationMigration::class);
    }

    private function makeMonzoIntegration(): Integration
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'monzo',
            'account_id' => null,
            'access_token' => 'test-token',
            'refresh_token' => null,
        ]);

        return Integration::create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
            'name' => 'Monzo Transactions',
            'instance_type' => 'transactions',
            'configuration' => [],
        ]);
    }
}
