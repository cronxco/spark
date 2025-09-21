<?php

namespace Tests\Feature;

use App\Integrations\Hevy\HevyPlugin;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

class HevySweepTest extends TestCase
{
    use RefreshDatabase;

    private HevyPlugin $plugin;
    private User $user;
    private IntegrationGroup $group;
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plugin = new HevyPlugin;
        $this->user = User::factory()->create();
        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'hevy',
            'access_token' => 'test-access-token',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'hevy',
            'instance_type' => 'workouts',
        ]);
    }

    /**
     * @test
     */
    public function perform_sweep_if_needed_runs_when_no_previous_sweep(): void
    {
        // Mock API responses
        Http::fake([
            'api.hevyapp.com/v1/workouts*' => Http::response([
                'data' => [
                    [
                        'id' => 'workout-1',
                        'start_time' => '2024-01-01T10:00:00Z',
                        'end_time' => '2024-01-01T11:00:00Z',
                        'title' => 'Test Workout 1',
                        'exercises' => [
                            [
                                'name' => 'Bench Press',
                                'sets' => [
                                    ['weight' => 100, 'reps' => 10],
                                    ['weight' => 105, 'reps' => 8],
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => 'workout-2',
                        'start_time' => '2024-01-02T10:00:00Z',
                        'end_time' => '2024-01-02T11:00:00Z',
                        'title' => 'Test Workout 2',
                        'exercises' => [
                            [
                                'name' => 'Squat',
                                'sets' => [
                                    ['weight' => 150, 'reps' => 12],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        // Integration has no previous sweep timestamp
        $this->integration->update(['configuration' => []]);

        // Call fetchData which should trigger sweep
        $this->plugin->fetchData($this->integration);

        // Verify sweep timestamp was set
        $this->integration->refresh();
        $this->assertNotNull($this->integration->configuration['hevy_last_sweep_at']);

        // Verify events were created for workouts
        $this->assertEquals(2, Event::where('integration_id', $this->integration->id)->count());

        // Verify API calls were made for sweep
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'workouts') &&
                   str_contains($request->url(), 'start_date=' . now()->subDays(30)->toDateString());
        });
    }

    /**
     * @test
     */
    public function perform_sweep_if_needed_skips_when_recent_sweep(): void
    {
        // Set recent sweep timestamp (2 days ago)
        $recentSweepTime = now()->subDays(2)->toIso8601String();
        $this->integration->update([
            'configuration' => ['hevy_last_sweep_at' => $recentSweepTime],
        ]);

        // Mock API responses
        Http::fake([
            'api.hevyapp.com/v1/workouts*' => Http::response(['data' => []]),
        ]);

        // Call fetchData
        $this->plugin->fetchData($this->integration);

        // Verify sweep timestamp was not updated
        $this->integration->refresh();
        $this->assertEquals($recentSweepTime, $this->integration->configuration['hevy_last_sweep_at']);

        // Verify no sweep API calls were made
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'start_date=' . now()->subDays(30)->toDateString());
        });
    }

    /**
     * @test
     */
    public function perform_sweep_if_needed_runs_when_old_sweep(): void
    {
        // Set old sweep timestamp (7 days ago)
        $oldSweepTime = now()->subDays(7)->toIso8601String();
        $this->integration->update([
            'configuration' => ['hevy_last_sweep_at' => $oldSweepTime],
        ]);

        // Mock API responses
        Http::fake([
            'api.hevyapp.com/v1/workouts*' => Http::response(['data' => []]),
        ]);

        // Call fetchData
        $this->plugin->fetchData($this->integration);

        // Verify sweep timestamp was updated
        $this->integration->refresh();
        $this->assertNotEquals($oldSweepTime, $this->integration->configuration['hevy_last_sweep_at']);

        // Verify sweep API calls were made
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'start_date=' . now()->subDays(30)->toDateString());
        });
    }

    /**
     * @test
     */
    public function perform_data_sweep_processes_workout_data(): void
    {
        // Mock API responses with workout data
        Http::fake([
            'api.hevyapp.com/v1/workouts*' => Http::response([
                'data' => [
                    [
                        'id' => 'workout-1',
                        'start_time' => '2024-01-01T10:00:00Z',
                        'end_time' => '2024-01-01T11:00:00Z',
                        'title' => 'Test Workout',
                        'exercises' => [
                            [
                                'name' => 'Bench Press',
                                'sets' => [
                                    ['weight' => 100, 'reps' => 10],
                                    ['weight' => 105, 'reps' => 8],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        // Integration has no previous sweep timestamp
        $this->integration->update(['configuration' => []]);

        // Call fetchData to trigger sweep
        $this->plugin->fetchData($this->integration);

        // Verify events were created
        $events = Event::where('integration_id', $this->integration->id)->get();
        $this->assertEquals(1, $events->count());

        // Verify workout event was created
        $event = $events->first();
        $this->assertEquals('completed_workout', $event->action);
    }

    /**
     * @test
     */
    public function perform_data_sweep_handles_different_response_formats(): void
    {
        // Test different response formats that Hevy might return
        $responseFormats = [
            ['data' => [['id' => 'workout-1', 'title' => 'Test Workout Format 1', 'start_time' => '2024-01-01T10:00:00Z']]],
            ['workouts' => [['id' => 'workout-2', 'title' => 'Test Workout Format 2', 'start_time' => '2024-01-02T10:00:00Z']]],
            [['id' => 'workout-3', 'title' => 'Test Workout Format 3', 'start_time' => '2024-01-03T10:00:00Z']], // Direct array
        ];

        foreach ($responseFormats as $index => $responseFormat) {
            // Mock API response
            Http::fake([
                'api.hevyapp.com/v1/workouts*' => Http::response($responseFormat),
            ]);

            // Create new integration for each test
            $integration = Integration::factory()->create([
                'user_id' => $this->user->id,
                'integration_group_id' => $this->group->id,
                'service' => 'hevy',
                'instance_type' => 'workouts',
                'configuration' => [], // No previous sweep
            ]);

            // Call fetchData
            $this->plugin->fetchData($integration);

            // Verify events were created
            $events = Event::where('integration_id', $integration->id)->get();
            $this->assertEquals(1, $events->count(), "Response format {$index} should create 1 event");
        }
    }

    /**
     * @test
     */
    public function perform_data_sweep_handles_api_errors_gracefully(): void
    {
        // Mock API error response
        Http::fake([
            'api.hevyapp.com/v1/workouts*' => Http::response([], 500),
        ]);

        // Integration has no previous sweep timestamp
        $this->integration->update(['configuration' => []]);

        // Expect exception to be thrown
        $this->expectException(Throwable::class);

        // Call fetchData to trigger sweep
        $this->plugin->fetchData($this->integration);
    }

    /**
     * @test
     */
    public function sweep_works_for_different_instance_types(): void
    {
        // Test with different instance types (Hevy only has workouts, but test the pattern)
        $instanceTypes = ['workouts'];

        foreach ($instanceTypes as $instanceType) {
            $integration = Integration::factory()->create([
                'user_id' => $this->user->id,
                'integration_group_id' => $this->group->id,
                'service' => 'hevy',
                'instance_type' => $instanceType,
                'configuration' => [], // No previous sweep
            ]);

            // Mock API responses
            Http::fake([
                'api.hevyapp.com/v1/workouts*' => Http::response(['data' => []]),
            ]);

            // Call fetchData
            $this->plugin->fetchData($integration);

            // Verify sweep timestamp was set regardless of instance type
            $integration->refresh();
            $this->assertNotNull($integration->configuration['hevy_last_sweep_at'],
                "Sweep should work for instance type: {$instanceType}");
        }
    }

    /**
     * @test
     */
    public function sweep_timestamp_format_is_correct(): void
    {
        // Mock API responses
        Http::fake([
            'api.hevyapp.com/v1/workouts*' => Http::response(['data' => []]),
        ]);

        // Integration has no previous sweep timestamp
        $this->integration->update(['configuration' => []]);

        // Call fetchData
        $this->plugin->fetchData($this->integration);

        // Verify timestamp format
        $this->integration->refresh();
        $sweepTimestamp = $this->integration->configuration['hevy_last_sweep_at'];

        $this->assertIsString($sweepTimestamp);
        $this->assertTrue(Carbon::parse($sweepTimestamp)->isValid());
        $this->assertTrue(Carbon::parse($sweepTimestamp)->isAfter(now()->subMinutes(1)));
    }

    /**
     * @test
     */
    public function sweep_uses_correct_date_range(): void
    {
        // Mock API responses
        Http::fake([
            'api.hevyapp.com/v1/workouts*' => Http::response(['data' => []]),
        ]);

        // Integration has no previous sweep timestamp
        $this->integration->update(['configuration' => []]);

        // Call fetchData
        $this->plugin->fetchData($this->integration);

        // Verify correct date range was used (30 days)
        Http::assertSent(function ($request) {
            $expectedStartDate = now()->subDays(30)->toDateString();

            return str_contains($request->url(), "start_date={$expectedStartDate}");
        });
    }

    /**
     * @test
     */
    public function sweep_handles_non_array_workout_items(): void
    {
        // Mock API response with non-array workout item
        Http::fake([
            'api.hevyapp.com/v1/workouts*' => Http::response([
                'data' => [
                    'invalid-workout-item', // This should be skipped
                    ['id' => 'workout-1', 'title' => 'Valid Workout'], // This should be processed
                ],
            ]),
        ]);

        // Integration has no previous sweep timestamp
        $this->integration->update(['configuration' => []]);

        // Call fetchData
        $this->plugin->fetchData($this->integration);

        // Verify only valid workout was processed
        $events = Event::where('integration_id', $this->integration->id)->get();
        $this->assertEquals(1, $events->count());
        $this->assertEquals('completed_workout', $events->first()->action);
    }

    /**
     * @test
     */
    public function sweep_includes_limit_parameter(): void
    {
        // Mock API responses
        Http::fake([
            'api.hevyapp.com/v1/workouts*' => Http::response(['data' => []]),
        ]);

        // Integration has no previous sweep timestamp
        $this->integration->update(['configuration' => []]);

        // Call fetchData
        $this->plugin->fetchData($this->integration);

        // Verify limit parameter was included
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'limit=100');
        });
    }
}
