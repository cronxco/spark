<?php

namespace Tests\Unit;

use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventObjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_object_has_uuid_as_primary_key(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $eventObject = EventObject::factory()->create(['integration_id' => $integration->id]);

        $this->assertTrue(Str::isUuid($eventObject->id));
    }

    public function test_event_object_id_is_not_auto_incrementing(): void
    {
        $eventObject = new EventObject();

        $this->assertFalse($eventObject->incrementing);
        $this->assertEquals('string', $eventObject->getKeyType());
    }

    public function test_event_object_uuid_is_generated_on_creation(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $eventObject = EventObject::factory()->create(['integration_id' => $integration->id]);

        $this->assertNotNull($eventObject->id);
        $this->assertTrue(Str::isUuid($eventObject->id));
    }

    public function test_event_object_does_not_override_provided_id(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $customId = Str::uuid()->toString();

        $eventObject = EventObject::factory()->create([
            'id' => $customId,
            'integration_id' => $integration->id,
        ]);

        $this->assertEquals($customId, $eventObject->id);
    }

    public function test_event_object_has_fillable_attributes(): void
    {
        $eventObject = new EventObject();
        $fillable = $eventObject->getFillable();

        $expectedFillable = [
            'time', 'integration_id', 'concept', 'type', 'title',
            'content', 'metadata', 'url', 'media_url', 'embeddings',
        ];

        foreach ($expectedFillable as $field) {
            $this->assertContains($field, $fillable);
        }
    }

    public function test_event_object_casts_time_to_datetime(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $eventObject = EventObject::factory()->create(['integration_id' => $integration->id]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $eventObject->time);
    }

    public function test_event_object_casts_metadata_to_array(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $eventObject = EventObject::factory()->create([
            'integration_id' => $integration->id,
            'metadata' => ['key' => 'value', 'nested' => ['data' => 'here']],
        ]);

        $this->assertIsArray($eventObject->metadata);
        $this->assertEquals('value', $eventObject->metadata['key']);
    }

    public function test_event_object_belongs_to_integration(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $eventObject = EventObject::factory()->create(['integration_id' => $integration->id]);

        $this->assertInstanceOf(Integration::class, $eventObject->integration);
        $this->assertEquals($integration->id, $eventObject->integration->id);
    }

    public function test_event_object_uses_objects_table(): void
    {
        $eventObject = new EventObject();

        $this->assertEquals('objects', $eventObject->getTable());
    }

    public function test_multiple_event_objects_have_unique_uuids(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);

        $eventObject1 = EventObject::factory()->create(['integration_id' => $integration->id]);
        $eventObject2 = EventObject::factory()->create(['integration_id' => $integration->id]);

        $this->assertNotEquals($eventObject1->id, $eventObject2->id);
    }

    public function test_event_object_can_store_content(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);

        $eventObject = EventObject::factory()->create([
            'integration_id' => $integration->id,
            'concept' => 'person',
            'type' => 'user',
            'title' => 'John Doe',
            'content' => 'User profile content',
            'url' => 'https://example.com/user/john',
        ]);

        $this->assertEquals('person', $eventObject->concept);
        $this->assertEquals('user', $eventObject->type);
        $this->assertEquals('John Doe', $eventObject->title);
        $this->assertEquals('User profile content', $eventObject->content);
        $this->assertEquals('https://example.com/user/john', $eventObject->url);
    }

    public function test_event_object_can_have_media_url(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);

        $eventObject = EventObject::factory()->create([
            'integration_id' => $integration->id,
            'media_url' => 'https://example.com/avatar.jpg',
        ]);

        $this->assertEquals('https://example.com/avatar.jpg', $eventObject->media_url);
    }
}
