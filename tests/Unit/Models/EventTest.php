<?php

namespace Tests\Unit\Models;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Tags\Tag;
use Tests\TestCase;

class EventTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private IntegrationGroup $group;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
        ]);
    }

    #[Test]
    public function it_generates_uuid_on_creation(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        $this->assertNotNull($event->id);
        $this->assertTrue(Str::isUuid($event->id));
    }

    #[Test]
    public function it_belongs_to_an_integration(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        $this->assertInstanceOf(Integration::class, $event->integration);
        $this->assertEquals($this->integration->id, $event->integration->id);
    }

    #[Test]
    public function it_belongs_to_an_actor(): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);

        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
        ]);

        $this->assertInstanceOf(EventObject::class, $event->actor);
        $this->assertEquals($actor->id, $event->actor->id);
    }

    #[Test]
    public function it_belongs_to_a_target(): void
    {
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
        ]);

        $this->assertInstanceOf(EventObject::class, $event->target);
        $this->assertEquals($target->id, $event->target->id);
    }

    #[Test]
    public function it_has_many_blocks(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        Block::factory()->count(3)->create(['event_id' => $event->id]);

        $this->assertCount(3, $event->blocks);
        $this->assertInstanceOf(Block::class, $event->blocks->first());
    }

    #[Test]
    public function it_calculates_formatted_value_with_multiplier(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'value' => 10000,
            'value_multiplier' => 100,
        ]);

        $this->assertEquals(100, $event->formatted_value);
    }

    #[Test]
    public function it_returns_raw_value_when_multiplier_is_one(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'value' => 100,
            'value_multiplier' => 1,
        ]);

        $this->assertEquals(100, $event->formatted_value);
    }

    #[Test]
    public function it_returns_raw_value_when_multiplier_is_zero(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'value' => 100,
            'value_multiplier' => 0,
        ]);

        $this->assertEquals(100, $event->formatted_value);
    }

    #[Test]
    public function it_returns_null_when_value_is_null(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'value' => null,
            'value_multiplier' => 100,
        ]);

        $this->assertNull($event->formatted_value);
    }

    #[Test]
    public function it_creates_blocks_without_duplicates(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        // Create block first time
        $block1 = $event->createBlock([
            'title' => 'Test Block',
            'block_type' => 'test_type',
            'value' => 100,
        ]);

        // Create same block again (should update, not create new)
        $block2 = $event->createBlock([
            'title' => 'Test Block',
            'block_type' => 'test_type',
            'value' => 200,
        ]);

        $this->assertEquals($block1->id, $block2->id);
        $this->assertEquals(200, $block2->value);
        $this->assertCount(1, $event->refresh()->blocks);
    }

    #[Test]
    public function it_has_relationships_from(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        $otherEvent = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event->id,
            'to_type' => Event::class,
            'to_id' => $otherEvent->id,
            'type' => 'linked_to',
        ]);

        $this->assertCount(1, $event->relationshipsFrom);
    }

    #[Test]
    public function it_has_relationships_to(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        $otherEvent = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $otherEvent->id,
            'to_type' => Event::class,
            'to_id' => $event->id,
            'type' => 'linked_to',
        ]);

        $this->assertCount(1, $event->relationshipsTo);
    }

    #[Test]
    public function it_gets_all_relationships(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        $event2 = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        $event3 = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'linked_to',
        ]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event3->id,
            'to_type' => Event::class,
            'to_id' => $event->id,
            'type' => 'related_to',
        ]);

        $allRelationships = $event->allRelationships()->get();
        $this->assertCount(2, $allRelationships);
    }

    #[Test]
    public function it_gets_related_events(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        $relatedEvent = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event->id,
            'to_type' => Event::class,
            'to_id' => $relatedEvent->id,
            'type' => 'linked_to',
        ]);

        $related = $event->relatedEvents()->get();
        $this->assertCount(1, $related);
        $this->assertEquals($relatedEvent->id, $related->first()->id);
    }

    #[Test]
    public function it_gets_related_events_by_type(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        $linkedEvent = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        $relatedEvent = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event->id,
            'to_type' => Event::class,
            'to_id' => $linkedEvent->id,
            'type' => 'linked_to',
        ]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event->id,
            'to_type' => Event::class,
            'to_id' => $relatedEvent->id,
            'type' => 'related_to',
        ]);

        $linked = $event->relatedEvents('linked_to')->get();
        $this->assertCount(1, $linked);
        $this->assertEquals($linkedEvent->id, $linked->first()->id);
    }

    #[Test]
    public function it_gets_related_objects(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event->id,
            'to_type' => EventObject::class,
            'to_id' => $object->id,
            'type' => 'linked_to',
        ]);

        $related = $event->relatedObjects()->get();
        $this->assertCount(1, $related);
        $this->assertEquals($object->id, $related->first()->id);
    }

    #[Test]
    public function it_gets_related_blocks(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        $block = Block::factory()->create(['event_id' => $event->id]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event->id,
            'to_type' => Block::class,
            'to_id' => $block->id,
            'type' => 'linked_to',
        ]);

        $related = $event->relatedBlocks()->get();
        $this->assertCount(1, $related);
        $this->assertEquals($block->id, $related->first()->id);
    }

    #[Test]
    public function it_generates_searchable_text(): void
    {
        $actor = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'John Smith',
        ]);

        $target = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Coffee Shop',
        ]);

        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
            'action' => 'made_transaction',
            'value' => 5.00,
            'value_multiplier' => 1,
            'value_unit' => 'GBP',
            'domain' => 'money',
            'service' => 'monzo',
        ]);

        $searchableText = $event->getSearchableText();

        $this->assertStringContainsString('John Smith', $searchableText);
        $this->assertStringContainsString('Made Transaction', $searchableText);
        $this->assertStringContainsString('Coffee Shop', $searchableText);
        $this->assertStringContainsString('5 GBP', $searchableText);
        $this->assertStringContainsString('money', $searchableText);
        $this->assertStringContainsString('monzo', $searchableText);
    }

    #[Test]
    public function it_uses_soft_deletes(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        $event->delete();

        $this->assertSoftDeleted('events', ['id' => $event->id]);
        $this->assertNotNull(Event::withTrashed()->find($event->id));
    }

    #[Test]
    public function it_can_attach_tags(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        $event->attachTag(Tag::findOrCreate('important', 'spark'));

        $this->assertTrue($event->refresh()->hasTag('important'));
    }

    #[Test]
    public function it_can_detach_tags(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        $tag = Tag::findOrCreate('important', 'spark');
        $event->attachTag($tag);
        $event->detachTag($tag);

        $this->assertFalse($event->refresh()->hasTag('important'));
    }

    #[Test]
    public function objects_method_returns_actor_and_target(): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        $objects = $event->objects()->get();

        $this->assertCount(2, $objects);
        $this->assertContains($actor->id, $objects->pluck('id'));
        $this->assertContains($target->id, $objects->pluck('id'));
    }

    #[Test]
    public function it_casts_metadata_fields_to_arrays(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'actor_metadata' => ['key' => 'value'],
            'event_metadata' => ['data' => 123],
            'target_metadata' => ['info' => 'test'],
        ]);

        $this->assertIsArray($event->actor_metadata);
        $this->assertIsArray($event->event_metadata);
        $this->assertIsArray($event->target_metadata);
        $this->assertEquals('value', $event->actor_metadata['key']);
        $this->assertEquals(123, $event->event_metadata['data']);
        $this->assertEquals('test', $event->target_metadata['info']);
    }

    #[Test]
    public function it_casts_time_to_datetime(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'time' => '2024-06-15 10:30:00',
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $event->time);
        $this->assertEquals('2024-06-15', $event->time->format('Y-m-d'));
    }
}
