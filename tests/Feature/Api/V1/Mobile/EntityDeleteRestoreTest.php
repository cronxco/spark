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
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\MobileSessionAbilities;
use Tests\TestCase;

/**
 * EOB-D5: the app can soft-delete an event or object and undo it.
 */
class EntityDeleteRestoreTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);

        $this->user = User::factory()->create();
        $this->integration = $this->integrationFor($this->user);
    }

    #[Test]
    public function an_event_is_soft_deleted_and_restored(): void
    {
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read', 'data:write']));
        $event = Event::factory()->create(['integration_id' => $this->integration->id, 'service' => 'monzo']);
        $etag = $this->getJson("/api/v1/mobile/events/{$event->id}")->headers->get('ETag');

        $this->deleteJson("/api/v1/mobile/events/{$event->id}", [], ['If-Match' => $etag])
            ->assertOk()
            ->assertJsonPath('id', $event->id)
            ->assertJsonStructure(['id', 'deleted_at']);

        $this->assertSoftDeleted('events', ['id' => $event->id]);
        $this->getJson("/api/v1/mobile/events/{$event->id}")->assertNotFound();

        $this->postJson("/api/v1/mobile/events/{$event->id}/restore")
            ->assertOk()
            ->assertHeader('ETag')
            ->assertJsonPath('id', $event->id);

        $this->assertNotSoftDeleted('events', ['id' => $event->id]);
    }

    #[Test]
    public function an_object_is_soft_deleted_and_restored_with_its_events_kept(): void
    {
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read', 'ios:write']));
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);
        $event = Event::factory()->create(['integration_id' => $this->integration->id, 'service' => 'monzo', 'target_id' => $object->id]);
        $etag = $this->getJson("/api/v1/mobile/objects/{$object->id}")->headers->get('ETag');

        $this->deleteJson("/api/v1/mobile/objects/{$object->id}", [], ['If-Match' => $etag])
            ->assertOk()
            ->assertJsonPath('id', $object->id);

        $this->assertSoftDeleted('objects', ['id' => $object->id]);
        $this->assertNotSoftDeleted('events', ['id' => $event->id]);

        $this->postJson("/api/v1/mobile/objects/{$object->id}/restore")
            ->assertOk()
            ->assertJsonPath('id', $object->id);

        $this->assertNotSoftDeleted('objects', ['id' => $object->id]);
    }

    #[Test]
    public function deleting_needs_a_current_if_match(): void
    {
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read', 'ios:write']));
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        $this->deleteJson("/api/v1/mobile/objects/{$object->id}")->assertStatus(428);
        $this->deleteJson("/api/v1/mobile/objects/{$object->id}", [], ['If-Match' => '"stale"'])->assertStatus(412);

        $this->assertNotSoftDeleted('objects', ['id' => $object->id]);
    }

    #[Test]
    public function restoring_twice_is_harmless(): void
    {
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read', 'ios:write']));
        $event = Event::factory()->create(['integration_id' => $this->integration->id, 'service' => 'monzo']);
        $event->delete();

        $this->postJson("/api/v1/mobile/events/{$event->id}/restore")->assertOk();
        $this->postJson("/api/v1/mobile/events/{$event->id}/restore")->assertOk()->assertJsonPath('id', $event->id);
    }

    #[Test]
    public function another_users_items_cannot_be_deleted_or_restored(): void
    {
        $other = User::factory()->create();
        $otherEvent = Event::factory()->create(['integration_id' => $this->integrationFor($other)->id, 'service' => 'monzo']);
        $otherObject = EventObject::factory()->create(['user_id' => $other->id]);
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read', 'ios:write']));

        $this->deleteJson("/api/v1/mobile/events/{$otherEvent->id}", [], ['If-Match' => '"x"'])->assertNotFound();
        $this->deleteJson("/api/v1/mobile/objects/{$otherObject->id}", [], ['If-Match' => '"x"'])->assertNotFound();

        $otherEvent->delete();
        $otherObject->delete();
        $this->postJson("/api/v1/mobile/events/{$otherEvent->id}/restore")->assertNotFound();
        $this->postJson("/api/v1/mobile/objects/{$otherObject->id}/restore")->assertNotFound();

        $this->assertSoftDeleted('events', ['id' => $otherEvent->id]);
        $this->assertSoftDeleted('objects', ['id' => $otherObject->id]);
    }

    #[Test]
    public function a_read_only_token_cannot_delete_or_restore(): void
    {
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read']));
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        $this->deleteJson("/api/v1/mobile/objects/{$object->id}", [], ['If-Match' => '"x"'])->assertForbidden();
        $this->postJson("/api/v1/mobile/objects/{$object->id}/restore")->assertForbidden();
    }

    #[Test]
    public function a_deleted_event_shows_up_in_the_sync_delta(): void
    {
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read', 'ios:write']));
        $event = Event::factory()->create(['integration_id' => $this->integration->id, 'service' => 'monzo']);
        $since = now()->subMinute()->toIso8601String();
        $etag = $this->getJson("/api/v1/mobile/events/{$event->id}")->headers->get('ETag');

        $this->deleteJson("/api/v1/mobile/events/{$event->id}", [], ['If-Match' => $etag])->assertOk();

        $this->getJson('/api/v1/mobile/sync/delta?since=' . urlencode($since))
            ->assertOk()
            ->assertJsonFragment(['deleted' => [$event->id]]);
    }

    #[Test]
    public function an_objects_media_survives_a_soft_delete_so_undo_brings_it_back(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);
        $object->addMedia($this->tinyImage())->withCustomProperties(['md5_hash' => 'undo_test_hash'])->toMediaCollection('downloaded_images');

        $object->delete();
        $this->assertSame(1, $object->media()->count());

        $object->restore();
        $this->assertCount(1, $object->fresh()->getMedia('downloaded_images'));

        $object->forceDelete();
        $this->assertSame(0, Media::query()->where('model_id', $object->id)->count());
    }

    private function tinyImage(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'test_img') . '.jpg';
        $image = imagecreatetruecolor(1, 1);
        imagejpeg($image, $path);
        imagedestroy($image);

        return $path;
    }

    private function integrationFor(User $user): Integration
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'monzo']);

        return Integration::factory()->create(['user_id' => $user->id, 'integration_group_id' => $group->id, 'service' => 'monzo']);
    }
}
