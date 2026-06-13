<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
        // Note writes create a block; keep the task pipeline (embedding etc.) out of these tests.
        config(['app.enable_task_pipeline' => false]);

        $this->user = User::factory()->create();

        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'monzo',
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
        ]);
    }

    #[Test]
    public function requires_authentication(): void
    {
        $event = $this->createEvent();
        $this->getJson("/api/v1/mobile/events/{$event->id}")->assertStatus(401);
    }

    #[Test]
    public function returns_compact_event_shape(): void
    {
        $event = $this->createEvent();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson("/api/v1/mobile/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('id', $event->id)
            ->assertJsonPath('service', 'monzo')
            ->assertJsonPath('action', 'card_payment_to')
            ->assertJsonPath('display_with_object', true)
            ->assertJsonStructure(['id', 'time', 'service', 'domain', 'action', 'display_with_object', 'actor', 'target']);
    }

    #[Test]
    public function returns_404_for_nonexistent_event(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/events/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }

    #[Test]
    public function returns_404_for_malformed_id(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/events/not-a-uuid')->assertStatus(404);
    }

    #[Test]
    public function denies_access_to_other_users_events(): void
    {
        $event = $this->createEvent();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($otherUser, ['ios:read', 'ios:write']);

        $this->getJson("/api/v1/mobile/events/{$event->id}")->assertStatus(404);
    }

    #[Test]
    public function etag_returns_304_on_match(): void
    {
        $event = $this->createEvent();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $first = $this->getJson("/api/v1/mobile/events/{$event->id}")->assertOk();
        $etag = $first->headers->get('ETag');

        $this->getJson("/api/v1/mobile/events/{$event->id}", ['If-None-Match' => $etag])
            ->assertStatus(304);
    }

    #[Test]
    public function update_note_sets_a_note_and_returns_it(): void
    {
        $event = $this->createEvent();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->patchJson("/api/v1/mobile/events/{$event->id}/note", ['note' => 'Remember this one'])
            ->assertOk()
            ->assertJsonPath('id', $event->id)
            ->assertJsonPath('note', 'Remember this one');

        $this->assertDatabaseHas('blocks', [
            'event_id' => $event->id,
            'block_type' => 'note',
            'title' => 'Note',
        ]);

        // The note block must not leak into the generic blocks grid.
        $this->getJson("/api/v1/mobile/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('note', 'Remember this one')
            ->assertJsonMissing(['block_type' => 'note']);
    }

    #[Test]
    public function update_note_is_idempotent_and_updates_existing_note(): void
    {
        $event = $this->createEvent();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->patchJson("/api/v1/mobile/events/{$event->id}/note", ['note' => 'First'])->assertOk();
        $this->patchJson("/api/v1/mobile/events/{$event->id}/note", ['note' => 'Second'])
            ->assertOk()
            ->assertJsonPath('note', 'Second');

        $this->assertSame(1, $event->blocks()->where('block_type', 'note')->count());
    }

    #[Test]
    public function update_note_with_null_clears_the_note(): void
    {
        $event = $this->createEvent();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->patchJson("/api/v1/mobile/events/{$event->id}/note", ['note' => 'Temp'])->assertOk();
        $this->patchJson("/api/v1/mobile/events/{$event->id}/note", ['note' => null])
            ->assertOk()
            ->assertJsonMissingPath('note');

        // The note block is soft-deleted; no live note block should remain.
        $this->assertSame(0, $event->blocks()->where('block_type', 'note')->whereNull('deleted_at')->count());
    }

    #[Test]
    public function update_note_requires_write_ability(): void
    {
        $event = $this->createEvent();
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->patchJson("/api/v1/mobile/events/{$event->id}/note", ['note' => 'nope'])
            ->assertStatus(403);
    }

    #[Test]
    public function update_note_denies_other_users_events(): void
    {
        $event = $this->createEvent();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($otherUser, ['ios:read', 'ios:write']);

        $this->patchJson("/api/v1/mobile/events/{$event->id}/note", ['note' => 'hi'])
            ->assertStatus(404);
    }

    #[Test]
    public function update_note_rejects_overlong_note(): void
    {
        $event = $this->createEvent();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->patchJson("/api/v1/mobile/events/{$event->id}/note", ['note' => str_repeat('a', 10001)])
            ->assertStatus(422);
    }

    protected function createEvent(): Event
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        return Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'value' => 10,
            'value_multiplier' => 1,
            'value_unit' => 'GBP',
            'time' => Carbon::today(),
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);
    }
}
