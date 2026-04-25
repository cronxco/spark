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
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Tags\Tag;
use Tests\TestCase;

class EventObjectTest extends TestCase
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
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        $this->assertNotNull($object->id);
        $this->assertTrue(Str::isUuid($object->id));
    }

    #[Test]
    public function it_belongs_to_a_user(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        $this->assertInstanceOf(User::class, $object->user);
        $this->assertEquals($this->user->id, $object->user->id);
    }

    #[Test]
    public function it_requires_user_id(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        $this->assertEquals($this->user->id, $object->user_id);
    }

    #[Test]
    public function it_has_actor_events(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        Event::factory()->count(3)->create([
            'integration_id' => $this->integration->id,
            'actor_id' => $object->id,
        ]);

        $this->assertCount(3, $object->actorEvents);
    }

    #[Test]
    public function it_has_target_events(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        Event::factory()->count(2)->create([
            'integration_id' => $this->integration->id,
            'target_id' => $object->id,
        ]);

        $this->assertCount(2, $object->targetEvents);
    }

    #[Test]
    public function it_uses_soft_deletes(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        $object->delete();

        $this->assertSoftDeleted('objects', ['id' => $object->id]);
        $this->assertNotNull(EventObject::withTrashed()->find($object->id));
    }

    #[Test]
    public function it_casts_metadata_to_array(): void
    {
        $object = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'metadata' => ['key' => 'value', 'nested' => ['a' => 1]],
        ]);

        $this->assertIsArray($object->metadata);
        $this->assertEquals('value', $object->metadata['key']);
        $this->assertEquals(1, $object->metadata['nested']['a']);
    }

    #[Test]
    public function it_has_relationships_from(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);
        $otherObject = EventObject::factory()->create(['user_id' => $this->user->id]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object->id,
            'to_type' => EventObject::class,
            'to_id' => $otherObject->id,
            'type' => 'linked_to',
        ]);

        $this->assertCount(1, $object->relationshipsFrom);
    }

    #[Test]
    public function it_has_relationships_to(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);
        $otherObject = EventObject::factory()->create(['user_id' => $this->user->id]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $otherObject->id,
            'to_type' => EventObject::class,
            'to_id' => $object->id,
            'type' => 'linked_to',
        ]);

        $this->assertCount(1, $object->relationshipsTo);
    }

    #[Test]
    public function it_gets_all_relationships(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);
        $object2 = EventObject::factory()->create(['user_id' => $this->user->id]);
        $object3 = EventObject::factory()->create(['user_id' => $this->user->id]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object->id,
            'to_type' => EventObject::class,
            'to_id' => $object2->id,
            'type' => 'linked_to',
        ]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object3->id,
            'to_type' => EventObject::class,
            'to_id' => $object->id,
            'type' => 'related_to',
        ]);

        $allRelationships = $object->allRelationships()->get();
        $this->assertCount(2, $allRelationships);
    }

    #[Test]
    public function it_gets_related_objects(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);
        $relatedObject = EventObject::factory()->create(['user_id' => $this->user->id]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object->id,
            'to_type' => EventObject::class,
            'to_id' => $relatedObject->id,
            'type' => 'linked_to',
        ]);

        $related = $object->relatedObjects()->get();
        $this->assertCount(1, $related);
        $this->assertEquals($relatedObject->id, $related->first()->id);
    }

    #[Test]
    public function it_gets_related_events(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object->id,
            'to_type' => Event::class,
            'to_id' => $event->id,
            'type' => 'linked_to',
        ]);

        $related = $object->relatedEvents()->get();
        $this->assertCount(1, $related);
        $this->assertEquals($event->id, $related->first()->id);
    }

    #[Test]
    public function it_gets_related_blocks(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);
        $block = Block::factory()->create(['event_id' => $event->id]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object->id,
            'to_type' => Block::class,
            'to_id' => $block->id,
            'type' => 'linked_to',
        ]);

        $related = $object->relatedBlocks()->get();
        $this->assertCount(1, $related);
        $this->assertEquals($block->id, $related->first()->id);
    }

    #[Test]
    public function it_generates_searchable_text(): void
    {
        $object = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'person',
            'type' => 'contact',
            'title' => 'John Smith',
            'content' => 'A test contact',
            'url' => 'https://example.com/john',
        ]);

        $searchableText = $object->getSearchableText();

        $this->assertStringContainsString('person', $searchableText);
        $this->assertStringContainsString('contact', $searchableText);
        $this->assertStringContainsString('John Smith', $searchableText);
        $this->assertStringContainsString('A test contact', $searchableText);
        $this->assertStringContainsString('https://example.com/john', $searchableText);
    }

    #[Test]
    public function it_can_be_locked(): void
    {
        $object = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Original Title',
        ]);

        $this->assertFalse($object->isLocked());

        $object->lock();
        $object->refresh();

        $this->assertTrue($object->isLocked());
        $this->assertNotNull($object->metadata['locked_at']);
    }

    #[Test]
    public function it_can_be_unlocked(): void
    {
        $object = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'metadata' => ['locked' => true],
        ]);

        $this->assertTrue($object->isLocked());

        $object->unlock();
        $object->refresh();

        $this->assertFalse($object->isLocked());
    }

    #[Test]
    public function locked_object_prevents_title_updates(): void
    {
        $object = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Original Title',
        ]);

        $object->lock();
        $object->refresh();

        $object->title = 'New Title';
        $object->save();
        $object->refresh();

        $this->assertEquals('Original Title', $object->title);
    }

    #[Test]
    public function locked_object_prevents_content_updates(): void
    {
        $object = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'content' => 'Original Content',
        ]);

        $object->lock();
        $object->refresh();

        $object->content = 'New Content';
        $object->save();
        $object->refresh();

        $this->assertEquals('Original Content', $object->content);
    }

    #[Test]
    public function it_can_attach_tags(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        $object->attachTag(Tag::findOrCreate('important', 'spark'));

        $this->assertTrue($object->refresh()->hasTag('important'));
    }

    #[Test]
    public function it_can_detach_tags(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        $tag = Tag::findOrCreate('important', 'spark');
        $object->attachTag($tag);
        $object->detachTag($tag);

        $this->assertFalse($object->refresh()->hasTag('important'));
    }

    #[Test]
    public function it_casts_time_to_datetime(): void
    {
        $object = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'time' => '2024-06-15 10:30:00',
        ]);

        $this->assertInstanceOf(Carbon::class, $object->time);
        $this->assertEquals('2024-06-15', $object->time->format('Y-m-d'));
    }

    #[Test]
    public function embeddings_are_converted_to_array_on_get(): void
    {
        $object = EventObject::factory()->create([
            'user_id' => $this->user->id,
        ]);

        // Set embeddings as array (1536 dimensions required by database)
        $testEmbeddings = array_fill(0, 1536, 0.0);
        $testEmbeddings[0] = 0.1;
        $testEmbeddings[1] = 0.2;
        $testEmbeddings[2] = 0.3;

        $object->embeddings = $testEmbeddings;
        $object->save();
        $object->refresh();

        $embeddings = $object->embeddings;

        $this->assertIsArray($embeddings);
        $this->assertCount(1536, $embeddings);
        $this->assertEquals(0.1, $embeddings[0]);
        $this->assertEquals(0.2, $embeddings[1]);
        $this->assertEquals(0.3, $embeddings[2]);
    }

    #[Test]
    public function embeddings_null_returns_null(): void
    {
        $object = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'embeddings' => null,
        ]);

        $this->assertNull($object->embeddings);
    }
}
