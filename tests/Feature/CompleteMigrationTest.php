<?php

namespace Tests\Feature;

use App\Jobs\Migrations\CompleteMigration;
use App\Models\ActionProgress;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Notifications\MigrationCompleted;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompleteMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function complete_migration_marks_progress_as_completed(): void
    {
        $integration = $this->makeMonzoIntegration();

        // Create initial progress record
        $progressRecord = ActionProgress::createProgress(
            $integration->user_id,
            'migration',
            "integration_{$integration->id}",
            'processing',
            'Processing migration data...',
            75
        );

        // Pause the integration (simulating migration state)
        $integration->update([
            'configuration->paused_during_migration' => true,
        ]);

        $job = new CompleteMigration($integration, 'monzo');
        $job->handle();

        // Refresh progress record
        $progressRecord->refresh();

        // Should have marked as completed
        $this->assertEquals('completed', $progressRecord->step);
        $this->assertEquals(100, $progressRecord->progress);
        $this->assertTrue($progressRecord->isCompleted());
        $this->assertEquals('monzo', $progressRecord->details['service']);
        $this->assertArrayHasKey('completed_at', $progressRecord->details);

        // Should have unpaused the integration
        $integration->refresh();
        $this->assertNull($integration->configuration['paused_during_migration'] ?? null);
    }

    #[Test]
    public function complete_migration_handles_missing_progress_record_gracefully(): void
    {
        $integration = $this->makeMonzoIntegration();

        // No progress record exists
        $this->assertNull(ActionProgress::getLatestProgress(
            $integration->user_id,
            'migration',
            "integration_{$integration->id}"
        ));

        // Pause the integration (simulating migration state)
        $integration->update([
            'configuration->paused_during_migration' => true,
        ]);

        $job = new CompleteMigration($integration, 'monzo');

        // Should not throw an exception
        $job->handle();

        // Should still unpause the integration
        $integration->refresh();
        $this->assertNull($integration->configuration['paused_during_migration'] ?? null);
    }

    #[Test]
    public function complete_migration_works_with_different_services(): void
    {
        $integration = $this->makeGoCardlessIntegration();

        // Create initial progress record
        $progressRecord = ActionProgress::createProgress(
            $integration->user_id,
            'migration',
            "integration_{$integration->id}",
            'processing',
            'Processing migration data...',
            75
        );

        $job = new CompleteMigration($integration, 'gocardless');
        $job->handle();

        // Refresh progress record
        $progressRecord->refresh();

        // Should have completed with correct service
        $this->assertTrue($progressRecord->isCompleted());
        $this->assertEquals('gocardless', $progressRecord->details['service']);
    }

    #[Test]
    public function complete_migration_sends_notification_with_statistics(): void
    {
        Notification::fake();

        $integration = $this->makeMonzoIntegration();

        // Set migration start time
        $migrationStartTime = Carbon::now()->subMinutes(10);
        $integration->update([
            'configuration' => [
                'migration_started_at' => $migrationStartTime->toIso8601String(),
            ],
        ]);

        // Create some events that were imported during the migration
        Event::factory()->count(15)->create([
            'integration_id' => $integration->id,
            'service' => 'monzo',
            'time' => Carbon::now()->subMonths(3),
            'created_at' => Carbon::now()->subMinutes(5), // Created during migration
        ]);

        Event::factory()->count(10)->create([
            'integration_id' => $integration->id,
            'service' => 'monzo',
            'time' => Carbon::now()->subMonths(6),
            'created_at' => Carbon::now()->subMinutes(8), // Created during migration
        ]);

        // Run the job
        $job = new CompleteMigration($integration, 'monzo');
        $job->handle();

        // Verify notification was sent
        Notification::assertSentTo(
            $integration->user,
            MigrationCompleted::class,
            function ($notification) use ($integration) {
                // Check that the notification has the correct integration
                $this->assertEquals($integration->id, $notification->integration->id);

                // Check that statistics were included
                $this->assertNotNull($notification->details);
                $this->assertArrayHasKey('events_imported', $notification->details);
                $this->assertEquals(25, $notification->details['events_imported']);

                // Check that date range is included
                $this->assertArrayHasKey('date_range', $notification->details);

                // Check that duration is included
                $this->assertArrayHasKey('duration', $notification->details);

                return true;
            }
        );
    }

    #[Test]
    public function complete_migration_handles_notification_failure_gracefully(): void
    {
        $integration = $this->makeMonzoIntegration();

        // Set migration start time but don't set up user relationship properly
        // This simulates a scenario where notification might fail
        $migrationStartTime = Carbon::now()->subMinutes(10);
        $integration->update([
            'configuration' => [
                'migration_started_at' => $migrationStartTime->toIso8601String(),
            ],
        ]);

        // Job should not throw an exception even if notification fails
        $job = new CompleteMigration($integration, 'monzo');

        // This should complete without throwing, even if notification has issues
        $this->expectNotToPerformAssertions();
        $job->handle();
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

    private function makeGoCardlessIntegration(): Integration
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'gocardless',
            'account_id' => null,
            'access_token' => 'test-token',
            'refresh_token' => null,
        ]);

        return Integration::create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'gocardless',
            'name' => 'GoCardless Transactions',
            'instance_type' => 'transactions',
            'configuration' => [],
        ]);
    }
}
