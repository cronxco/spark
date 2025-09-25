<?php

namespace Tests\Feature;

use App\Models\ActionProgress;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrationProgressUITest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function updates_page_shows_migration_progress(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'activity',
        ]);

        // Create a migration progress record
        $progress = ActionProgress::createProgress(
            $user->id,
            'migration',
            "integration_{$integration->id}",
            'processing',
            'Processing Oura activity data...',
            65
        );

        $this->actingAs($user);

        $response = $this->get('/updates');

        $response->assertStatus(200);
        $response->assertSee('Processing Oura activity data...');
        $response->assertSee('65%');
    }

    /**
     * @test
     */
    public function updates_page_shows_failed_migration(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
            'instance_type' => 'transactions',
        ]);

        // Create a failed migration progress record
        $progress = ActionProgress::createProgress(
            $user->id,
            'migration',
            "integration_{$integration->id}",
            'failed',
            'Migration failed',
            0
        );
        $progress->markFailed('API rate limit exceeded');

        $this->actingAs($user);

        $response = $this->get('/updates');

        $response->assertStatus(200);
        $response->assertSee('Migration Failed');
    }

    /**
     * @test
     */
    public function updates_page_shows_completed_migration(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'spotify',
            'instance_type' => 'listening',
        ]);

        // Create a completed migration progress record
        $progress = ActionProgress::createProgress(
            $user->id,
            'migration',
            "integration_{$integration->id}",
            'completed',
            'Migration completed successfully',
            100
        );
        $progress->markCompleted();

        $this->actingAs($user);

        $response = $this->get('/updates');

        $response->assertStatus(200);
        $response->assertSee('Migrated');
    }

    /**
     * @test
     */
    public function updates_page_shows_different_progress_steps(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'github',
            'instance_type' => 'activity',
        ]);

        // Test different progress steps
        $steps = [
            ['step' => 'starting', 'message' => 'Starting integration migration...', 'progress' => 0],
            ['step' => 'configuring', 'message' => 'Configuring GitHub migration...', 'progress' => 20],
            ['step' => 'fetching', 'message' => 'Starting GitHub data fetch...', 'progress' => 30],
            ['step' => 'processing', 'message' => 'Processing GitHub activity data...', 'progress' => 50],
        ];

        foreach ($steps as $stepData) {
            $progress = ActionProgress::createProgress(
                $user->id,
                'migration',
                "integration_{$integration->id}",
                $stepData['step'],
                $stepData['message'],
                $stepData['progress']
            );

            $this->actingAs($user);

            $response = $this->get('/updates');

            $response->assertStatus(200);
            $response->assertSee($stepData['message']);
            $response->assertSee($stepData['progress'] . '%');

            // Clean up for next iteration
            $progress->delete();
        }
    }
}
