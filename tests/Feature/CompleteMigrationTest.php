<?php

namespace Tests\Feature;

use App\Jobs\Migrations\CompleteMigration;
use App\Models\ActionProgress;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
