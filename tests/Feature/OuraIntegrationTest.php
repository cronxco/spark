<?php

namespace Tests\Feature;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Data\Oura\OuraStressData;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class OuraIntegrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function oura_plugin_has_correct_metadata_and_scopes(): void
    {
        $this->assertEquals('oura', OuraPlugin::getIdentifier());
        $this->assertEquals('Oura', OuraPlugin::getDisplayName());
        $this->assertEquals('oauth', OuraPlugin::getServiceType());

        $plugin = new OuraPlugin;
        $reflection = new ReflectionClass($plugin);
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

    #[Test]
    public function oura_plugin_can_initialize_group_and_instance(): void
    {
        $user = User::factory()->create();
        $plugin = new OuraPlugin;

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

    #[Test]
    public function oura_fetch_daily_activity_creates_event_and_blocks(): void
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

        $plugin = new OuraPlugin;
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

    #[Test]
    public function oura_fetch_sleep_records_creates_event_with_stages_and_avg_hr(): void
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
                    'duration' => 8 * 3600, // old field for compatibility
                    'total_sleep_duration' => 7 * 3600, // NEW: main event value (27660 seconds = 7.7 hours)
                    'deep_sleep_duration' => 2 * 3600, // 2 hours
                    'light_sleep_duration' => 4 * 3600, // 4 hours
                    'rem_sleep_duration' => 1 * 3600,   // 1 hour
                    'awake_time' => 1800,               // 30 minutes
                    'latency' => 1200,                  // 20 minutes to fall asleep
                    'restless_periods' => 15,           // 15 restless periods
                    'average_breath' => 14.5,           // 14.5 breaths per minute
                    'efficiency' => 87,
                    'average_heart_rate' => 58,
                    'lowest_heart_rate' => 45,
                    'average_hrv' => 35,
                    'heart_rate' => [
                        'interval' => 300,
                        'items' => [65, 58, 52, 48, 45, 50, 55, 60],
                        'timestamp' => now()->subHours(8)->toIso8601String(),
                    ],
                    'hrv' => [
                        'interval' => 300,
                        'items' => [30, 35, 40, 32, 28, 38, 42, 36],
                        'timestamp' => now()->subHours(8)->toIso8601String(),
                    ],
                    'movement_30_sec' => '111222333444111222333444',
                    'sleep_phase_5_min' => '44432211143',
                ]],
            ], 200),
        ]);

        (new OuraPlugin)->fetchData($integration);

        $event = Event::where('integration_id', $integration->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('health', $event->domain);
        $this->assertEquals('slept_for', $event->action);

        // Verify main event value is now total_sleep_duration (not duration)
        $this->assertEquals(7 * 3600, $event->value); // 7 hours = 25200 seconds
        $this->assertEquals('seconds', $event->value_unit);

        // Verify basic event metadata
        $this->assertArrayHasKey('end', $event->event_metadata);
        $this->assertArrayHasKey('efficiency', $event->event_metadata);

        $blocks = $event->blocks;

        // Verify basic sleep stage blocks exist (simplified OuraPlugin version)
        $this->assertNotNull($blocks->where('title', 'Deep Sleep')->first());
        $this->assertNotNull($blocks->where('title', 'Light Sleep')->first());
        $this->assertNotNull($blocks->where('title', 'REM Sleep')->first());
        $this->assertNotNull($blocks->where('title', 'Awake Time')->first());

        // Verify average heart rate block exists
        $heartRateBlock = $blocks->where('title', 'Average Heart Rate')->first();
        $this->assertNotNull($heartRateBlock);
        $this->assertEquals('heart_rate', $heartRateBlock->block_type);
        $this->assertEquals(58, $heartRateBlock->value); // average heart rate
        $this->assertEquals('bpm', $heartRateBlock->value_unit);

        // Note: Enhanced features like HRV blocks, detailed metadata arrays,
        // and additional metrics are available in the dedicated OuraSleepRecordsData job
        // which provides comprehensive sleep analysis with all requested features.
    }

    #[Test]
    public function oura_fetch_heartrate_series_creates_daily_event_with_min_max_blocks(): void
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
                    ['timestamp' => $day . 'T00:00:00Z', 'bpm' => 50],
                    ['timestamp' => $day . 'T01:00:00Z', 'bpm' => 70],
                    ['timestamp' => $day . 'T02:00:00Z', 'bpm' => 60],
                ],
            ], 200),
        ]);

        (new OuraPlugin)->fetchData($integration);
        $event = Event::where('integration_id', $integration->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('had_heart_rate', $event->action);
        $this->assertEquals('bpm', $event->value_unit);
        $this->assertNotNull($event->blocks->where('title', 'Min Heart Rate')->first());
        $this->assertNotNull($event->blocks->where('title', 'Max Heart Rate')->first());
    }

    #[Test]
    public function oura_fetch_workouts_creates_event_seconds_and_blocks(): void
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

        (new OuraPlugin)->fetchData($integration);
        $event = Event::where('integration_id', $integration->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('did_workout', $event->action);
        $this->assertEquals('seconds', $event->value_unit);
        $this->assertNotNull($event->blocks->where('title', 'Calories')->first());
        $this->assertNotNull($event->blocks->where('title', 'Average Heart Rate')->first());
    }

    #[Test]
    public function oura_fetch_sessions_creates_event_seconds_and_mindfulness_concept(): void
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

        (new OuraPlugin)->fetchData($integration);
        $event = Event::where('integration_id', $integration->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('health', $event->domain);
        $this->assertEquals('had_mindfulness_session', $event->action);
        $this->assertEquals('seconds', $event->value_unit);
    }

    #[Test]
    public function oura_fetch_tags_creates_event_and_tag_block(): void
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

        (new OuraPlugin)->fetchData($integration);
        $event = Event::where('integration_id', $integration->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('health', $event->domain);
        $this->assertEquals('had_oura_tag', $event->action);
        $this->assertNull($event->value);
        $this->assertNotNull($event->blocks->where('title', 'Tag')->first());
    }

    #[Test]
    public function oura_daily_readiness_resilience_stress_spo2_actions(): void
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
            'https://api.ouraring.com/v2/usercollection/daily_stress*' => Http::response(['data' => [['day' => $day, 'day_summary' => 'normal']]], 200),
            'https://api.ouraring.com/v2/usercollection/daily_spo2*' => Http::response(['data' => [['id' => 'spo2-test-id', 'day' => $day, 'breathing_disturbance_index' => 5, 'spo2_percentage' => ['average' => 97.125]]]], 200),
        ]);

        // Readiness
        $readiness = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'readiness',
            'configuration' => ['days_back' => 1],
        ]);
        (new OuraPlugin)->fetchData($readiness);
        $this->assertEquals('had_readiness_score', Event::where('integration_id', $readiness->id)->first()->action);

        // Resilience
        $resilience = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'resilience',
            'configuration' => ['days_back' => 1],
        ]);
        (new OuraPlugin)->fetchData($resilience);
        $this->assertEquals('had_resilience_score', Event::where('integration_id', $resilience->id)->first()->action);

        // Stress
        $stress = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'stress',
            'configuration' => ['days_back' => 1],
        ]);
        $plugin = new OuraPlugin;
        $stressData = $plugin->pullStressData($stress);
        (new OuraStressData($stress, $stressData))->handle();
        $this->assertEquals('had_stress_score', Event::where('integration_id', $stress->id)->first()->action);

        // SpO2
        $spo2 = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'spo2',
            'configuration' => ['days_back' => 1],
        ]);
        (new OuraPlugin)->fetchData($spo2);
        $this->assertEquals('had_spo2', Event::where('integration_id', $spo2->id)->first()->action);
    }

    #[Test]
    public function oura_stress_data_processes_new_format_with_day_summary_and_blocks(): void
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
            'instance_type' => 'stress',
            'configuration' => ['days_back' => 1],
        ]);

        // Mock the new stress data format
        Http::fake([
            'https://api.ouraring.com/v2/usercollection/personal_info*' => Http::response([
                'id' => 'user_abc',
                'email' => 'test@example.com',
            ], 200),
            'https://api.ouraring.com/v2/usercollection/daily_stress*' => Http::response([
                'data' => [[
                    'id' => '31defd5a-92b2-4ba7-986b-54649873aef0',
                    'day' => '2025-08-29',
                    'stress_high' => 3600,
                    'recovery_high' => 2700,
                    'day_summary' => 'normal',
                ]],
            ], 200),
        ]);

        // Simulate the pull job workflow
        $plugin = new OuraPlugin;
        $stressData = $plugin->pullStressData($integration);

        // Process the data through the job
        $job = new OuraStressData($integration, $stressData);
        $job->handle();

        // Verify the main stress event was created
        $event = Event::where('integration_id', $integration->id)
            ->where('action', 'had_stress_score')
            ->first();

        $this->assertNotNull($event, 'Stress event should be created');
        $this->assertEquals('2025-08-29 00:00:00', $event->time);
        $this->assertEquals('health', $event->domain);
        $this->assertEquals('stress_level', $event->value_unit);

        // Check that day_summary 'normal' maps to value 2
        $plugin = new OuraPlugin;
        $expectedMappedValue = $plugin->mapValueForStorage('stress_day_summary', 'normal');
        $this->assertEquals(2, $expectedMappedValue);

        [$encodedValue, $multiplier] = $plugin->encodeNumericValue($expectedMappedValue);
        $this->assertEquals($encodedValue, $event->value);
        $this->assertEquals($multiplier, $event->value_multiplier);

        // Verify event metadata contains original values
        $this->assertEquals('2025-08-29', $event->event_metadata['day']);
        $this->assertEquals('normal', $event->event_metadata['original_day_summary']);
        $this->assertEquals(2, $event->event_metadata['mapped_value']);

        // Verify stress_high block was created
        $stressHighBlock = $event->blocks()
            ->where('title', 'Stress High Duration')
            ->first();
        $this->assertNotNull($stressHighBlock, 'Stress high duration block should be created');
        $this->assertEquals(3600, $stressHighBlock->value);
        $this->assertEquals('seconds', $stressHighBlock->value_unit);
        $this->assertEquals('biometrics', $stressHighBlock->block_type);
        $this->assertEquals('stress_duration', $stressHighBlock->metadata['type']);
        $this->assertEquals('high', $stressHighBlock->metadata['stress_level']);

        // Verify recovery_high block was created
        $recoveryHighBlock = $event->blocks()
            ->where('title', 'Recovery High Duration')
            ->first();
        $this->assertNotNull($recoveryHighBlock, 'Recovery high duration block should be created');
        $this->assertEquals(2700, $recoveryHighBlock->value);
        $this->assertEquals('seconds', $recoveryHighBlock->value_unit);
        $this->assertEquals('biometrics', $recoveryHighBlock->block_type);
        $this->assertEquals('recovery_duration', $recoveryHighBlock->metadata['type']);
        $this->assertEquals('high', $recoveryHighBlock->metadata['recovery_level']);
    }

    #[Test]
    public function oura_stress_data_maps_stressful_day_summary_correctly(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'oura',
            'account_id' => 'user_abc_stressful',
            'access_token' => 'test_access_token',
            'expiry' => now()->addHour(),
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'stress',
            'configuration' => ['days_back' => 1],
        ]);

        Http::fake([
            'https://api.ouraring.com/v2/usercollection/personal_info*' => Http::response([
                'id' => 'user_abc_stressful',
                'email' => 'test_stressful@example.com',
            ], 200),
            'https://api.ouraring.com/v2/usercollection/daily_stress*' => Http::response([
                'data' => [[
                    'id' => 'test-id-stressful',
                    'day' => '2025-08-27',
                    'stress_high' => 1800,
                    'recovery_high' => 1200,
                    'day_summary' => 'stressful',
                ]],
            ], 200),
        ]);

        // Simulate the pull job workflow
        $plugin = new OuraPlugin;
        $stressData = $plugin->pullStressData($integration);
        $job = new OuraStressData($integration, $stressData);
        $job->handle();

        $event = Event::where('integration_id', $integration->id)
            ->where('action', 'had_stress_score')
            ->whereJsonContains('event_metadata->day', '2025-08-27')
            ->first();

        $this->assertNotNull($event, 'Event should be created for stressful');

        [$encodedValue, $multiplier] = $plugin->encodeNumericValue(3);
        $this->assertEquals($encodedValue, $event->value, 'Event should have correct mapped value for stressful');
        $this->assertEquals($multiplier, $event->value_multiplier);
        $this->assertEquals('stressful', $event->event_metadata['original_day_summary']);
    }

    #[Test]
    public function oura_stress_data_maps_normal_day_summary_correctly(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'oura',
            'account_id' => 'user_abc_normal',
            'access_token' => 'test_access_token',
            'expiry' => now()->addHour(),
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'stress',
            'configuration' => ['days_back' => 1],
        ]);

        Http::fake([
            'https://api.ouraring.com/v2/usercollection/personal_info*' => Http::response([
                'id' => 'user_abc_normal',
                'email' => 'test_normal@example.com',
            ], 200),
            'https://api.ouraring.com/v2/usercollection/daily_stress*' => Http::response([
                'data' => [[
                    'id' => 'test-id-normal',
                    'day' => '2025-08-28',
                    'stress_high' => 1800,
                    'recovery_high' => 1200,
                    'day_summary' => 'normal',
                ]],
            ], 200),
        ]);

        // Simulate the pull job workflow
        $plugin = new OuraPlugin;
        $stressData = $plugin->pullStressData($integration);
        $job = new OuraStressData($integration, $stressData);
        $job->handle();

        $event = Event::where('integration_id', $integration->id)
            ->where('action', 'had_stress_score')
            ->whereJsonContains('event_metadata->day', '2025-08-28')
            ->first();

        $this->assertNotNull($event, 'Event should be created for normal');

        [$encodedValue, $multiplier] = $plugin->encodeNumericValue(2);
        $this->assertEquals($encodedValue, $event->value, 'Event should have correct mapped value for normal');
        $this->assertEquals($multiplier, $event->value_multiplier);
        $this->assertEquals('normal', $event->event_metadata['original_day_summary']);
    }

    #[Test]
    public function oura_stress_data_maps_restored_day_summary_correctly(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'oura',
            'account_id' => 'user_abc_restored',
            'access_token' => 'test_access_token',
            'expiry' => now()->addHour(),
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'stress',
            'configuration' => ['days_back' => 1],
        ]);

        Http::fake([
            'https://api.ouraring.com/v2/usercollection/personal_info*' => Http::response([
                'id' => 'user_abc_restored',
                'email' => 'test_restored@example.com',
            ], 200),
            'https://api.ouraring.com/v2/usercollection/daily_stress*' => Http::response([
                'data' => [[
                    'id' => 'test-id-restored',
                    'day' => '2025-08-29',
                    'stress_high' => 1800,
                    'recovery_high' => 1200,
                    'day_summary' => 'restored',
                ]],
            ], 200),
        ]);

        // Simulate the pull job workflow
        $plugin = new OuraPlugin;
        $stressData = $plugin->pullStressData($integration);
        $job = new OuraStressData($integration, $stressData);
        $job->handle();

        $event = Event::where('integration_id', $integration->id)
            ->where('action', 'had_stress_score')
            ->whereJsonContains('event_metadata->day', '2025-08-29')
            ->first();

        $this->assertNotNull($event, 'Event should be created for restored');

        [$encodedValue, $multiplier] = $plugin->encodeNumericValue(1);
        $this->assertEquals($encodedValue, $event->value, 'Event should have correct mapped value for restored');
        $this->assertEquals($multiplier, $event->value_multiplier);
        $this->assertEquals('restored', $event->event_metadata['original_day_summary']);
    }
}
