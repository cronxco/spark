<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function unauthenticated_users_cannot_create_events()
    {
        $response = $this->postJson('/api/events', []);
        $response->assertStatus(401);
    }

    /**
     * @test
     */
    public function authenticated_user_can_create_event_with_objects_and_blocks()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $actorData = EventObject::factory()->make(['user_id' => $user->id])->toArray();
        $targetData = EventObject::factory()->make(['user_id' => $user->id])->toArray();
        $eventData = Event::factory()->make([
            'integration_id' => $integration->id,
            // actor_id and target_id will be set by the controller
        ])->toArray();
        unset($eventData['actor_id'], $eventData['target_id']);
        $blocksData = [
            Block::factory()->make()->toArray(),
            Block::factory()->make()->toArray(),
        ];
        $payload = [
            'actor' => $actorData,
            'target' => $targetData,
            'event' => $eventData,
            'blocks' => $blocksData,
        ];
        $response = $this->postJson('/api/events', $payload);
        $response->assertStatus(201);
        $response->assertJsonStructure([
            'event' => ['id', 'integration_id', 'actor_id', 'target_id', 'created_at', 'updated_at'],
            'actor' => ['id', 'user_id', 'created_at', 'updated_at'],
            'target' => ['id', 'user_id', 'created_at', 'updated_at'],
            'blocks' => [
                ['id', 'event_id', 'created_at', 'updated_at'],
            ],
        ]);
        $this->assertDatabaseHas('events', ['id' => $response['event']['id']]);
        $this->assertDatabaseHas('objects', ['id' => $response['actor']['id']]);
        $this->assertDatabaseHas('objects', ['id' => $response['target']['id']]);
        foreach ($response['blocks'] as $block) {
            $this->assertDatabaseHas('blocks', ['id' => $block['id']]);
        }
    }
}
