<?php

namespace Tests\Feature;

use App\Integrations\Oura\OuraPlugin;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OuraIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_oura_plugin_has_correct_metadata_and_scopes(): void
    {
        $this->assertEquals('oura', OuraPlugin::getIdentifier());
        $this->assertEquals('Oura', OuraPlugin::getDisplayName());
        $this->assertEquals('oauth', OuraPlugin::getServiceType());

        $plugin = new OuraPlugin();
        $reflection = new \ReflectionClass($plugin);
        $method = $reflection->getMethod('getRequiredScopes');
        $method->setAccessible(true);
        $scopes = $method->invoke($plugin);

        $this->assertStringContainsString('email', $scopes);
        $this->assertStringContainsString('personal', $scopes);
        $this->assertStringContainsString('daily', $scopes);
        $this->assertStringContainsString('heartrate', $scopes);
        $this->assertStringContainsString('workout', $scopes);
        $this->assertStringContainsString('tag', $scopes);
        $this->assertStringContainsString('session', $scopes);
        $this->assertStringContainsString('spo2', $scopes);
    }

    public function test_oura_plugin_can_initialize_group_and_instance(): void
    {
        $user = User::factory()->create();
        $plugin = new OuraPlugin();

        $group = $plugin->initializeGroup($user);
        $this->assertInstanceOf(IntegrationGroup::class, $group);
        $this->assertEquals($user->id, $group->user_id);
        $this->assertEquals('oura', $group->service);

        $instance = $plugin->createInstance($group, 'activity', ['days_back' => 3]);
        $this->assertInstanceOf(Integration::class, $instance);
        $this->assertEquals($group->id, $instance->integration_group_id);
        $this->assertEquals('activity', $instance->instance_type);
        $this->assertEquals('oura', $instance->service);
    }

    public function test_oura_fetch_daily_activity_creates_event_and_blocks(): void
    {
        $user = User::factory()->create();

        // Create a group with an access token (used by the plugin HTTP client)
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'oura',
            'account_id' => 'user_abc',
            'access_token' => 'test_access_token',
            'refresh_token' => 'test_refresh',
            'expiry' => now()->addHour(),
        ]);

        // Create an integration instance bound to the group
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'name' => 'Oura Test',
            'instance_type' => 'activity',
            'configuration' => ['days_back' => 1],
        ]);

        // Mock Oura API responses used by the plugin
        Http::fake([
            // Personal info
            'https://api.ouraring.com/v2/usercollection/personal_info*' => Http::response([
                'data' => [[
                    'user_id' => 'user_abc',
                    'email' => 'test@example.com',
                    'age' => 30,
                    'biological_sex' => 'male',
                    'weight' => 70,
                    'height' => 175,
                    'dominant_hand' => 'right',
                ]],
            ], 200),

            // Daily activity
            'https://api.ouraring.com/v2/usercollection/daily_activity*' => Http::response([
                'data' => [[
                    'day' => now()->toDateString(),
                    'score' => 82,
                    'contributors' => [
                        'meet_daily_targets' => 75,
                        'move_every_hour' => 90,
                        'stay_active' => 80,
                    ],
                    'steps' => 10432,
                    'cal_total' => 2350,
                    'equivalent_walking_distance' => 7.8,
                    'target_calories' => 2200,
                    'non_wear_time' => 0,
                ]],
            ], 200),
        ]);

        $plugin = new OuraPlugin();
        $plugin->fetchData($integration);

        $event = Event::where('integration_id', $integration->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('oura', $event->service);
        $this->assertEquals('health', $event->domain);
        $this->assertEquals('had_activity_score', $event->action);
        $this->assertEquals(82, $event->value);
        $this->assertEquals('percent', $event->value_unit);

        // Check that contributor blocks were created (e.g., Meet Daily Targets)
        $blocks = $event->blocks;
        $this->assertGreaterThan(0, $blocks->count());
        $this->assertNotNull($blocks->where('title', 'Meet Daily Targets')->first());

        // Check that individual detail blocks exist (e.g., Steps, Cal Total)
        $this->assertNotNull($blocks->where('title', 'Steps')->first());
        $this->assertNotNull($blocks->where('title', 'Cal Total')->first());
    }

    public function test_oura_fetch_sleep_records_creates_event_with_stages_and_avg_hr(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'oura',
            'account_id' => 'user_abc',
            'access_token' => 'test_access_token',
            'expiry' => now()->addHour(),
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'sleep_records',
            'configuration' => ['days_back' => 1],
        ]);

        Http::fake([
            'https://api.ouraring.com/v2/usercollection/personal_info*' => Http::response(['data' => [['user_id' => 'user_abc']]], 200),
            'https://api.ouraring.com/v2/usercollection/sleep*' => Http::response([
                'data' => [[
                    'id' => 'sleep_1',
                    'bedtime_start' => now()->subHours(8)->toIso8601String(),
                    'bedtime_end' => now()->toIso8601String(),
                    'duration' => 8 * 3600,
                    'efficiency' => 0.95,
                    'sleep_stages' => [
                        'deep' => 3600,
                        'light' => 14400,
                        'rem' => 5400,
                        'awake' => 900,
                    ],
                    'average_heart_rate' => 58,
                ]],
            ], 200),
        ]);

        (new OuraPlugin())->fetchData($integration);

        $event = Event::where('integration_id', $integration->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('sleep', $event->domain);
        $this->assertEquals('slept_for', $event->action);
        $this->assertEquals(8 * 3600, $event->value);
        $this->assertEquals('seconds', $event->value_unit);

        $blocks = $event->blocks;
        $this->assertNotNull($blocks->where('title', 'Deep Sleep')->first());
        $this->assertNotNull($blocks->where('title', 'Light Sleep')->first());
        $this->assertNotNull($blocks->where('title', 'REM Sleep')->first());
        $this->assertNotNull($blocks->where('title', 'Awake Time')->first());
        $this->assertNotNull($blocks->where('title', 'Average Heart Rate')->first());
    }

    public function test_oura_fetch_heartrate_series_creates_daily_event_with_min_max_blocks(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'oura',
            'account_id' => 'user_abc',
            'access_token' => 'test_access_token',
            'expiry' => now()->addHour(),
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'heartrate',
            'configuration' => ['days_back' => 1],
        ]);

        $day = now()->toDateString();
        Http::fake([
            'https://api.ouraring.com/v2/usercollection/personal_info*' => Http::response(['data' => [['user_id' => 'user_abc']]], 200),
            'https://api.ouraring.com/v2/usercollection/heartrate*' => Http::response([
                'data' => [
                    ['timestamp' => $day.'T00:00:00Z', 'bpm' => 50],
                    ['timestamp' => $day.'T01:00:00Z', 'bpm' => 70],
                    ['timestamp' => $day.'T02:00:00Z', 'bpm' => 60],
                ],
            ], 200),
        ]);

        (new OuraPlugin())->fetchData($integration);
        $event = Event::where('integration_id', $integration->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('had_heart_rate', $event->action);
        $this->assertEquals('bpm', $event->value_unit);
        $this->assertNotNull($event->blocks->where('title', 'Min Heart Rate')->first());
        $this->assertNotNull($event->blocks->where('title', 'Max Heart Rate')->first());
    }

    public function test_oura_fetch_workouts_creates_event_seconds_and_blocks(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'oura',
            'account_id' => 'user_abc',
            'access_token' => 'test_access_token',
            'expiry' => now()->addHour(),
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'workouts',
            'configuration' => ['days_back' => 1],
        ]);

        Http::fake([
            'https://api.ouraring.com/v2/usercollection/personal_info*' => Http::response(['data' => [['user_id' => 'user_abc']]], 200),
            'https://api.ouraring.com/v2/usercollection/workout*' => Http::response([
                'data' => [[
                    'id' => 'w1',
                    'activity' => 'run',
                    'start_datetime' => now()->subMinutes(30)->toIso8601String(),
                    'end_datetime' => now()->toIso8601String(),
                    'duration' => 1800,
                    'calories' => 450.5,
                    'average_heart_rate' => 140,
                ]],
            ], 200),
        ]);

        (new OuraPlugin())->fetchData($integration);
        $event = Event::where('integration_id', $integration->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('did_workout', $event->action);
        $this->assertEquals('seconds', $event->value_unit);
        $this->assertNotNull($event->blocks->where('title', 'Calories')->first());
        $this->assertNotNull($event->blocks->where('title', 'Average Heart Rate')->first());
    }

    public function test_oura_fetch_sessions_creates_event_seconds_and_mindfulness_concept(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'oura',
            'account_id' => 'user_abc',
            'access_token' => 'test_access_token',
            'expiry' => now()->addHour(),
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'sessions',
            'configuration' => ['days_back' => 1],
        ]);

        Http::fake([
            'https://api.ouraring.com/v2/usercollection/personal_info*' => Http::response(['data' => [['user_id' => 'user_abc']]], 200),
            'https://api.ouraring.com/v2/usercollection/session*' => Http::response([
                'data' => [[
                    'id' => 's1',
                    'type' => 'breathing',
                    'start_datetime' => now()->subMinutes(10)->toIso8601String(),
                    'duration' => 600,
                    'mood' => 'calm',
                ]],
            ], 200),
        ]);

        (new OuraPlugin())->fetchData($integration);
        $event = Event::where('integration_id', $integration->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('health', $event->domain);
        $this->assertEquals('had_mindfulness_session', $event->action);
        $this->assertEquals('seconds', $event->value_unit);
    }

    public function test_oura_fetch_tags_creates_event_and_tag_block(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'oura',
            'account_id' => 'user_abc',
            'access_token' => 'test_access_token',
            'expiry' => now()->addHour(),
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'tags',
            'configuration' => ['days_back' => 1],
        ]);

        Http::fake([
            'https://api.ouraring.com/v2/usercollection/personal_info*' => Http::response(['data' => [['user_id' => 'user_abc']]], 200),
            'https://api.ouraring.com/v2/usercollection/tag*' => Http::response([
                'data' => [[
                    'timestamp' => now()->toIso8601String(),
                    'tag' => 'travel',
                ]],
            ], 200),
        ]);

        (new OuraPlugin())->fetchData($integration);
        $event = Event::where('integration_id', $integration->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('health', $event->domain);
        $this->assertEquals('had_oura_tag', $event->action);
        $this->assertNull($event->value);
        $this->assertNotNull($event->blocks->where('title', 'Tag')->first());
    }

    public function test_oura_daily_readiness_resilience_stress_spo2_actions(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'oura',
            'account_id' => 'user_abc',
            'access_token' => 'test_access_token',
            'expiry' => now()->addHour(),
        ]);

        $day = now()->toDateString();
        Http::fake([
            'https://api.ouraring.com/v2/usercollection/personal_info*' => Http::response(['data' => [['user_id' => 'user_abc']]], 200),
            'https://api.ouraring.com/v2/usercollection/daily_readiness*' => Http::response(['data' => [['day' => $day, 'score' => 90, 'contributors' => []]]], 200),
            'https://api.ouraring.com/v2/usercollection/daily_resilience*' => Http::response(['data' => [['day' => $day, 'resilience_score' => 70, 'contributors' => []]]], 200),
            'https://api.ouraring.com/v2/usercollection/daily_stress*' => Http::response(['data' => [['day' => $day, 'stress_score' => 30, 'contributors' => []]]], 200),
            'https://api.ouraring.com/v2/usercollection/daily_spo2*' => Http::response(['data' => [['day' => $day, 'spo2_average' => 97]]], 200),
        ]);

        // Readiness
        $readiness = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'readiness',
            'configuration' => ['days_back' => 1],
        ]);
        (new OuraPlugin())->fetchData($readiness);
        $this->assertEquals('had_readiness_score', Event::where('integration_id', $readiness->id)->first()->action);

        // Resilience
        $resilience = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'resilience',
            'configuration' => ['days_back' => 1],
        ]);
        (new OuraPlugin())->fetchData($resilience);
        $this->assertEquals('had_resilience_score', Event::where('integration_id', $resilience->id)->first()->action);

        // Stress
        $stress = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'stress',
            'configuration' => ['days_back' => 1],
        ]);
        (new OuraPlugin())->fetchData($stress);
        $this->assertEquals('had_stress_score', Event::where('integration_id', $stress->id)->first()->action);

        // SpO2
        $spo2 = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'spo2',
            'configuration' => ['days_back' => 1],
        ]);
        (new OuraPlugin())->fetchData($spo2);
        $this->assertEquals('had_spo2', Event::where('integration_id', $spo2->id)->first()->action);
    }
}


