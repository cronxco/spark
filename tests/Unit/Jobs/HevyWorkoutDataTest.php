<?php

namespace Tests\Unit\Jobs;

use App\Integrations\Hevy\HevyPlugin;
use App\Jobs\Data\Hevy\HevyWorkoutData;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

class HevyWorkoutDataTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->integration = Integration::factory()->create([
            'service' => 'hevy',
            'configuration' => [
                'units' => 'kg',
                'include_exercise_summary_blocks' => ['enabled'],
            ],
        ]);
    }

    /**
     * @test
     */
    public function job_creation()
    {
        $job = $this->createTestableJob([]);

        $reflection = new ReflectionClass($job);
        
        $serviceNameMethod = $reflection->getMethod('getServiceName');
        $serviceNameMethod->setAccessible(true);
        $this->assertEquals('hevy', $serviceNameMethod->invoke($job));
        
        $jobTypeMethod = $reflection->getMethod('getJobType');
        $jobTypeMethod->setAccessible(true);
        $this->assertEquals('workout', $jobTypeMethod->invoke($job));
    }

    /**
     * @test
     */
    public function process_workout_data()
    {
        $rawData = [
            'data' => [
                [
                    'id' => 'workout_123',
                    'title' => 'Morning Workout',
                    'start_time' => '2024-01-15T08:00:00Z',
                    'total_volume' => 1500.5,
                    'duration_seconds' => 3600,
                    'exercises' => [
                        [
                            'name' => 'Bench Press',
                            'sets' => [
                                [
                                    'reps' => 10,
                                    'weight' => 80.5,
                                    'rpe' => 7.5,
                                    'rest_seconds' => 120,
                                ],
                                [
                                    'reps' => 8,
                                    'weight' => 85.0,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Mock the user profile API call
        Http::fake([
            'api.hevyapp.com/v1/me' => Http::response(['name' => 'Test User'], 200),
        ]);

        $job = new HevyWorkoutData($this->integration, $rawData);
        $job->handle();

        // Assert workout event was created
        $this->assertDatabaseHas('events', [
            'integration_id' => $this->integration->id,
            'action' => 'completed_workout',
            'service' => 'hevy',
        ]);

        // Assert user object was created
        $this->assertDatabaseHas('objects', [
            'user_id' => $this->integration->user_id,
            'type' => 'hevy_user',
        ]);

        // Assert workout object was created
        $this->assertDatabaseHas('objects', [
            'user_id' => $this->integration->user_id,
            'type' => 'hevy_workout',
            'title' => 'Morning Workout',
        ]);
    }

    /**
     * @test
     */
    public function duplicate_workout_handling()
    {
        // Create actor and target objects first
        $actor = EventObject::factory()->create([
            'user_id' => $this->integration->user_id,
            'concept' => 'user',
            'type' => 'hevy_user',
            'title' => 'Test User',
        ]);

        $target = EventObject::factory()->create([
            'user_id' => $this->integration->user_id,
            'concept' => 'workout',
            'type' => 'hevy_workout',
            'title' => 'Morning Workout',
        ]);

        // Create a workout event first
        Event::create([
            'source_id' => "hevy_workout_{$this->integration->id}_workout_123",
            'time' => '2024-01-15T08:00:00Z',
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
            'service' => 'hevy',
            'domain' => 'health',
            'action' => 'completed_workout',
            'value' => 1500500,
            'value_multiplier' => 1000,
            'value_unit' => 'kg',
        ]);

        $rawData = [
            'data' => [
                [
                    'id' => 'workout_123',
                    'title' => 'Morning Workout',
                    'start_time' => '2024-01-15T08:00:00Z',
                    'total_volume' => 1500.5,
                ],
            ],
        ];

        $job = new HevyWorkoutData($this->integration, $rawData);
        $job->handle();

        // Assert only one event exists (no duplicate)
        $this->assertEquals(1, Event::where('source_id', "hevy_workout_{$this->integration->id}_workout_123")->count());
    }

    /**
     * @test
     */
    public function exercise_blocks_creation()
    {
        $rawData = [
            'data' => [
                [
                    'id' => 'workout_123',
                    'title' => 'Bench Day',
                    'start_time' => '2024-01-15T08:00:00Z',
                    'exercises' => [
                        [
                            'name' => 'Bench Press',
                            'sets' => [
                                [
                                    'reps' => 10,
                                    'weight' => 80.5,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            'api.hevyapp.com/v1/me' => Http::response([], 200),
        ]);

        $job = new HevyWorkoutData($this->integration, $rawData);
        $job->handle();

        $event = Event::where('integration_id', $this->integration->id)->first();

        // Assert exercise block was created
        $this->assertDatabaseHas('blocks', [
            'event_id' => $event->id,
            'block_type' => 'exercise',
            'title' => 'Bench Press - Set 1',
        ]);
    }

    /**
     * @test
     */
    public function weight_unit_inference()
    {
        // Test with preferred kg units
        $this->integration->update(['configuration' => ['units' => 'kg']]);

        $plugin = new HevyPlugin;
        $reflection = new ReflectionClass($plugin);
        $method = $reflection->getMethod('inferWeightUnit');
        $method->setAccessible(true);

        $result = $method->invoke($plugin, $this->integration, 'lb'); // Workout specifies lb
        $this->assertEquals('lb', $result); // Should use workout unit

        $result = $method->invoke($plugin, $this->integration, null); // No workout unit
        $this->assertEquals('kg', $result); // Should use preferred unit
    }

    /**
     * @test
     */
    public function encode_numeric_value()
    {
        $plugin = new HevyPlugin;
        $reflection = new ReflectionClass($plugin);
        $method = $reflection->getMethod('encodeNumericValue');
        $method->setAccessible(true);

        // Test integer value
        [$value, $multiplier] = $method->invoke($plugin, 100);
        $this->assertEquals(100, $value);
        $this->assertEquals(1, $multiplier);

        // Test float value
        [$value, $multiplier] = $method->invoke($plugin, 100.5);
        $this->assertEquals(100500, $value);
        $this->assertEquals(1000, $multiplier);

        // Test null value
        [$value, $multiplier] = $method->invoke($plugin, null);
        $this->assertNull($value);
        $this->assertNull($multiplier);
    }

    private function createTestableJob(array $rawData): HevyWorkoutData
    {
        return new class($this->integration, $rawData) extends HevyWorkoutData
        {
            public function publicGetServiceName()
            {
                return $this->getServiceName();
            }

            public function publicGetJobType()
            {
                return $this->getJobType();
            }
        };
    }
}
