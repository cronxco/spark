<?php

namespace Tests\Feature;

use App\Jobs\Migrations\StartIntegrationMigration;
use App\Models\ActionProgress;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class MigrationProgressTrackingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function start_integration_migration_creates_progress_record(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'activity',
        ]);

        // Dispatch the migration job
        $job = new StartIntegrationMigration($integration);
        $job->handle();

        // Check that a progress record was created
        $progress = ActionProgress::getLatestProgress(
            $user->id,
            'migration',
            "integration_{$integration->id}"
        );

        $this->assertNotNull($progress);
        $this->assertEquals('fetching', $progress->step);
        $this->assertEquals('Starting data fetch...', $progress->message);
        $this->assertEquals(30, $progress->progress);
        $this->assertTrue($progress->isInProgress());
    }

    /**
     * @test
     */
    public function migration_progress_updates_during_processing(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'activity',
        ]);

        // Create initial progress record
        $progress = ActionProgress::createProgress(
            $user->id,
            'migration',
            "integration_{$integration->id}",
            'starting',
            'Starting migration...',
            0
        );

        // Simulate progress update
        $progress->updateProgress(
            'configuring',
            'Configuring Oura activity migration...',
            20,
            ['service' => 'oura', 'instance_type' => 'activity']
        );

        $progress->refresh();

        $this->assertEquals('configuring', $progress->step);
        $this->assertEquals('Configuring Oura activity migration...', $progress->message);
        $this->assertEquals(20, $progress->progress);
        $this->assertEquals(['service' => 'oura', 'instance_type' => 'activity'], $progress->details);
    }

    /**
     * @test
     */
    public function migration_progress_can_be_marked_completed(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'activity',
        ]);

        // Create progress record
        $progress = ActionProgress::createProgress(
            $user->id,
            'migration',
            "integration_{$integration->id}",
            'processing',
            'Processing data...',
            50
        );

        // Mark as completed
        $progress->markCompleted([
            'items_processed' => 100,
            'duration' => '2m 30s',
        ]);

        $progress->refresh();

        $this->assertTrue($progress->isCompleted());
        $this->assertNotNull($progress->completed_at);
        $this->assertEquals(['items_processed' => 100, 'duration' => '2m 30s'], $progress->details);
    }

    /**
     * @test
     */
    public function migration_progress_can_be_marked_failed(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'activity',
        ]);

        // Create progress record
        $progress = ActionProgress::createProgress(
            $user->id,
            'migration',
            "integration_{$integration->id}",
            'processing',
            'Processing data...',
            50
        );

        // Mark as failed
        $progress->markFailed('API rate limit exceeded', [
            'error_code' => 'RATE_LIMIT',
            'retry_after' => 3600,
        ]);

        $progress->refresh();

        $this->assertTrue($progress->isFailed());
        $this->assertNotNull($progress->failed_at);
        $this->assertEquals('API rate limit exceeded', $progress->error_message);
        $this->assertEquals(['error_code' => 'RATE_LIMIT', 'retry_after' => 3600], $progress->details);
    }
}
