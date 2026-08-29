<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'log']);
    }

    #[Test]
    public function unauthenticated_users_cannot_create_events()
    {
        $payload = $this->eventPayload('00000000-0000-4000-8000-000000000000');
        $objectsBefore = EventObject::count();
        $eventsBefore = Event::count();
        $blocksBefore = Block::count();

        $response = $this->postJson('/api/events', $payload);

        $response->assertStatus(401);
        $this->assertSame($objectsBefore, EventObject::count());
        $this->assertSame($eventsBefore, Event::count());
        $this->assertSame($blocksBefore, Block::count());
    }

    #[Test]
    public function authenticated_user_can_create_event_with_objects_and_blocks()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $payload = $this->eventPayload($integration->id);
        $payload['blocks'][] = [
            'time' => now()->toIso8601String(),
            'title' => 'Second block',
            'metadata' => [],
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

    #[Test]
    public function event_creation_derives_ownership_and_linkage_from_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $otherIntegration = Integration::factory()->create(['user_id' => $otherUser->id]);
        $otherActor = EventObject::factory()->create(['user_id' => $otherUser->id]);
        $otherTarget = EventObject::factory()->create(['user_id' => $otherUser->id]);
        $payload = $this->eventPayload($integration->id);

        $payload['actor']['user_id'] = $otherUser->id;
        $payload['actor']['integration_id'] = $otherIntegration->id;
        $payload['target']['user_id'] = $otherUser->id;
        $payload['target']['integration_id'] = $otherIntegration->id;
        $payload['event']['actor_id'] = $otherActor->id;
        $payload['event']['target_id'] = $otherTarget->id;
        $payload['blocks'][0]['event_id'] = $otherActor->id;
        $payload['blocks'][0]['integration_id'] = $otherIntegration->id;

        $response = $this->postJson('/api/events', $payload);

        $response->assertCreated();
        $response->assertJsonPath('actor.user_id', $user->id);
        $response->assertJsonPath('target.user_id', $user->id);
        $response->assertJsonPath('event.integration_id', $integration->id);
        $response->assertJsonPath('event.actor_id', $response->json('actor.id'));
        $response->assertJsonPath('event.target_id', $response->json('target.id'));
        $response->assertJsonPath('blocks.0.event_id', $response->json('event.id'));
    }

    #[Test]
    public function event_creation_rejects_foreign_and_unknown_integrations_opaquely_without_writing_records(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $otherIntegration = Integration::factory()->create(['user_id' => $otherUser->id]);
        $foreignPayload = $this->eventPayload($otherIntegration->id);
        $unknownPayload = $this->eventPayload('00000000-0000-4000-8000-000000000000');
        $objectsBefore = EventObject::count();
        $eventsBefore = Event::count();
        $blocksBefore = Block::count();

        $foreignResponse = $this->postJson('/api/events', $foreignPayload);

        $foreignResponse->assertNotFound();
        $this->assertSame($objectsBefore, EventObject::count());
        $this->assertSame($eventsBefore, Event::count());
        $this->assertSame($blocksBefore, Block::count());

        $unknownResponse = $this->postJson('/api/events', $unknownPayload);

        $unknownResponse->assertNotFound();
        $this->assertSame($foreignResponse->getContent(), $unknownResponse->getContent());
        $this->assertSame($objectsBefore, EventObject::count());
        $this->assertSame($eventsBefore, Event::count());
        $this->assertSame($blocksBefore, Block::count());
    }

    #[Test]
    public function event_creation_requires_an_integration_id(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload = $this->eventPayload('00000000-0000-4000-8000-000000000000');
        unset($payload['event']['integration_id']);
        $objectsBefore = EventObject::count();
        $eventsBefore = Event::count();
        $blocksBefore = Block::count();

        $this->postJson('/api/events', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event.integration_id');
        $this->assertSame($objectsBefore, EventObject::count());
        $this->assertSame($eventsBefore, Event::count());
        $this->assertSame($blocksBefore, Block::count());
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(string $integrationId): array
    {
        return [
            'actor' => [
                'time' => now()->toIso8601String(),
                'concept' => 'person',
                'type' => 'api_actor',
                'title' => 'Actor',
                'metadata' => [],
            ],
            'target' => [
                'time' => now()->toIso8601String(),
                'concept' => 'document',
                'type' => 'api_target',
                'title' => 'Target',
                'metadata' => [],
            ],
            'event' => [
                'source_id' => (string) Str::uuid(),
                'time' => now()->toIso8601String(),
                'integration_id' => $integrationId,
                'service' => 'api',
                'domain' => 'test',
                'action' => 'created',
                'event_metadata' => [],
            ],
            'blocks' => [[
                'time' => now()->toIso8601String(),
                'title' => 'Block',
                'metadata' => [],
            ]],
        ];
    }
}
