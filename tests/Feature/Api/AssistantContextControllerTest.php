<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssistantContextControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function unauthenticated_users_cannot_access_context()
    {
        $response = $this->getJson('/api/assistant/context');

        $response->assertStatus(401);
    }

    #[Test]
    public function returns_404_if_flint_not_configured()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/assistant/context');

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Flint integration not configured']);
    }

    #[Test]
    public function returns_context_json_for_authenticated_user()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Create Flint integration
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'flint',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'flint',
            'instance_type' => 'assistant',
            'configuration' => [
                'yesterday_enabled' => true,
                'today_enabled' => true,
                'tomorrow_enabled' => true,
            ],
        ]);

        // Create some test events
        $monzoIntegration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'monzo']);
        Event::factory()->count(3)->create([
            'integration_id' => $monzoIntegration->id,
            'time' => now(),
        ]);

        $response = $this->getJson('/api/assistant/context');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'yesterday' => [
                'date',
                'timezone',
                'event_count',
                'group_count',
                'service_breakdown',
                'groups',
                'relationships',
            ],
            'today' => [
                'date',
                'timezone',
                'event_count',
                'group_count',
                'service_breakdown',
                'groups',
                'relationships',
            ],
            'tomorrow' => [
                'date',
                'timezone',
                'event_count',
                'group_count',
                'service_breakdown',
                'groups',
                'relationships',
            ],
        ]);

        $this->assertEquals(3, $response->json('today.event_count'));
    }

    #[Test]
    public function returns_user_specific_context_only()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // Create Flint integration for user1
        $group1 = IntegrationGroup::factory()->create([
            'user_id' => $user1->id,
            'service' => 'flint',
        ]);

        Integration::factory()->create([
            'user_id' => $user1->id,
            'integration_group_id' => $group1->id,
            'service' => 'flint',
            'instance_type' => 'assistant',
        ]);

        // Create events for both users
        $monzo1 = Integration::factory()->create(['user_id' => $user1->id, 'service' => 'monzo']);
        $monzo2 = Integration::factory()->create(['user_id' => $user2->id, 'service' => 'monzo']);

        Event::factory()->count(5)->create([
            'integration_id' => $monzo1->id,
            'time' => now(),
        ]);

        Event::factory()->count(10)->create([
            'integration_id' => $monzo2->id,
            'time' => now(),
        ]);

        // User1 should only see their own events
        Sanctum::actingAs($user1);
        $response = $this->getJson('/api/assistant/context');

        $response->assertStatus(200);
        $this->assertEquals(5, $response->json('today.event_count'));
    }

    #[Test]
    public function respects_timeframe_configuration()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'flint',
        ]);

        // Disable today and tomorrow
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'flint',
            'instance_type' => 'assistant',
            'configuration' => [
                'yesterday_enabled' => true,
                'today_enabled' => false,
                'tomorrow_enabled' => false,
            ],
        ]);

        $monzoIntegration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'monzo']);
        Event::factory()->count(5)->create([
            'integration_id' => $monzoIntegration->id,
            'time' => now(),
        ]);

        $response = $this->getJson('/api/assistant/context');

        $response->assertStatus(200);

        $this->assertEquals(0, $response->json('today.event_count'));
        $this->assertEmpty($response->json('today.groups'));
        $this->assertEquals(0, $response->json('tomorrow.event_count'));
        $this->assertEmpty($response->json('tomorrow.groups'));
    }

    #[Test]
    public function response_includes_proper_group_structure()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'flint',
        ]);

        Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'flint',
            'instance_type' => 'assistant',
        ]);

        $spotifyIntegration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'spotify']);

        // Create multiple events in same hour
        for ($i = 0; $i < 5; $i++) {
            Event::factory()->create([
                'integration_id' => $spotifyIntegration->id,
                'service' => 'spotify',
                'action' => 'listened_to',
                'time' => now()->setHour(17)->setMinute($i * 10),
            ]);
        }

        $response = $this->getJson('/api/assistant/context');

        $response->assertStatus(200);

        $groups = $response->json('today.groups');
        $this->assertCount(1, $groups);

        $group = $groups[0];
        $this->assertArrayHasKey('service', $group);
        $this->assertArrayHasKey('action', $group);
        $this->assertArrayHasKey('hour', $group);
        $this->assertArrayHasKey('timezone_hour', $group);
        $this->assertArrayHasKey('count', $group);
        $this->assertArrayHasKey('object_type_plural', $group);
        $this->assertArrayHasKey('summary', $group);
        $this->assertArrayHasKey('is_condensed', $group);
        $this->assertArrayHasKey('formatted_action', $group);
        $this->assertArrayHasKey('first_event', $group);
        $this->assertArrayHasKey('all_events', $group);

        $this->assertEquals(5, $group['count']);
        $this->assertTrue($group['is_condensed']);
    }
}
