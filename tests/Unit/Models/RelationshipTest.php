<?php

namespace Tests\Unit\Models;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RelationshipTest extends TestCase
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
        $event1 = Event::factory()->create(['integration_id' => $this->integration->id]);
        $event2 = Event::factory()->create(['integration_id' => $this->integration->id]);

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'linked_to',
        ]);

        $this->assertNotNull($relationship->id);
        $this->assertTrue(Str::isUuid($relationship->id));
    }

    #[Test]
    public function it_belongs_to_a_user(): void
    {
        $event1 = Event::factory()->create(['integration_id' => $this->integration->id]);
        $event2 = Event::factory()->create(['integration_id' => $this->integration->id]);

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'linked_to',
        ]);

        $this->assertInstanceOf(User::class, $relationship->user);
        $this->assertEquals($this->user->id, $relationship->user->id);
    }

    #[Test]
    public function it_has_polymorphic_from_relationship(): void
    {
        $event = Event::factory()->create(['integration_id' => $this->integration->id]);
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event->id,
            'to_type' => EventObject::class,
            'to_id' => $object->id,
            'type' => 'linked_to',
        ]);

        $this->assertInstanceOf(Event::class, $relationship->from);
        $this->assertEquals($event->id, $relationship->from->id);
    }

    #[Test]
    public function it_has_polymorphic_to_relationship(): void
    {
        $event = Event::factory()->create(['integration_id' => $this->integration->id]);
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event->id,
            'to_type' => EventObject::class,
            'to_id' => $object->id,
            'type' => 'linked_to',
        ]);

        $this->assertInstanceOf(EventObject::class, $relationship->to);
        $this->assertEquals($object->id, $relationship->to->id);
    }

    #[Test]
    public function it_calculates_formatted_value(): void
    {
        $event1 = Event::factory()->create(['integration_id' => $this->integration->id]);
        $event2 = Event::factory()->create(['integration_id' => $this->integration->id]);

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'transferred_to',
            'value' => 10000,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
        ]);

        $this->assertEquals(100.0, $relationship->formatted_value);
    }

    #[Test]
    public function formatted_value_returns_null_when_value_is_null(): void
    {
        $event1 = Event::factory()->create(['integration_id' => $this->integration->id]);
        $event2 = Event::factory()->create(['integration_id' => $this->integration->id]);

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'linked_to',
            'value' => null,
        ]);

        $this->assertNull($relationship->formatted_value);
    }

    #[Test]
    public function it_uses_soft_deletes(): void
    {
        $event1 = Event::factory()->create(['integration_id' => $this->integration->id]);
        $event2 = Event::factory()->create(['integration_id' => $this->integration->id]);

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'linked_to',
        ]);

        $relationship->delete();

        $this->assertSoftDeleted('relationships', ['id' => $relationship->id]);
    }

    #[Test]
    public function create_relationship_prevents_duplicate_bidirectional(): void
    {
        $object1 = EventObject::factory()->create(['user_id' => $this->user->id]);
        $object2 = EventObject::factory()->create(['user_id' => $this->user->id]);

        // Create first relationship
        $rel1 = Relationship::createRelationship([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object1->id,
            'to_type' => EventObject::class,
            'to_id' => $object2->id,
            'type' => 'related_to', // bi-directional
        ]);

        // Try to create reverse relationship - should return existing
        $rel2 = Relationship::createRelationship([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object2->id,
            'to_type' => EventObject::class,
            'to_id' => $object1->id,
            'type' => 'related_to',
        ]);

        $this->assertEquals($rel1->id, $rel2->id);
    }

    #[Test]
    public function create_relationship_allows_duplicate_directional(): void
    {
        $object1 = EventObject::factory()->create(['user_id' => $this->user->id]);
        $object2 = EventObject::factory()->create(['user_id' => $this->user->id]);

        // Create first directional relationship
        $rel1 = Relationship::createRelationship([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object1->id,
            'to_type' => EventObject::class,
            'to_id' => $object2->id,
            'type' => 'linked_to', // directional
        ]);

        // Create reverse directional relationship - should create new
        $rel2 = Relationship::createRelationship([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $object2->id,
            'to_type' => EventObject::class,
            'to_id' => $object1->id,
            'type' => 'linked_to',
        ]);

        $this->assertNotEquals($rel1->id, $rel2->id);
    }

    #[Test]
    public function is_directional_returns_correct_value(): void
    {
        $event1 = Event::factory()->create(['integration_id' => $this->integration->id]);
        $event2 = Event::factory()->create(['integration_id' => $this->integration->id]);

        $directional = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'linked_to',
        ]);

        $bidirectional = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'related_to',
        ]);

        $this->assertTrue($directional->isDirectional());
        $this->assertFalse($bidirectional->isDirectional());
    }

    #[Test]
    public function get_type_config_returns_registry_data(): void
    {
        $event1 = Event::factory()->create(['integration_id' => $this->integration->id]);
        $event2 = Event::factory()->create(['integration_id' => $this->integration->id]);

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'linked_to',
        ]);

        $config = $relationship->getTypeConfig();

        $this->assertIsArray($config);
        $this->assertArrayHasKey('display_name', $config);
        $this->assertArrayHasKey('icon', $config);
        $this->assertArrayHasKey('is_directional', $config);
    }

    #[Test]
    public function pending_relationships_are_identified(): void
    {
        $event1 = Event::factory()->create(['integration_id' => $this->integration->id]);
        $event2 = Event::factory()->create(['integration_id' => $this->integration->id]);

        $pendingRel = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'linked_to',
            'metadata' => ['pending' => true, 'confidence' => 0.85],
        ]);

        $confirmedRel = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'related_to',
            'metadata' => [],
        ]);

        $this->assertTrue($pendingRel->isPending());
        $this->assertFalse($pendingRel->isConfirmed());
        $this->assertFalse($confirmedRel->isPending());
        $this->assertTrue($confirmedRel->isConfirmed());
    }

    #[Test]
    public function pending_relationship_can_be_approved(): void
    {
        $event1 = Event::factory()->create(['integration_id' => $this->integration->id]);
        $event2 = Event::factory()->create(['integration_id' => $this->integration->id]);

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'linked_to',
            'metadata' => ['pending' => true],
        ]);

        $this->assertTrue($relationship->isPending());

        $relationship->approve();
        $relationship->refresh();

        $this->assertFalse($relationship->isPending());
        $this->assertArrayHasKey('approved_at', $relationship->metadata);
    }

    #[Test]
    public function pending_relationship_can_be_rejected(): void
    {
        $event1 = Event::factory()->create(['integration_id' => $this->integration->id]);
        $event2 = Event::factory()->create(['integration_id' => $this->integration->id]);

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'linked_to',
            'metadata' => ['pending' => true],
        ]);

        $relationship->reject();

        $this->assertSoftDeleted('relationships', ['id' => $relationship->id]);
    }

    #[Test]
    public function get_confidence_returns_value_from_metadata(): void
    {
        $event1 = Event::factory()->create(['integration_id' => $this->integration->id]);
        $event2 = Event::factory()->create(['integration_id' => $this->integration->id]);

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'linked_to',
            'metadata' => ['pending' => true, 'confidence' => 0.92],
        ]);

        $this->assertEquals(0.92, $relationship->getConfidence());
    }

    #[Test]
    public function get_detection_strategy_returns_value_from_metadata(): void
    {
        $event1 = Event::factory()->create(['integration_id' => $this->integration->id]);
        $event2 = Event::factory()->create(['integration_id' => $this->integration->id]);

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'linked_to',
            'metadata' => ['detection_strategy' => 'explicit_reference'],
        ]);

        $this->assertEquals('explicit_reference', $relationship->getDetectionStrategy());
    }

    #[Test]
    public function it_casts_metadata_to_array(): void
    {
        $event1 = Event::factory()->create(['integration_id' => $this->integration->id]);
        $event2 = Event::factory()->create(['integration_id' => $this->integration->id]);

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'linked_to',
            'metadata' => ['key' => 'value', 'nested' => ['a' => 1]],
        ]);

        $this->assertIsArray($relationship->metadata);
        $this->assertEquals('value', $relationship->metadata['key']);
        $this->assertEquals(1, $relationship->metadata['nested']['a']);
    }

    #[Test]
    public function default_value_multiplier_is_one(): void
    {
        $event1 = Event::factory()->create(['integration_id' => $this->integration->id]);
        $event2 = Event::factory()->create(['integration_id' => $this->integration->id]);

        $relationship = Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'linked_to',
        ]);

        $this->assertEquals(1, $relationship->value_multiplier);
    }

    #[Test]
    public function scope_between_events_finds_relationships_either_direction(): void
    {
        $event1 = Event::factory()->create(['integration_id' => $this->integration->id]);
        $event2 = Event::factory()->create(['integration_id' => $this->integration->id]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'linked_to',
        ]);

        // Query in both directions should find the relationship
        $result1 = Relationship::betweenEvents($event1->id, $event2->id)->first();
        $result2 = Relationship::betweenEvents($event2->id, $event1->id)->first();

        $this->assertNotNull($result1);
        $this->assertNotNull($result2);
        $this->assertEquals($result1->id, $result2->id);
    }
}
