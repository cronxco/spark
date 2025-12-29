<?php

namespace Tests\Feature\Hevy;

use App\Jobs\Effects\Hevy\HevyAnalyzeProgressionEffect;
use App\Jobs\Effects\Hevy\HevyAutoCoachEffect;
use App\Jobs\Effects\Hevy\HevyUpdateRoutineEffect;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Services\TaskPipeline\TaskRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HevyCoachEffectsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /**
     * @test
     */
    public function analyze_effect_creates_recommendation_blocks(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'hevy',
            'configuration' => [
                'coach_enabled' => true,
                'goal_reps' => 12,
                'progression_rpe_trigger' => 9.0,
                'weight_increment_kg' => 5.0,
                'analysis_window_days' => 7,
            ],
        ]);

        $userObject = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'user',
            'type' => 'hevy_user',
            'title' => 'Hevy User',
        ]);

        $routine = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'routine',
            'type' => 'hevy_routine',
            'title' => 'Push Day',
        ]);

        // Create workout event with exercise data
        $event = Event::create([
            'source_id' => 'hevy_workout_analyze_test',
            'time' => now()->subDays(1),
            'integration_id' => $integration->id,
            'actor_id' => $userObject->id,
            'target_id' => $routine->id,
            'service' => 'hevy',
            'domain' => 'health',
            'action' => 'completed_workout',
            'event_metadata' => ['routine_title' => 'Push Day'],
        ]);

        $event->createBlock([
            'block_type' => 'exercise',
            'title' => 'Bench Press',
            'time' => now()->subDays(1),
            'metadata' => [
                'routine_title' => 'Push Day',
                'exercise_name' => 'Bench Press',
                'sets' => [
                    ['weight_kg' => 60, 'reps' => 12, 'rpe' => 8.5],
                ],
            ],
        ]);

        // Run the analyze effect
        $job = new HevyAnalyzeProgressionEffect($integration, []);
        $result = $job->handle();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Analyzed', $result['message']);

        // Check that recommendation blocks were created
        $recommendationBlocks = Block::where('block_type', 'coach_recommendation')->get();
        $this->assertGreaterThan(0, $recommendationBlocks->count());

        // Check that event was created
        $recommendationEvents = Event::where('action', 'had_coach_recommendation')
            ->where('integration_id', $integration->id)
            ->get();
        $this->assertGreaterThan(0, $recommendationEvents->count());
    }

    /**
     * @test
     */
    public function update_effect_calls_hevy_api(): void
    {
        Http::fake([
            '*/v1/routines?*' => Http::response([
                'routines' => [
                    [
                        'id' => 'routine_123',
                        'title' => 'Push Day',
                        'exercises' => [
                            [
                                'title' => 'Bench Press',
                                'notes' => '12:kg@5',
                                'sets' => [
                                    ['weight_kg' => 60, 'reps' => 12],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
            '*/v1/routines/*' => Http::response(['success' => true]),
        ]);

        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'hevy',
            'configuration' => [
                'coach_enabled' => true,
                'api_key' => 'test_key',
            ],
        ]);

        $userObject = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'user',
            'type' => 'hevy_user',
            'title' => 'Hevy User',
        ]);

        $routine = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'routine',
            'type' => 'hevy_routine',
            'title' => 'Push Day',
        ]);

        // Create a recent recommendation block
        $event = Event::create([
            'source_id' => 'hevy_coach_test',
            'time' => now()->subHour(),
            'integration_id' => $integration->id,
            'actor_id' => $userObject->id,
            'target_id' => $routine->id,
            'service' => 'hevy',
            'domain' => 'health',
            'action' => 'had_coach_recommendation',
        ]);

        $event->createBlock([
            'block_type' => 'coach_recommendation',
            'title' => 'Push Day - Bench Press - Increase_weight',
            'time' => now()->subHour(),
            'content' => 'Ready to increase weight',
            'metadata' => [
                'routine' => 'Push Day',
                'exercise' => 'Bench Press',
                'action' => 'increase_weight',
                'current_weight' => 60,
                'new_weight' => 65,
                'current_unit' => 'kg',
                'narrative' => '⬆️ Increased Weight by 5kg',
            ],
        ]);

        // Run the update effect
        $job = new HevyUpdateRoutineEffect($integration, []);
        $result = $job->handle();

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('updated_count', $result['data']);

        // Verify API was called
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/routines');
        });
    }

    /**
     * @test
     */
    public function update_effect_returns_error_when_no_recommendations(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'hevy',
            'configuration' => ['coach_enabled' => true],
        ]);

        // No recommendations exist
        $job = new HevyUpdateRoutineEffect($integration, []);
        $result = $job->handle();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No recommendations found', $result['message']);
    }

    /**
     * @test
     */
    public function auto_coach_runs_both_effects(): void
    {
        Http::fake([
            '*/v1/routines?*' => Http::response(['routines' => []]),
        ]);

        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'hevy',
            'configuration' => [
                'coach_enabled' => true,
                'goal_reps' => 12,
                'progression_rpe_trigger' => 9.0,
                'weight_increment_kg' => 5.0,
                'api_key' => 'test_key',
            ],
        ]);

        $userObject = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'user',
            'type' => 'hevy_user',
            'title' => 'Hevy User',
        ]);

        $routine = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'routine',
            'type' => 'hevy_routine',
            'title' => 'Push Day',
        ]);

        // Create workout data
        $event = Event::create([
            'source_id' => 'hevy_workout_auto_coach',
            'time' => now()->subDay(),
            'integration_id' => $integration->id,
            'actor_id' => $userObject->id,
            'target_id' => $routine->id,
            'service' => 'hevy',
            'domain' => 'health',
            'action' => 'completed_workout',
            'event_metadata' => ['routine_title' => 'Push Day'],
        ]);

        $event->createBlock([
            'block_type' => 'exercise',
            'title' => 'Bench Press',
            'time' => now()->subDay(),
            'metadata' => [
                'routine_title' => 'Push Day',
                'exercise_name' => 'Bench Press',
                'sets' => [
                    ['weight_kg' => 60, 'reps' => 12, 'rpe' => 8.5],
                ],
            ],
        ]);

        // Run auto-coach
        $job = new HevyAutoCoachEffect($integration, []);
        $result = $job->handle();

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('analysis', $result['data']);
        $this->assertArrayHasKey('update', $result['data']);
    }

    /**
     * @test
     */
    public function task_is_registered_in_pipeline(): void
    {
        $task = TaskRegistry::getTask('hevy_auto_coach');

        $this->assertNotNull($task);
        $this->assertEquals('Hevy Auto Coach', $task->name);
        $this->assertEquals(HevyAutoCoachEffect::class, $task->jobClass);
        $this->assertEquals(['event'], $task->appliesTo);
        $this->assertEquals('hevy', $task->conditions['service']);
        $this->assertEquals('completed_workout', $task->conditions['action']);
    }

    /**
     * @test
     */
    public function task_runs_when_coach_enabled(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'hevy',
            'configuration' => ['coach_enabled' => true],
        ]);

        $userObject = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'user',
            'type' => 'hevy_user',
            'title' => 'Hevy User',
        ]);

        $routine = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'routine',
            'type' => 'hevy_routine',
            'title' => 'Push Day',
        ]);

        $event = Event::create([
            'source_id' => 'hevy_workout_task_test',
            'time' => now(),
            'integration_id' => $integration->id,
            'actor_id' => $userObject->id,
            'target_id' => $routine->id,
            'service' => 'hevy',
            'domain' => 'health',
            'action' => 'completed_workout',
        ]);

        $task = TaskRegistry::getTask('hevy_auto_coach');
        $this->assertTrue($task->isApplicableTo($event));
    }

    /**
     * @test
     */
    public function task_does_not_run_when_coach_disabled(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'hevy',
            'configuration' => ['coach_enabled' => false],
        ]);

        $userObject = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'user',
            'type' => 'hevy_user',
            'title' => 'Hevy User',
        ]);

        $routine = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'routine',
            'type' => 'hevy_routine',
            'title' => 'Push Day',
        ]);

        $event = Event::create([
            'source_id' => 'hevy_workout_no_coach',
            'time' => now(),
            'integration_id' => $integration->id,
            'actor_id' => $userObject->id,
            'target_id' => $routine->id,
            'service' => 'hevy',
            'domain' => 'health',
            'action' => 'completed_workout',
        ]);

        $task = TaskRegistry::getTask('hevy_auto_coach');
        $this->assertFalse($task->isApplicableTo($event));
    }

    /**
     * @test
     */
    public function task_does_not_run_for_non_hevy_events(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'spotify',
            'configuration' => [],
        ]);

        $userObject = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'user',
            'type' => 'spotify_user',
            'title' => 'Spotify User',
        ]);

        $track = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'track',
            'type' => 'spotify_track',
            'title' => 'Test Track',
        ]);

        $event = Event::create([
            'source_id' => 'spotify_listen_test',
            'time' => now(),
            'integration_id' => $integration->id,
            'actor_id' => $userObject->id,
            'target_id' => $track->id,
            'service' => 'spotify',
            'domain' => 'media',
            'action' => 'listened_to',
        ]);

        $task = TaskRegistry::getTask('hevy_auto_coach');
        $this->assertFalse($task->isApplicableTo($event));
    }
}
