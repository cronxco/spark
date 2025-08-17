<?php

namespace Tests\Feature;

use App\Integrations\Hevy\HevyPlugin;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HevyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_hevy_plugin_has_correct_metadata_and_service_type(): void
    {
        $this->assertEquals('hevy', HevyPlugin::getIdentifier());
        $this->assertEquals('Hevy', HevyPlugin::getDisplayName());
        $this->assertEquals('apikey', HevyPlugin::getServiceType());
    }

    public function test_hevy_plugin_can_initialize_group_and_instance(): void
    {
        $user = User::factory()->create();
        $plugin = new HevyPlugin();

        $group = $plugin->initializeGroup($user);
        $this->assertInstanceOf(IntegrationGroup::class, $group);
        $this->assertEquals($user->id, $group->user_id);
        $this->assertEquals('hevy', $group->service);

        $instance = $plugin->createInstance($group, 'workouts', ['days_back' => 3]);
        $this->assertInstanceOf(Integration::class, $instance);
        $this->assertEquals($group->id, $instance->integration_group_id);
        $this->assertEquals('workouts', $instance->instance_type);
        $this->assertEquals('hevy', $instance->service);
    }

    public function test_hevy_fetch_workouts_creates_event_and_set_blocks(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'hevy',
            'account_id' => 'user_hevy_123',
            'access_token' => 'test_access_token',
            'expiry' => now()->addHour(),
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'hevy',
            'name' => 'Hevy Test',
            'instance_type' => 'workouts',
            'configuration' => ['days_back' => 1, 'units' => 'kg'],
        ]);

        // Fake Hevy API responses
        Http::fake([
            'https://api.hevyapp.com/v1/me*' => Http::response([
                'id' => 'user_hevy_123',
                'email' => 'hevy@example.com',
                'name' => 'Hevy User',
            ], 200),
            'https://api.hevyapp.com/v1/workouts*' => Http::response([
                'data' => [[
                    'id' => 'w_1',
                    'title' => 'Push Day',
                    'start_time' => now()->subHour()->toIso8601String(),
                    'end_time' => now()->toIso8601String(),
                    'duration_seconds' => 3600,
                    'total_volume' => 8250.5,
                    'weight_unit' => 'kg',
                    'exercises' => [
                        [
                            'name' => 'Bench Press',
                            'weight_unit' => 'kg',
                            'sets' => [
                                ['reps' => 8, 'weight' => 80, 'rest_seconds' => 120, 'rpe' => 8],
                                ['reps' => 6, 'weight' => 85, 'rest_seconds' => 150, 'rpe' => 9],
                            ],
                        ],
                        [
                            'name' => 'Incline Dumbbell Press',
                            'weight_unit' => 'kg',
                            'sets' => [
                                ['reps' => 12, 'weight' => 30, 'rest_seconds' => 90],
                            ],
                        ],
                    ],
                ]],
            ], 200),
        ]);

        $plugin = new HevyPlugin();
        $plugin->fetchData($integration);

        $event = Event::where('integration_id', $integration->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('hevy', $event->service);
        $this->assertEquals('fitness', $event->domain);
        $this->assertEquals('completed_workout', $event->action);
        $this->assertEquals('kg', $event->value_unit);

        // Each set becomes a block
        $blocks = $event->blocks;
        $this->assertGreaterThanOrEqual(3, $blocks->count());
        $this->assertNotNull($blocks->firstWhere('title', 'Bench Press - Set 1'));
        $this->assertNotNull($blocks->firstWhere('title', 'Bench Press - Set 2'));
        $this->assertNotNull($blocks->firstWhere('title', 'Incline Dumbbell Press - Set 1'));

        // Exercise summary block exists
        $this->assertNotNull($blocks->firstWhere('title', 'Bench Press - Total Volume'));
    }
}


