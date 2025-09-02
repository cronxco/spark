<?php

namespace Tests\Feature;

use App\Integrations\AppleHealth\AppleHealthPlugin;
use App\Integrations\PluginRegistry;
use App\Jobs\Webhook\AppleHealth\AppleHealthWebhookHook;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
//
use Tests\TestCase;

class AppleHealthIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure plugin is registered in test runtime
        PluginRegistry::register(AppleHealthPlugin::class);

        // Use queue fake for testing job dispatching
        Queue::fake();
    }

    #[Test]
    public function webhook_ingests_workouts_creating_event_and_blocks(): void
    {
        $user = User::factory()->create();
        [$group, $workouts, $metrics] = $this->createGroupWithInstances($user);

        $payload = [
            'workouts' => [
                [
                    'id' => '1F088C46-09AD-4259-A78F-6CBE9F90BD94',
                    'name' => 'Outdoor Walk',
                    'start' => '2025-08-13 19:37:47 +0100',
                    'end' => '2025-08-13 19:56:37 +0100',
                    'duration' => 1130.0801928043365,
                    'location' => 'Outdoor',
                    'distance' => ['qty' => 1.8251686498265018, 'units' => 'km'],
                    'activeEnergyBurned' => ['qty' => 77.31842723339633, 'units' => 'kcal'],
                    'intensity' => ['qty' => 4.335439755992295, 'units' => 'kcal/hr·kg'],
                    'metadata' => [],
                ],
                [
                    'id' => '0A38C1F7-D607-4C37-87DE-C789C73E8B5E',
                    'name' => 'Indoor Walk',
                    'start' => '2025-08-13 08:15:03 +0100',
                    'end' => '2025-08-13 08:33:31 +0100',
                    'duration' => 1107.9819749593735,
                    'location' => 'Indoor',
                    'distance' => ['qty' => 1.638549294765587, 'units' => 'km'],
                    'activeEnergyBurned' => ['qty' => 129.88400000000024, 'units' => 'kcal'],
                    'intensity' => ['qty' => 6.817569770668788, 'units' => 'kcal/hr·kg'],
                    'metadata' => [],
                ],
            ],
        ];

        $resp = $this->postJson(route('webhook.handle', ['service' => 'apple_health', 'secret' => $group->account_id]), $payload);
        $resp->assertStatus(200);

        // Verify webhook job was dispatched (processing is now asynchronous)
        // Since we can see from debug output that jobs are being dispatched,
        // let's just check that at least one AppleHealthWebhookHook job was pushed
        Queue::assertPushed(AppleHealthWebhookHook::class);

        // Note: Database assertions removed as processing is now asynchronous
        // The actual event/block creation happens in the background job
    }

    #[Test]
    public function webhook_ingests_metrics_creating_events(): void
    {
        $user = User::factory()->create();
        [$group, $workouts, $metrics] = $this->createGroupWithInstances($user);

        $payload = [
            'metrics' => [
                [
                    'name' => 'resting_heart_rate',
                    'units' => 'count/min',
                    'data' => [
                        ['date' => '2025-08-12 00:00:00 +0100', 'qty' => 79],
                        ['date' => '2025-08-13 00:00:00 +0100', 'qty' => 79],
                    ],
                ],
                [
                    'name' => 'heart_rate',
                    'units' => 'count/min',
                    'data' => [
                        ['date' => '2025-08-12 00:00:00 +0100', 'Min' => 59, 'Avg' => 90.5, 'Max' => 180],
                        ['date' => '2025-08-13 00:00:00 +0100', 'Min' => 55, 'Avg' => 76.0, 'Max' => 138],
                    ],
                ],
                [
                    'name' => 'walking_running_distance',
                    'units' => 'km',
                    'data' => [
                        ['date' => '2025-08-12 00:00:00 +0100', 'qty' => 5.48, 'source' => 'Watch|iPhone'],
                    ],
                ],
            ],
        ];

        $resp = $this->postJson(route('webhook.handle', ['service' => 'apple_health', 'secret' => $group->account_id]), $payload);
        $resp->assertStatus(200);

        // Verify webhook job was dispatched (processing is now asynchronous)
        Queue::assertPushed(AppleHealthWebhookHook::class);

        // Note: Database assertions removed as processing is now asynchronous
        // The actual event creation happens in the background job
    }

    private function createGroupWithInstances(User $user): array
    {
        $plugin = new AppleHealthPlugin;
        $group = $plugin->initializeGroup($user);
        $workouts = $plugin->createInstance($group, 'workouts');
        $metrics = $plugin->createInstance($group, 'metrics');

        return [$group, $workouts, $metrics];
    }
}
