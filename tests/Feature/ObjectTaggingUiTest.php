<?php

namespace Tests\Feature;

use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ObjectTaggingUiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_can_add_and_remove_tags_via_object_component_methods(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        // Ensure user has an integration to satisfy related events queries guards
        Integration::factory()->create(['user_id' => $user->id]);
        $object = $this->createObjectFor($user);

        $this->actingAs($user);

        Livewire::test('objects.show', ['object' => $object])
            ->call('addTag', 'alpha')
            ->call('addTag', 'beta')
            ->call('removeTag', 'alpha');

        $object->refresh();

        $this->assertTrue($object->hasTag('beta'));
        $this->assertFalse($object->hasTag('alpha'));
    }

    #[Test]
    public function it_ignores_marker_values_used_for_whitelist_or_initial(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        Integration::factory()->create(['user_id' => $user->id]);
        $object = $this->createObjectFor($user);

        $this->actingAs($user);

        Livewire::test('objects.show', ['object' => $object])
            ->call('addTag', 'tag-whitelist-123')
            ->call('addTag', 'tag-initial-123')
            ->call('removeTag', 'tag-whitelist-123')
            ->call('removeTag', 'tag-initial-123');

        $object->refresh();
        $this->assertCount(0, $object->tags);
    }

    private function createObjectFor(User $user): EventObject
    {
        return EventObject::factory()->create(['user_id' => $user->id]);
    }
}
