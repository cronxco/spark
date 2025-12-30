<?php

namespace Tests\Unit\Services\Hevy;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Services\Hevy\ProgressionAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgressionAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProgressionAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProgressionAnalysisService::class);
    }

    /**
     * @test
     */
    public function parses_notes_target_format(): void
    {
        $notes = 'Previous workout notes. 15:kg@5';
        $result = $this->service->parseNotesTarget($notes);

        $this->assertNotNull($result);
        $this->assertEquals(15, $result['target_reps']);
        $this->assertEquals('kg', $result['increment_type']);
        $this->assertEquals(5.0, $result['increment_amount']);
    }

    /**
     * @test
     */
    public function parses_notes_target_with_reps_increment(): void
    {
        $notes = 'Keep pushing! 20:reps@2';
        $result = $this->service->parseNotesTarget($notes);

        $this->assertNotNull($result);
        $this->assertEquals(20, $result['target_reps']);
        $this->assertEquals('reps', $result['increment_type']);
        $this->assertEquals(2.0, $result['increment_amount']);
    }

    /**
     * @test
     */
    public function parses_notes_target_with_decimal_increment(): void
    {
        $notes = '12:kg@2.5';
        $result = $this->service->parseNotesTarget($notes);

        $this->assertNotNull($result);
        $this->assertEquals(12, $result['target_reps']);
        $this->assertEquals('kg', $result['increment_type']);
        $this->assertEquals(2.5, $result['increment_amount']);
    }

    /**
     * @test
     */
    public function returns_null_for_invalid_notes_format(): void
    {
        $notes = 'No target information here';
        $result = $this->service->parseNotesTarget($notes);

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function formats_notes_with_increase_weight_action(): void
    {
        $recommendation = [
            'action' => 'increase_weight',
            'narrative' => '⬆️ Increased Weight by 5kg',
            'new_target' => '12:kg@5',
            'current_weight' => 60,
            'new_weight' => 65,
            'current_unit' => 'kg',
            'current_reps' => 12,
            'current_rpe' => 8.5,
        ];

        $notes = $this->service->formatNotes($recommendation);

        $this->assertStringContainsString('⬆️ Increased Weight by 5kg', $notes);
        $this->assertStringContainsString('12:kg@', $notes);
    }

    /**
     * @test
     */
    public function formats_notes_with_increase_reps_action(): void
    {
        $recommendation = [
            'action' => 'increase_reps',
            'narrative' => '⬆️ Increase Reps by 2',
            'new_target' => '12:reps@2',
            'current_reps' => 10,
            'new_reps' => 12,
            'current_unit' => 'kg',
        ];

        $notes = $this->service->formatNotes($recommendation);

        $this->assertStringContainsString('⬆️ Increase Reps by 2', $notes);
        $this->assertStringContainsString(':reps@2', $notes);
    }

    /**
     * @test
     */
    public function formats_notes_with_deload_action(): void
    {
        $recommendation = [
            'action' => 'deload',
            'narrative' => '⏪ Deloaded',
            'current_weight' => 60,
            'new_weight' => 55,
            'current_unit' => 'kg',
        ];

        $notes = $this->service->formatNotes($recommendation);

        $this->assertStringContainsString('⏪ Deloaded', $notes);
    }

    /**
     * @test
     */
    public function formats_notes_with_maintain_action(): void
    {
        $recommendation = [
            'action' => 'maintain',
            'narrative' => '▶️ Maintain',
            'current_weight' => 60,
            'current_unit' => 'kg',
        ];

        $notes = $this->service->formatNotes($recommendation);

        $this->assertStringContainsString('▶️ Maintain', $notes);
    }

    /**
     * @test
     */
    public function rounds_weight_to_increment(): void
    {
        // Test rounding to nearest 5kg
        $this->assertEquals(65.0, $this->service->roundToIncrement(63.5, 5.0));
        $this->assertEquals(65.0, $this->service->roundToIncrement(66.2, 5.0));
        $this->assertEquals(60.0, $this->service->roundToIncrement(62.4, 5.0));

        // Test rounding to nearest 2.5kg
        $this->assertEquals(62.5, $this->service->roundToIncrement(63.0, 2.5));
        $this->assertEquals(65.0, $this->service->roundToIncrement(65.8, 2.5));

        // Test rounding to nearest 2 reps
        $this->assertEquals(12.0, $this->service->roundToIncrement(11.5, 2.0));
        $this->assertEquals(14.0, $this->service->roundToIncrement(13.8, 2.0));
    }

    /**
     * @test
     */
    public function analyze_recommends_increase_weight(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'hevy',
            'configuration' => [
                'goal_reps' => 12,
                'progression_rpe_trigger' => 9.0,
                'weight_increment_kg' => 5.0,
                'analysis_window_days' => 7,
            ],
        ]);

        // Create routine and exercise template
        $routine = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'routine',
            'type' => 'hevy_routine',
            'title' => 'Push Day',
        ]);

        // Create recent workout event with exercise that achieved goal reps at acceptable RPE
        $userObject = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'user',
            'type' => 'hevy_user',
            'title' => 'Hevy User',
        ]);

        $event = Event::create([
            'source_id' => 'hevy_workout_test',
            'time' => now()->subDays(1),
            'integration_id' => $integration->id,
            'actor_id' => $userObject->id,
            'target_id' => $routine->id,
            'service' => 'hevy',
            'domain' => 'health',
            'action' => 'completed_workout',
            'event_metadata' => ['routine_title' => 'Push Day'],
        ]);

        // Create individual blocks for each set (matches real Hevy structure)
        // All sets achieve goal reps with acceptable RPE
        $event->createBlock([
            'block_type' => 'exercise',
            'title' => 'Bench Press - Set 1',
            'time' => now()->subDays(1),
            'metadata' => [
                'routine_title' => 'Push Day',
                'exercise_name' => 'Bench Press',
                'set_number' => 1,
                'weight' => 60,
                'reps' => 12,
                'rpe' => 8.5,
                'unit' => 'kg',
            ],
        ]);

        $event->createBlock([
            'block_type' => 'exercise',
            'title' => 'Bench Press - Set 2',
            'time' => now()->subDays(1),
            'metadata' => [
                'routine_title' => 'Push Day',
                'exercise_name' => 'Bench Press',
                'set_number' => 2,
                'weight' => 60,
                'reps' => 12,
                'rpe' => 8.8,
                'unit' => 'kg',
            ],
        ]);

        $event->createBlock([
            'block_type' => 'exercise',
            'title' => 'Bench Press - Set 3',
            'time' => now()->subDays(1),
            'metadata' => [
                'routine_title' => 'Push Day',
                'exercise_name' => 'Bench Press',
                'set_number' => 3,
                'weight' => 60,
                'reps' => 12,
                'rpe' => 9.0,
                'unit' => 'kg',
            ],
        ]);

        $config = $integration->configuration;
        $result = $this->service->analyze($integration, $config);

        $this->assertNotEmpty($result['recommendations']);
        $recommendation = $result['recommendations'][0];
        $this->assertEquals('increase_weight', $recommendation['action']);
        $this->assertEquals(65, $recommendation['new_weight']); // 60 + 5 (rounded to 5kg increment)
        $this->assertEquals('kg', $recommendation['current_unit']);
    }

    /**
     * @test
     */
    public function analyze_recommends_deload(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'hevy',
            'configuration' => [
                'goal_reps' => 12,
                'progression_rpe_trigger' => 9.0,
                'weight_increment_kg' => 5.0,
                'deload_percentage' => 90.0,
                'analysis_window_days' => 7,
            ],
        ]);

        // Create routine
        $routine = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'routine',
            'type' => 'hevy_routine',
            'title' => 'Push Day',
        ]);

        $userObject = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'user',
            'type' => 'hevy_user',
            'title' => 'Hevy User',
        ]);

        $event = Event::create([
            'source_id' => 'hevy_workout_test_deload',
            'time' => now()->subDays(1),
            'integration_id' => $integration->id,
            'actor_id' => $userObject->id,
            'target_id' => $routine->id,
            'service' => 'hevy',
            'domain' => 'health',
            'action' => 'completed_workout',
            'event_metadata' => ['routine_title' => 'Push Day'],
        ]);

        // Create exercise with low reps and very high RPE (indicates failure/struggle)
        $event->createBlock([
            'block_type' => 'exercise',
            'title' => 'Bench Press - Set 1',
            'time' => now()->subDays(1),
            'metadata' => [
                'routine_title' => 'Push Day',
                'exercise_name' => 'Bench Press',
                'set_number' => 1,
                'weight' => 70,
                'reps' => 7,
                'rpe' => 9.8,
                'unit' => 'kg',
            ],
        ]);

        $event->createBlock([
            'block_type' => 'exercise',
            'title' => 'Bench Press - Set 2',
            'time' => now()->subDays(1),
            'metadata' => [
                'routine_title' => 'Push Day',
                'exercise_name' => 'Bench Press',
                'set_number' => 2,
                'weight' => 70,
                'reps' => 6,
                'rpe' => 10.0,
                'unit' => 'kg',
            ],
        ]);

        $config = $integration->configuration;
        $result = $this->service->analyze($integration, $config);

        $this->assertNotEmpty($result['recommendations']);
        $recommendation = $result['recommendations'][0];
        $this->assertEquals('deload', $recommendation['action']);
        $this->assertEquals(65, $recommendation['new_weight']); // 70 * 0.9 = 63, rounded to 65 (nearest 5kg)
    }

    /**
     * @test
     */
    public function analyze_recommends_maintain(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'hevy',
            'configuration' => [
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

        $event = Event::create([
            'source_id' => 'hevy_workout_test_maintain',
            'time' => now()->subDays(1),
            'integration_id' => $integration->id,
            'actor_id' => $userObject->id,
            'target_id' => $routine->id,
            'service' => 'hevy',
            'domain' => 'health',
            'action' => 'completed_workout',
            'event_metadata' => ['routine_title' => 'Push Day'],
        ]);

        // Exercise at goal reps but high RPE (not ready to increase)
        $event->createBlock([
            'block_type' => 'exercise',
            'title' => 'Bench Press - Set 1',
            'time' => now()->subDays(1),
            'metadata' => [
                'routine_title' => 'Push Day',
                'exercise_name' => 'Bench Press',
                'set_number' => 1,
                'weight' => 60,
                'reps' => 10,
                'rpe' => 9.2,
                'unit' => 'kg',
            ],
        ]);

        $config = $integration->configuration;
        $result = $this->service->analyze($integration, $config);

        $this->assertNotEmpty($result['recommendations']);
        $recommendation = $result['recommendations'][0];
        $this->assertEquals('maintain', $recommendation['action']);
    }

    /**
     * @test
     */
    public function analyze_finds_heaviest_set_not_last_set(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'hevy',
            'configuration' => [
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

        $event = Event::create([
            'source_id' => 'hevy_workout_test_heaviest',
            'time' => now()->subDays(1),
            'integration_id' => $integration->id,
            'actor_id' => $userObject->id,
            'target_id' => $routine->id,
            'service' => 'hevy',
            'domain' => 'health',
            'action' => 'completed_workout',
            'event_metadata' => ['routine_title' => 'Push Day'],
        ]);

        // Pyramid set where heaviest set is in the middle
        $event->createBlock([
            'block_type' => 'exercise',
            'title' => 'Bench Press - Set 1',
            'time' => now()->subDays(1),
            'metadata' => [
                'routine_title' => 'Push Day',
                'exercise_name' => 'Bench Press',
                'set_number' => 1,
                'weight' => 60,
                'reps' => 10,
                'rpe' => 7.0,
                'unit' => 'kg',
            ],
        ]);

        $event->createBlock([
            'block_type' => 'exercise',
            'title' => 'Bench Press - Set 2',
            'time' => now()->subDays(1),
            'metadata' => [
                'routine_title' => 'Push Day',
                'exercise_name' => 'Bench Press',
                'set_number' => 2,
                'weight' => 70,
                'reps' => 12,
                'rpe' => 8.5,
                'unit' => 'kg',
            ],
        ]);

        $event->createBlock([
            'block_type' => 'exercise',
            'title' => 'Bench Press - Set 3',
            'time' => now()->subDays(1),
            'metadata' => [
                'routine_title' => 'Push Day',
                'exercise_name' => 'Bench Press',
                'set_number' => 3,
                'weight' => 55,
                'reps' => 15,
                'rpe' => 7.5,
                'unit' => 'kg',
            ],
        ]);

        $config = $integration->configuration;
        $result = $this->service->analyze($integration, $config);

        $this->assertNotEmpty($result['recommendations']);
        $recommendation = $result['recommendations'][0];
        // Should base recommendation on 70kg set (heaviest), not 55kg (last)
        $this->assertEquals(70, $recommendation['current_weight']);
        $this->assertEquals('increase_weight', $recommendation['action']);
        $this->assertEquals(75, $recommendation['new_weight']); // 70 + 5
    }

    /**
     * @test
     */
    public function analyze_handles_missing_rpe(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'hevy',
            'configuration' => [
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

        $event = Event::create([
            'source_id' => 'hevy_workout_test_no_rpe',
            'time' => now()->subDays(1),
            'integration_id' => $integration->id,
            'actor_id' => $userObject->id,
            'target_id' => $routine->id,
            'service' => 'hevy',
            'domain' => 'health',
            'action' => 'completed_workout',
            'event_metadata' => ['routine_title' => 'Push Day'],
        ]);

        // Exercise without RPE data
        $event->createBlock([
            'block_type' => 'exercise',
            'title' => 'Bench Press - Set 1',
            'time' => now()->subDays(1),
            'metadata' => [
                'routine_title' => 'Push Day',
                'exercise_name' => 'Bench Press',
                'set_number' => 1,
                'weight' => 60,
                'reps' => 10,
                'unit' => 'kg',
            ],
        ]);

        $config = $integration->configuration;
        $result = $this->service->analyze($integration, $config);

        // Should still provide a recommendation, but with maintain action
        // (since we can't make progression decisions without RPE)
        $this->assertNotEmpty($result['recommendations']);
        $recommendation = $result['recommendations'][0];
        $this->assertEquals('maintain', $recommendation['action']);
        $this->assertArrayHasKey('reason', $recommendation);
    }
}
