<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventTaggingUiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_can_add_and_remove_tags_via_event_component_methods(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $event = $this->createEventFor($user);

        $this->actingAs($user);

        Livewire::test('events.show', ['event' => $event])
            ->call('addTag', 'alpha')
            ->call('addTag', 'beta')
            ->call('removeTag', 'alpha');

        $event->refresh();

        $this->assertTrue($event->hasTag('beta'));
        $this->assertFalse($event->hasTag('alpha'));
    }

    #[Test]
    public function it_ignores_marker_values_used_for_whitelist_or_initial(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $event = $this->createEventFor($user);

        $this->actingAs($user);

        Livewire::test('events.show', ['event' => $event])
            ->call('addTag', 'tag-whitelist-123')
            ->call('addTag', 'tag-initial-123')
            ->call('removeTag', 'tag-whitelist-123')
            ->call('removeTag', 'tag-initial-123');

        $event->refresh();
        $this->assertCount(0, $event->tags);
    }

    private function createEventFor(User $user): Event
    {
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        return Event::factory()->create([
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);
    }
}
