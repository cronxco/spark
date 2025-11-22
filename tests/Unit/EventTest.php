<?php

namespace Tests\Unit;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_has_uuid_as_primary_key(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);

        $this->assertTrue(Str::isUuid($event->id));
    }

    public function test_event_id_is_not_auto_incrementing(): void
    {
        $event = new Event();

        $this->assertFalse($event->incrementing);
        $this->assertEquals('string', $event->getKeyType());
    }

    public function test_event_uuid_is_generated_on_creation(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);

        $this->assertNotNull($event->id);
        $this->assertTrue(Str::isUuid($event->id));
    }

    public function test_event_does_not_override_provided_id(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $customId = Str::uuid()->toString();

        $event = Event::factory()->create([
            'id' => $customId,
            'integration_id' => $integration->id,
        ]);

        $this->assertEquals($customId, $event->id);
    }

    public function test_event_has_fillable_attributes(): void
    {
        $event = new Event();
        $fillable = $event->getFillable();

        $expectedFillable = [
            'source_id', 'time', 'integration_id', 'actor_id', 'actor_metadata',
            'service', 'domain', 'action', 'value', 'value_multiplier', 'value_unit',
            'event_metadata', 'target_id', 'target_metadata', 'embeddings',
        ];

        foreach ($expectedFillable as $field) {
            $this->assertContains($field, $fillable);
        }
    }

    public function test_event_casts_time_to_datetime(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $event->time);
    }

    public function test_event_casts_metadata_to_array(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'actor_metadata' => ['key' => 'value'],
            'event_metadata' => ['foo' => 'bar'],
            'target_metadata' => ['baz' => 'qux'],
        ]);

        $this->assertIsArray($event->actor_metadata);
        $this->assertIsArray($event->event_metadata);
        $this->assertIsArray($event->target_metadata);
    }

    public function test_event_belongs_to_integration(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);

        $this->assertInstanceOf(Integration::class, $event->integration);
        $this->assertEquals($integration->id, $event->integration->id);
    }

    public function test_event_belongs_to_actor(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $actor = EventObject::factory()->create(['integration_id' => $integration->id]);
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
        ]);

        $this->assertInstanceOf(EventObject::class, $event->actor);
        $this->assertEquals($actor->id, $event->actor->id);
    }

    public function test_event_belongs_to_target(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['integration_id' => $integration->id]);
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'target_id' => $target->id,
        ]);

        $this->assertInstanceOf(EventObject::class, $event->target);
        $this->assertEquals($target->id, $event->target->id);
    }

    public function test_event_has_many_blocks(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);

        Block::factory()->count(3)->create([
            'event_id' => $event->id,
            'integration_id' => $integration->id,
        ]);

        $this->assertCount(3, $event->blocks);
        $this->assertInstanceOf(Block::class, $event->blocks->first());
    }

    public function test_event_can_have_no_blocks(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);

        $this->assertCount(0, $event->blocks);
    }

    public function test_event_uses_events_table(): void
    {
        $event = new Event();

        $this->assertEquals('events', $event->getTable());
    }

    public function test_multiple_events_have_unique_uuids(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event1 = Event::factory()->create(['integration_id' => $integration->id]);
        $event2 = Event::factory()->create(['integration_id' => $integration->id]);

        $this->assertNotEquals($event1->id, $event2->id);
    }
}
