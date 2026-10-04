<?php

namespace Tests\Feature\EventsObjectsBlocks;

use App\Livewire\EditObject;
use App\Models\EventObject;
use App\Models\User;
use App\Services\Api\EntityMutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EOB-04: a locked object's title edit was quietly reverted and reported as saved.
 * EOB-D2/D3: the lock covers every source field (title, concept, type, content, URL).
 */
class LockedObjectEditTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_web_editor_says_a_locked_title_cannot_change(): void
    {
        [$user, $object] = $this->lockedObject();
        $this->actingAs($user);

        Livewire::test(EditObject::class, ['object' => $object])
            ->set('title', 'Renamed')
            ->call('save')
            ->assertHasErrors(['title'])
            ->assertNotDispatched('object-updated');

        $this->assertSame('Original', $object->fresh()->title);
    }

    #[Test]
    public function the_web_editor_rejects_any_source_field_change_on_a_locked_object(): void
    {
        [$user, $object] = $this->lockedObject();
        $this->actingAs($user);

        Livewire::test(EditObject::class, ['object' => $object])
            ->assertSet('sourceFieldsEditable', false)
            ->set('url', 'https://example.com/new')
            ->call('save')
            ->assertHasErrors(['url'])
            ->assertNotDispatched('object-updated');

        $this->assertNull($object->fresh()->url);
    }

    #[Test]
    public function the_api_rejects_a_locked_title_change_instead_of_reporting_success(): void
    {
        config(['ios.mobile_api_enabled' => true]);
        [$user, $object] = $this->lockedObject();
        Sanctum::actingAs($user, ['ios:read', 'ios:write']);

        $etag = $this->getJson("/api/v1/mobile/objects/{$object->id}")->headers->get('ETag');

        $this->patchJson("/api/v1/mobile/objects/{$object->id}", ['title' => 'Renamed'], ['If-Match' => $etag])
            ->assertStatus(422)
            ->assertJsonPath('errors.title.0', EntityMutationService::LOCKED_TITLE_MESSAGE);

        $this->assertSame('Original', $object->fresh()->title);
    }

    #[Test]
    public function an_unlocked_object_can_still_be_renamed(): void
    {
        [$user, $object] = $this->lockedObject();
        $object->unlock();
        $this->actingAs($user);

        Livewire::test(EditObject::class, ['object' => $object->fresh()])
            ->set('title', 'Renamed')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Renamed', $object->fresh()->title);
    }

    /** @return array{User, EventObject} */
    private function lockedObject(): array
    {
        $user = User::factory()->create();
        $object = EventObject::factory()->create(['user_id' => $user->id, 'title' => 'Original', 'url' => null]);
        $object->lock();

        return [$user, $object->fresh()];
    }
}
