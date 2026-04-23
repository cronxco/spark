<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Relationship;
use App\Models\User;
use App\Services\RelationshipTypeRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationshipTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->integration = Integration::factory()->create(['user_id' => $this->user->id]);
    }

    /** @test */
    public function it_can_create_a_directional_relationship(): void
    {
        $object1 = EventObject::factory()->create(['user_id' => $this->user->id]);
        $object2 = EventObject::factory()->create(['user_id' => $this->user->id]);

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object1->id,
            'to_type' => EventObject::class,
            'to_id' => $object2->id,
            'type' => 'linked_to',
        ]);

        $this->assertDatabaseHas('relationships', [
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object1->id,
            'to_type' => EventObject::class,
            'to_id' => $object2->id,
            'type' => 'linked_to',
        ]);

        $this->assertTrue($relationship->isDirectional());
    }

    /** @test */
    public function it_can_create_a_bidirectional_relationship(): void
    {
        $object1 = EventObject::factory()->create(['user_id' => $this->user->id]);
        $object2 = EventObject::factory()->create(['user_id' => $this->user->id]);

        $relationship = Relationship::createRelationship([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object1->id,
            'to_type' => EventObject::class,
            'to_id' => $object2->id,
            'type' => 'related_to',
        ]);

        $this->assertFalse($relationship->isDirectional());

        // Try to create reverse relationship - should return existing one
        $reverseRelationship = Relationship::createRelationship([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object2->id,
            'to_type' => EventObject::class,
            'to_id' => $object1->id,
            'type' => 'related_to',
        ]);

        $this->assertEquals($relationship->id, $reverseRelationship->id);
        $this->assertEquals(1, Relationship::count());
    }

    /** @test */
    public function it_prevents_duplicate_directional_relationships(): void
    {
        $object1 = EventObject::factory()->create(['user_id' => $this->user->id]);
        $object2 = EventObject::factory()->create(['user_id' => $this->user->id]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object1->id,
            'to_type' => EventObject::class,
            'to_id' => $object2->id,
            'type' => 'linked_to',
        ]);

        $this->expectException(QueryException::class);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object1->id,
            'to_type' => EventObject::class,
            'to_id' => $object2->id,
            'type' => 'linked_to',
        ]);
    }

    /** @test */
    public function it_can_relate_different_model_types(): void
    {
        $event = Event::factory()->create(['integration_id' => $this->integration->id]);
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event->id,
            'to_type' => EventObject::class,
            'to_id' => $object->id,
            'type' => 'caused_by',
        ]);

        $this->assertEquals(Event::class, $relationship->from_type);
        $this->assertEquals(EventObject::class, $relationship->to_type);
        $this->assertInstanceOf(Event::class, $relationship->from);
        $this->assertInstanceOf(EventObject::class, $relationship->to);
    }

    /** @test */
    public function it_supports_value_fields_for_monetary_relationships(): void
    {
        $object1 = EventObject::factory()->create(['user_id' => $this->user->id]);
        $object2 = EventObject::factory()->create(['user_id' => $this->user->id]);

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object1->id,
            'to_type' => EventObject::class,
            'to_id' => $object2->id,
            'type' => 'transferred_to',
            'value' => 10000, // £100.00 in pence
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
        ]);

        $this->assertEquals(10000, $relationship->value);
        $this->assertEquals(100, $relationship->value_multiplier);
        $this->assertEquals('GBP', $relationship->value_unit);
        $this->assertEquals(100.0, $relationship->formatted_value);
    }

    /** @test */
    public function it_stores_metadata(): void
    {
        $object1 = EventObject::factory()->create(['user_id' => $this->user->id]);
        $object2 = EventObject::factory()->create(['user_id' => $this->user->id]);

        $metadata = [
            'url' => 'https://example.com',
            'linked_at' => now()->toIso8601String(),
            'custom_field' => 'value',
        ];

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object1->id,
            'to_type' => EventObject::class,
            'to_id' => $object2->id,
            'type' => 'linked_to',
            'metadata' => $metadata,
        ]);

        $this->assertEquals($metadata, $relationship->metadata);
    }

    /** @test */
    public function it_soft_deletes_relationships(): void
    {
        $object1 = EventObject::factory()->create(['user_id' => $this->user->id]);
        $object2 = EventObject::factory()->create(['user_id' => $this->user->id]);

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object1->id,
            'to_type' => EventObject::class,
            'to_id' => $object2->id,
            'type' => 'linked_to',
        ]);

        $relationship->delete();

        $this->assertSoftDeleted('relationships', ['id' => $relationship->id]);
        $this->assertNotNull($relationship->fresh()->deleted_at);
    }

    /** @test */
    public function event_can_access_relationships(): void
    {
        $event = Event::factory()->create(['integration_id' => $this->integration->id]);
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event->id,
            'to_type' => EventObject::class,
            'to_id' => $object->id,
            'type' => 'linked_to',
        ]);

        $this->assertEquals(1, $event->relationshipsFrom()->count());
        $this->assertEquals(0, $event->relationshipsTo()->count());
        $this->assertEquals(1, $event->allRelationships()->count());
    }

    /** @test */
    public function event_object_can_access_relationships(): void
    {
        $object1 = EventObject::factory()->create(['user_id' => $this->user->id]);
        $object2 = EventObject::factory()->create(['user_id' => $this->user->id]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object1->id,
            'to_type' => EventObject::class,
            'to_id' => $object2->id,
            'type' => 'linked_to',
        ]);

        $this->assertEquals(1, $object1->relationshipsFrom()->count());
        $this->assertEquals(1, $object2->relationshipsTo()->count());
    }

    /** @test */
    public function block_can_access_relationships(): void
    {
        $event = Event::factory()->create(['integration_id' => $this->integration->id]);
        $block = Block::factory()->create(['event_id' => $event->id]);
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Block::class,
            'from_id' => $block->id,
            'to_type' => EventObject::class,
            'to_id' => $object->id,
            'type' => 'part_of',
        ]);

        $this->assertEquals(1, $block->relationshipsFrom()->count());
    }

    /** @test */
    public function it_can_query_related_objects(): void
    {
        $object1 = EventObject::factory()->create(['user_id' => $this->user->id, 'title' => 'Object 1']);
        $object2 = EventObject::factory()->create(['user_id' => $this->user->id, 'title' => 'Object 2']);
        $object3 = EventObject::factory()->create(['user_id' => $this->user->id, 'title' => 'Object 3']);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object1->id,
            'to_type' => EventObject::class,
            'to_id' => $object2->id,
            'type' => 'linked_to',
        ]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object1->id,
            'to_type' => EventObject::class,
            'to_id' => $object3->id,
            'type' => 'related_to',
        ]);

        $relatedObjects = $object1->relatedObjects()->get();
        $this->assertEquals(2, $relatedObjects->count());

        $linkedObjects = $object1->relatedObjects('linked_to')->get();
        $this->assertEquals(1, $linkedObjects->count());
        $this->assertEquals('Object 2', $linkedObjects->first()->title);
    }

    /** @test */
    public function relationship_type_registry_returns_correct_config(): void
    {
        $this->assertTrue(RelationshipTypeRegistry::typeExists('linked_to'));
        $this->assertTrue(RelationshipTypeRegistry::typeExists('transferred_to'));
        $this->assertFalse(RelationshipTypeRegistry::typeExists('nonexistent'));

        $this->assertTrue(RelationshipTypeRegistry::isDirectional('linked_to'));
        $this->assertFalse(RelationshipTypeRegistry::isDirectional('related_to'));

        $this->assertTrue(RelationshipTypeRegistry::supportsValue('transferred_to'));
        $this->assertFalse(RelationshipTypeRegistry::supportsValue('linked_to'));

        $this->assertEquals('GBP', RelationshipTypeRegistry::getDefaultValueUnit('transferred_to'));
        $this->assertNull(RelationshipTypeRegistry::getDefaultValueUnit('linked_to'));
    }

    /** @test */
    public function it_gets_type_config_from_relationship_instance(): void
    {
        $object1 = EventObject::factory()->create(['user_id' => $this->user->id]);
        $object2 = EventObject::factory()->create(['user_id' => $this->user->id]);

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object1->id,
            'to_type' => EventObject::class,
            'to_id' => $object2->id,
            'type' => 'linked_to',
        ]);

        $config = $relationship->getTypeConfig();
        $this->assertNotNull($config);
        $this->assertEquals('Linked To', $config['display_name']);
        $this->assertEquals('fas.link', $config['icon']);
        $this->assertTrue($config['is_directional']);
    }
}
