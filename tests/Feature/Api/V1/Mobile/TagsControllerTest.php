<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Tags\Tag;
use Tests\TestCase;

class TagsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
        config(['app.enable_task_pipeline' => false]);

        $this->user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'test',
        ]);
    }

    #[Test]
    public function browse_is_user_scoped_and_includes_counts(): void
    {
        $visible = Tag::findOrCreate('coffee', 'spark');
        $hidden = Tag::findOrCreate('private-other-user-tag', 'spark');
        $event = $this->createEvent('coffee meeting');
        $event->attachTags([$visible]);

        $other = User::factory()->create();
        $otherObject = EventObject::factory()->create(['user_id' => $other->id]);
        $otherObject->attachTags([$hidden]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/tags')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $visible->id)
            ->assertJsonPath('data.0.events_count', 1)
            ->assertJsonPath('data.0.total_count', 1)
            ->assertJsonMissing(['name' => 'private-other-user-tag']);
    }

    #[Test]
    public function detail_returns_only_items_actually_tagged(): void
    {
        $tag = Tag::findOrCreate('coffee', 'spark');
        $tagged = $this->createEvent('Tagged');
        $tagged->attachTags([$tag]);
        $this->createEvent('Coffee appears in text but is not tagged');

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson("/api/v1/mobile/tags/{$tag->id}")
            ->assertOk()
            ->assertJsonPath('tag.id', (string) $tag->id)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $tagged->id)
            ->assertJsonPath('data.0.kind', 'event');
    }

    #[Test]
    public function suggest_prioritises_an_exact_existing_tag(): void
    {
        $exact = Tag::findOrCreate('coffee', 'spark');
        $prefix = Tag::findOrCreate('coffee shop', 'spark');
        $event = $this->createEvent('Tagged');
        $event->attachTags([$exact, $prefix]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/tags/suggest?q=coffee')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $exact->id);
    }

    #[Test]
    public function write_endpoints_create_attach_remove_and_log_tags(): void
    {
        $event = $this->createEvent('Tagged');
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $created = $this->postJson("/api/v1/mobile/events/{$event->id}/tags", [
            'name' => 'new tag',
        ], $this->ifMatchEvent($event))->assertCreated()
            ->assertJsonPath('tag.name', 'new tag')
            ->assertJsonPath('tag.type', 'spark');

        $tagId = $created->json('tag.id');
        $this->assertTrue($event->fresh()->tags()->where('tags.id', $tagId)->exists());
        $this->getJson("/api/v1/mobile/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('tags.0.id', $tagId)
            ->assertJsonPath('tags.0.name', 'new tag');

        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $event->id,
            'event' => 'tag_added',
        ]);

        $this->deleteJson("/api/v1/mobile/events/{$event->id}/tags/{$tagId}", [], $this->ifMatchEvent($event))
            ->assertOk()
            ->assertJsonCount(0, 'tags');

        $this->assertFalse($event->fresh()->tags()->where('tags.id', $tagId)->exists());
        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $event->id,
            'event' => 'tag_removed',
        ]);
    }

    #[Test]
    public function object_write_endpoints_attach_existing_tags(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);
        $tag = Tag::findOrCreate('person', 'spark');
        $this->createEvent('Tagged')->attachTags([$tag]);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/objects/{$object->id}/tags", [
            'tag_id' => (string) $tag->id,
        ], $this->ifMatchObject($object))->assertCreated()
            ->assertJsonPath('tags.0.id', (string) $tag->id);

        $this->getJson("/api/v1/mobile/objects/{$object->id}")
            ->assertOk()
            ->assertJsonPath('tags.0.id', (string) $tag->id);
    }

    #[Test]
    public function cannot_attach_a_tag_that_is_not_visible_to_the_user(): void
    {
        $event = $this->createEvent('Tagged');
        $hidden = Tag::findOrCreate('private-other-user-tag', 'spark');
        $otherObject = EventObject::factory()->create(['user_id' => User::factory()->create()->id]);
        $otherObject->attachTags([$hidden]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/events/{$event->id}/tags", [
            'tag_id' => $hidden->id,
        ], $this->ifMatchEvent($event))->assertNotFound();
    }

    #[Test]
    public function writes_require_the_write_ability(): void
    {
        $event = $this->createEvent('Tagged');
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson("/api/v1/mobile/events/{$event->id}/tags", [
            'name' => 'nope',
        ])->assertForbidden();
    }

    #[Test]
    public function cannot_mutate_another_users_entity(): void
    {
        $other = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $other->id,
            'service' => 'other',
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $other->id,
            'integration_group_id' => $group->id,
            'service' => 'other',
        ]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/events/{$event->id}/tags", [
            'name' => 'nope',
        ])->assertNotFound();
    }

    /** @return array{If-Match: string} */
    private function ifMatchEvent(Event $event): array
    {
        return ['If-Match' => $this->getJson("/api/v1/mobile/events/{$event->id}")->headers->get('ETag')];
    }

    /** @return array{If-Match: string} */
    private function ifMatchObject(EventObject $object): array
    {
        return ['If-Match' => $this->getJson("/api/v1/mobile/objects/{$object->id}")->headers->get('ETag')];
    }

    private function createEvent(string $title): Event
    {
        $target = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'title' => $title,
        ]);

        return Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'test',
            'domain' => 'knowledge',
            'action' => 'created',
            'target_id' => $target->id,
            'time' => now(),
        ]);
    }
}
