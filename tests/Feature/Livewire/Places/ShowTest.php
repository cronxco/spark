<?php

namespace Tests\Feature\Livewire\Places;

use App\Livewire\Places\Show;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Place;
use App\Models\Relationship;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Place $place;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'test',
        ]);

        $this->place = Place::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'place',
            'type' => 'discovered_place',
            'title' => 'Test Place',
            'location' => Point::makeGeodetic(51.5074, -0.1278), // London
            'location_address' => 'London, UK',
        ]);
    }

    #[Test]
    public function component_renders_successfully(): void
    {
        Livewire::test(Show::class, ['place' => $this->place])
            ->assertStatus(200);
    }

    #[Test]
    public function unauthorized_user_cannot_access_place(): void
    {
        $otherUser = User::factory()->create();
        $otherPlace = Place::factory()->create([
            'user_id' => $otherUser->id,
            'concept' => 'place',
            'type' => 'discovered_place',
            'title' => 'Other Place',
        ]);

        Livewire::test(Show::class, ['place' => $otherPlace])
            ->assertForbidden();
    }

    #[Test]
    public function link_nearby_event_works_with_correct_user_scoping(): void
    {
        // Create event with integration (events don't have user_id directly)
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
            'action' => 'test_action',
            'time' => now(),
            'location' => Point::makeGeodetic(51.5074, -0.1278), // Same location as place
        ]);

        $component = Livewire::test(Show::class, ['place' => $this->place]);

        $component->call('linkNearbyEvent', $event->id)
            ->assertStatus(200);

        // Verify relationship was created
        $this->assertDatabaseHas('relationships', [
            'from_type' => Event::class,
            'from_id' => $event->id,
            'to_type' => EventObject::class,
            'to_id' => $this->place->id,
            'type' => 'occurred_at',
        ]);
    }

    #[Test]
    public function cannot_link_another_users_event_to_place(): void
    {
        $this->expectException(ModelNotFoundException::class);

        // Create another user with their own integration and event
        $otherUser = User::factory()->create();
        $otherGroup = IntegrationGroup::factory()->create([
            'user_id' => $otherUser->id,
            'service' => 'test',
        ]);
        $otherIntegration = Integration::factory()->create([
            'user_id' => $otherUser->id,
            'integration_group_id' => $otherGroup->id,
            'service' => 'test',
        ]);
        $otherEvent = Event::factory()->create([
            'integration_id' => $otherIntegration->id,
            'action' => 'other_action',
        ]);

        $component = Livewire::test(Show::class, ['place' => $this->place]);

        // Should throw ModelNotFoundException because event won't be found due to forUser() scope
        $component->call('linkNearbyEvent', $otherEvent->id);

        // This won't execute due to exception, but demonstrates intent
        // No relationship should be created
        $this->assertDatabaseMissing('relationships', [
            'from_id' => $otherEvent->id,
            'to_id' => $this->place->id,
        ]);
    }

    #[Test]
    public function toggle_favorite_updates_metadata(): void
    {
        $this->assertFalse($this->place->is_favorite);

        $component = Livewire::test(Show::class, ['place' => $this->place]);

        $component->call('toggleFavorite');

        $this->place->refresh();
        $this->assertTrue($this->place->is_favorite);

        $component->call('toggleFavorite');

        $this->place->refresh();
        $this->assertFalse($this->place->is_favorite);
    }

    #[Test]
    public function unlink_event_removes_relationship(): void
    {
        // Create event and link it to place
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'action' => 'test_action',
        ]);

        Relationship::createRelationship([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event->id,
            'to_type' => EventObject::class,
            'to_id' => $this->place->id,
            'type' => 'occurred_at',
        ]);

        $component = Livewire::test(Show::class, ['place' => $this->place]);

        $component->call('unlinkEvent', $event->id);

        // Verify relationship was soft deleted
        $this->assertSoftDeleted('relationships', [
            'from_id' => $event->id,
            'to_id' => $this->place->id,
            'type' => 'occurred_at',
        ]);
    }

    #[Test]
    public function save_place_updates_title_and_metadata(): void
    {
        $component = Livewire::test(Show::class, ['place' => $this->place]);

        $component->call('startEditing');

        $component->set('editTitle', 'Updated Place Name')
            ->set('editCategory', 'cafe')
            ->set('editIsFavorite', true)
            ->set('editDetectionRadius', 100)
            ->call('savePlace');

        $this->place->refresh();

        $this->assertEquals('Updated Place Name', $this->place->title);
        $this->assertEquals('cafe', $this->place->category);
        $this->assertTrue($this->place->is_favorite);
        $this->assertEquals(100, $this->place->detection_radius);
    }

    #[Test]
    public function save_place_validates_required_fields(): void
    {
        $component = Livewire::test(Show::class, ['place' => $this->place]);

        $component->call('startEditing');

        $component->set('editTitle', '')
            ->call('savePlace')
            ->assertHasErrors(['editTitle' => 'required']);
    }

    #[Test]
    public function save_place_validates_detection_radius_range(): void
    {
        $component = Livewire::test(Show::class, ['place' => $this->place]);

        $component->call('startEditing');

        // Test minimum
        $component->set('editDetectionRadius', 5)
            ->call('savePlace')
            ->assertHasErrors(['editDetectionRadius' => 'min']);

        // Test maximum
        $component->set('editDetectionRadius', 600)
            ->call('savePlace')
            ->assertHasErrors(['editDetectionRadius' => 'max']);
    }
}
