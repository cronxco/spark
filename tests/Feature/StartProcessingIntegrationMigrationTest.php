<?php

namespace Tests\Feature;

use App\Jobs\Migrations\StartProcessingIntegrationMigration;
use App\Models\ActionProgress;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StartProcessingIntegrationMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function start_processing_creates_batch_with_completion_job(): void
    {
        $integration = $this->makeMonzoIntegration();

        // Create initial progress record
        $progressRecord = ActionProgress::createProgress(
            $integration->user_id,
            'migration',
            "integration_{$integration->id}",
            'starting_processing',
            'Fetch completed, starting data processing...',
            60
        );

        // Set up some cached transaction windows
        $windows = [
            ['since' => '2024-01-01T00:00:00Z', 'before' => '2024-01-02T00:00:00Z'],
            ['since' => '2024-01-02T00:00:00Z', 'before' => '2024-01-03T00:00:00Z'],
        ];
        Cache::put('monzo:migration:' . $integration->id . ':tx_windows', $windows, now()->addHours(6));
        Cache::put('monzo:migration:' . $integration->id . ':balances_last_date', '2024-01-03', now()->addHours(6));

        // Use queue fake to capture dispatched jobs
        Queue::fake();

        $job = new StartProcessingIntegrationMigration($integration);
        $job->handle();

        // Refresh progress record
        $progressRecord->refresh();

        // Should have updated progress to processing
        $this->assertEquals('processing_batch', $progressRecord->step);
        $this->assertEquals('Starting processing batch...', $progressRecord->message);
        $this->assertEquals(75, $progressRecord->progress);
        $this->assertEquals(5, $progressRecord->details['jobs_count']); // 2 tx windows + 1 pots + 1 balances + 1 completion job
        $this->assertEquals(2, $progressRecord->details['transaction_windows']);

        // Should have created a batch (we can't easily test the exact jobs in a batch without more setup)
        // But we can verify the integration's migration_batch_id was updated
        $integration->refresh();
        $this->assertNotNull($integration->migration_batch_id);
    }

    #[Test]
    public function start_processing_works_without_cached_data(): void
    {
        $integration = $this->makeMonzoIntegration();

        // Create initial progress record
        $progressRecord = ActionProgress::createProgress(
            $integration->user_id,
            'migration',
            "integration_{$integration->id}",
            'starting_processing',
            'Fetch completed, starting data processing...',
            60
        );

        // No cached data
        Queue::fake();

        $job = new StartProcessingIntegrationMigration($integration);
        $job->handle();

        // Should still work (just with minimal jobs)
        $progressRecord->refresh();
        $this->assertEquals('processing_batch', $progressRecord->step);
        $this->assertEquals(2, $progressRecord->details['jobs_count']); // 1 pots + 1 completion job (no balances without cached date)
        $this->assertEquals(0, $progressRecord->details['transaction_windows']);
    }

    #[Test]
    public function start_processing_handles_missing_progress_record(): void
    {
        $integration = $this->makeMonzoIntegration();

        // No progress record exists
        $this->assertNull(ActionProgress::getLatestProgress(
            $integration->user_id,
            'migration',
            "integration_{$integration->id}"
        ));

        Queue::fake();

        $job = new StartProcessingIntegrationMigration($integration);

        // Should not throw an exception
        $job->handle();

        // Should have updated migration_batch_id
        $integration->refresh();
        $this->assertNotNull($integration->migration_batch_id);
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
