<?php

namespace Tests\Feature\Unit\Models;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Place;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function events_here_scopes_to_user_correctly(): void
    {
        // Create two users
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // Create integrations for both users
        $group1 = IntegrationGroup::factory()->for($user1)->create();
        $integration1 = Integration::factory()->create([
            'user_id' => $user1->id,
            'integration_group_id' => $group1->id,
        ]);

        $group2 = IntegrationGroup::factory()->for($user2)->create();
        $integration2 = Integration::factory()->create([
            'user_id' => $user2->id,
            'integration_group_id' => $group2->id,
        ]);

        // Create a place for user1
        $place = Place::factory()->for($user1)->create();

        // Create events for both users at the same location
        $event1 = Event::factory()->for($integration1)->create();
        $event1->setLocation($place->latitude, $place->longitude);
        $event1->save();

        $event2 = Event::factory()->for($integration2)->create();
        $event2->setLocation($place->latitude, $place->longitude);
        $event2->save();

        // Create occurred_at relationships for both events
        Relationship::createRelationship([
            'user_id' => $user1->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => EventObject::class,
            'to_id' => $place->id,
            'type' => 'occurred_at',
        ]);

        Relationship::createRelationship([
            'user_id' => $user2->id,
            'from_type' => Event::class,
            'from_id' => $event2->id,
            'to_type' => EventObject::class,
            'to_id' => $place->id,
            'type' => 'occurred_at',
        ]);

        // Assert that eventsHere only returns user1's event
        $eventsHere = $place->eventsHere()->get();

        $this->assertCount(1, $eventsHere);
        $this->assertTrue($eventsHere->contains($event1));
        $this->assertFalse($eventsHere->contains($event2));
    }

    /**
     * @test
     */
    public function events_nearby_scopes_to_user_correctly(): void
    {
        // Create two users
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // Create integrations for both users
        $group1 = IntegrationGroup::factory()->for($user1)->create();
        $integration1 = Integration::factory()->create([
            'user_id' => $user1->id,
            'integration_group_id' => $group1->id,
        ]);

        $group2 = IntegrationGroup::factory()->for($user2)->create();
        $integration2 = Integration::factory()->create([
            'user_id' => $user2->id,
            'integration_group_id' => $group2->id,
        ]);

        // Create a place for user1
        $place = Place::factory()->for($user1)->create();

        // Create events for both users nearby (within 50m)
        $event1 = Event::factory()->for($integration1)->create();
        $event1->setLocation($place->latitude, $place->longitude);
        $event1->save();

        $event2 = Event::factory()->for($integration2)->create();
        $event2->setLocation($place->latitude, $place->longitude);
        $event2->save();

        // Assert that eventsNearby only returns user1's event
        $eventsNearby = $place->eventsNearby()->get();

        $this->assertCount(1, $eventsNearby);
        $this->assertTrue($eventsNearby->contains($event1));
        $this->assertFalse($eventsNearby->contains($event2));
    }

    /**
     * @test
     */
    public function events_nearby_returns_empty_query_when_place_has_no_location(): void
    {
        $user = User::factory()->create();

        $place = Place::factory()->for($user)->create(['location' => null]);

        $eventsNearby = $place->eventsNearby()->get();

        $this->assertCount(0, $eventsNearby);
    }
}
