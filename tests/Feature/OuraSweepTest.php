<?php

namespace Tests\Feature;

use App\Integrations\Oura\OuraPlugin;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OuraSweepTest extends TestCase
{
    use RefreshDatabase;

    private OuraPlugin $plugin;

    private User $user;

    private IntegrationGroup $group;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plugin = new OuraPlugin;
        $this->user = User::factory()->create();
        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'access_token' => 'test-access-token',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'oura',
            'instance_type' => 'activity',
        ]);
    }

    /**
     * @test
     */
    public function perform_sweep_if_needed_runs_when_no_previous_sweep(): void
    {
        // Mock API responses
        Http::fake([
            'api.ouraring.com/v2/usercollection/workout*' => Http::response([
                'data' => [
                    ['day' => '2024-01-01', 'score' => 85],
                    ['day' => '2024-01-02', 'score' => 90],
                ],
            ]),
            'api.ouraring.com/v2/usercollection/daily_activity*' => Http::response([
                'data' => [
                    ['day' => '2024-01-01', 'score' => 80],
                    ['day' => '2024-01-02', 'score' => 85],
                ],
            ]),
            'api.ouraring.com/v2/usercollection/daily_sleep*' => Http::response([
                'data' => [
                    ['day' => '2024-01-01', 'score' => 75],
                    ['day' => '2024-01-02', 'score' => 80],
                ],
            ]),
            'api.ouraring.com/v2/usercollection/daily_readiness*' => Http::response([
                'data' => [
                    ['day' => '2024-01-01', 'score' => 70],
                    ['day' => '2024-01-02', 'score' => 75],
                ],
            ]),
        ]);

        // Integration has no previous sweep timestamp
        $this->integration->update(['configuration' => []]);

        // Call fetchData which should trigger sweep
        $this->plugin->fetchData($this->integration);

        // Verify sweep timestamp was set
        $this->integration->refresh();
        $this->assertNotNull($this->integration->configuration['oura_last_sweep_at']);

        // Verify events were created for all data types
        $this->assertEquals(8, Event::where('integration_id', $this->integration->id)->count());

        // Verify API calls were made for sweep
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'usercollection/workout') &&
                   str_contains($request->url(), 'start_date=' . now()->subDays(30)->toDateString());
        });
    }

    /**
     * @test
     */
    public function perform_sweep_if_needed_skips_when_recent_sweep(): void
    {
        // Set recent sweep timestamp
        $recentSweepTime = now()->subHours(10)->toIso8601String();
        $this->integration->update([
            'configuration' => ['oura_last_sweep_at' => $recentSweepTime],
        ]);

        // Mock API responses
        Http::fake([
            'api.ouraring.com/v2/usercollection/daily_activity*' => Http::response(['data' => []]),
        ]);

        // Call fetchData
        $this->plugin->fetchData($this->integration);

        // Verify sweep timestamp was not updated
        $this->integration->refresh();
        $this->assertEquals($recentSweepTime, $this->integration->configuration['oura_last_sweep_at']);

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
        // Set old sweep timestamp (25 hours ago)
        $oldSweepTime = now()->subHours(25)->toIso8601String();
        $this->integration->update([
            'configuration' => ['oura_last_sweep_at' => $oldSweepTime],
        ]);

        // Mock API responses
        Http::fake([
            'api.ouraring.com/v2/usercollection/workout*' => Http::response(['data' => []]),
            'api.ouraring.com/v2/usercollection/daily_activity*' => Http::response(['data' => []]),
            'api.ouraring.com/v2/usercollection/daily_sleep*' => Http::response(['data' => []]),
            'api.ouraring.com/v2/usercollection/daily_readiness*' => Http::response(['data' => []]),
        ]);

        // Call fetchData
        $this->plugin->fetchData($this->integration);

        // Verify sweep timestamp was updated
        $this->integration->refresh();
        $this->assertNotEquals($oldSweepTime, $this->integration->configuration['oura_last_sweep_at']);

        // Verify sweep API calls were made
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'start_date=' . now()->subDays(30)->toDateString());
        });
    }

    /**
     * @test
     */
    public function perform_data_sweep_creates_events_for_all_data_types(): void
    {
        // Mock API responses with different data types
        Http::fake([
            'api.ouraring.com/v2/usercollection/workout*' => Http::response([
                'data' => [
                    ['day' => '2024-01-01', 'score' => 85, 'workout_type' => 'running'],
                ],
            ]),
            'api.ouraring.com/v2/usercollection/daily_activity*' => Http::response([
                'data' => [
                    ['day' => '2024-01-01', 'score' => 80, 'steps' => 10000],
                ],
            ]),
            'api.ouraring.com/v2/usercollection/daily_sleep*' => Http::response([
                'data' => [
                    ['day' => '2024-01-01', 'score' => 75, 'total_sleep_duration' => 480],
                ],
            ]),
            'api.ouraring.com/v2/usercollection/daily_readiness*' => Http::response([
                'data' => [
                    ['day' => '2024-01-01', 'score' => 70, 'resting_heart_rate' => 60],
                ],
            ]),
        ]);

        // Integration has no previous sweep timestamp
        $this->integration->update(['configuration' => []]);

        // Call fetchData to trigger sweep
        $this->plugin->fetchData($this->integration);

        // Verify events were created for all data types
        $events = Event::where('integration_id', $this->integration->id)->get();
        $this->assertEquals(4, $events->count());

        // Verify different event types were created
        $eventTypes = $events->pluck('action')->unique()->sort()->values();
        $this->assertEquals(['did_workout', 'had_activity_score', 'had_readiness_score', 'had_sleep_score'], $eventTypes->toArray());
    }

    /**
     * @test
     */
    public function perform_data_sweep_handles_api_errors_gracefully(): void
    {
        // Mock API error response
        Http::fake([
            'api.ouraring.com/v2/usercollection/workout*' => Http::response([], 500),
            'api.ouraring.com/v2/usercollection/daily_activity*' => Http::response([], 500),
            'api.ouraring.com/v2/usercollection/daily_sleep*' => Http::response([], 500),
            'api.ouraring.com/v2/usercollection/daily_readiness*' => Http::response([], 500),
        ]);

        // Integration has no previous sweep timestamp
        $this->integration->update(['configuration' => []]);

        // Call fetchData to trigger sweep - should not throw exception
        $this->plugin->fetchData($this->integration);

        // Verify sweep timestamp was still set
        $this->integration->refresh();
        $this->assertNotNull($this->integration->configuration['oura_last_sweep_at']);

        // Verify no events were created due to API errors
        $this->assertEquals(0, Event::where('integration_id', $this->integration->id)->count());
    }

    /**
     * @test
     */
    public function sweep_works_for_different_instance_types(): void
    {
        // Test with different instance types
        $instanceTypes = ['activity', 'sleep', 'readiness', 'workouts', 'stress', 'resilience'];

        // Instance types that should perform sweeps (not in skip list)
        $sweepInstanceTypes = ['activity', 'sleep', 'workouts'];

        // Instance types that should skip sweeps
        $skipSweepInstanceTypes = ['readiness', 'stress', 'resilience'];

        foreach ($instanceTypes as $instanceType) {
            $integration = Integration::factory()->create([
                'user_id' => $this->user->id,
                'integration_group_id' => $this->group->id,
                'service' => 'oura',
                'instance_type' => $instanceType,
                'configuration' => [], // No previous sweep
            ]);

            // Mock API responses
            Http::fake([
                'api.ouraring.com/v2/usercollection/workout*' => Http::response(['data' => []]),
                'api.ouraring.com/v2/usercollection/daily_activity*' => Http::response(['data' => []]),
                'api.ouraring.com/v2/usercollection/daily_sleep*' => Http::response(['data' => []]),
                'api.ouraring.com/v2/usercollection/daily_readiness*' => Http::response(['data' => []]),
            ]);

            // Call fetchData
            $this->plugin->fetchData($integration);

            // Verify sweep timestamp behavior based on instance type
            $integration->refresh();

            if (in_array($instanceType, $sweepInstanceTypes)) {
                // These instance types should perform sweeps and set timestamp
                $this->assertNotNull($integration->configuration['oura_last_sweep_at'],
                    "Sweep should work for instance type: {$instanceType}");
            } else {
                // These instance types should skip sweeps and not set timestamp
                $this->assertNull($integration->configuration['oura_last_sweep_at'] ?? null,
                    "Sweep should be skipped for instance type: {$instanceType}");
            }
        }
    }

    /**
     * @test
     */
    public function sweep_timestamp_format_is_correct(): void
    {
        // Mock API responses
        Http::fake([
            'api.ouraring.com/v2/usercollection/workout*' => Http::response(['data' => []]),
            'api.ouraring.com/v2/usercollection/daily_activity*' => Http::response(['data' => []]),
            'api.ouraring.com/v2/usercollection/daily_sleep*' => Http::response(['data' => []]),
            'api.ouraring.com/v2/usercollection/daily_readiness*' => Http::response(['data' => []]),
        ]);

        // Integration has no previous sweep timestamp
        $this->integration->update(['configuration' => []]);

        // Call fetchData
        $this->plugin->fetchData($this->integration);

        // Verify timestamp format
        $this->integration->refresh();
        $sweepTimestamp = $this->integration->configuration['oura_last_sweep_at'];

        $this->assertIsString($sweepTimestamp);
        $this->assertTrue(Carbon::parse($sweepTimestamp)->isValid());
        $this->assertTrue(Carbon::parse($sweepTimestamp)->isAfter(now()->subMinutes(1)));
    }
}
