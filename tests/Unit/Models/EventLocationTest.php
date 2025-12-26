<?php

namespace Tests\Unit\Models;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventLocationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * @test
     */
    public function set_location_stores_coordinates(): void
    {
        $event = Event::factory()->create();
        $event->integration->update(['user_id' => $this->user->id]);

        $event->setLocation(51.5074, -0.1278, 'London, UK', 'manual');
        $event->refresh();

        $this->assertInstanceOf(Point::class, $event->location);
        $this->assertEquals(51.5074, $event->location->getLatitude());
        $this->assertEquals(-0.1278, $event->location->getLongitude());
        $this->assertEquals('London, UK', $event->location_address);
        $this->assertEquals('manual', $event->location_source);
        $this->assertNotNull($event->location_geocoded_at);
    }

    /**
     * @test
     */
    public function latitude_accessor(): void
    {
        $event = Event::factory()->create();
        $event->integration->update(['user_id' => $this->user->id]);
        $event->location = Point::makeGeodetic(51.5074, -0.1278);
        $event->save();
        $event->refresh();

        $this->assertEquals(51.5074, $event->latitude);
    }

    /**
     * @test
     */
    public function longitude_accessor(): void
    {
        $event = Event::factory()->create();
        $event->integration->update(['user_id' => $this->user->id]);
        $event->location = Point::makeGeodetic(51.5074, -0.1278);
        $event->save();
        $event->refresh();

        $this->assertEquals(-0.1278, $event->longitude);
    }

    /**
     * @test
     */
    public function latitude_accessor_returns_null_when_no_location(): void
    {
        $event = Event::factory()->create();
        $event->integration->update(['user_id' => $this->user->id]);

        $this->assertNull($event->latitude);
    }

    /**
     * @test
     */
    public function inherit_location_from_target(): void
    {
        // Create target object with location
        $target = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'place',
            'type' => 'venue',
            'title' => 'Test Venue',
        ]);
        $target->setLocation(51.5074, -0.1278, 'London, UK', 'manual');

        // Create event with this target
        $event = Event::factory()->create([
            'target_id' => $target->id,
        ]);
        $event->integration->update(['user_id' => $this->user->id]);

        $result = $event->inheritLocationFromTarget();

        $this->assertTrue($result);
        $event->refresh();
        $this->assertInstanceOf(Point::class, $event->location);
        $this->assertEquals(51.5074, $event->location->getLatitude());
        $this->assertEquals(-0.1278, $event->location->getLongitude());
        $this->assertEquals('London, UK', $event->location_address);
        $this->assertEquals('inherited', $event->location_source);
    }

    /**
     * @test
     */
    public function inherit_location_returns_false_when_no_target(): void
    {
        $event = Event::factory()->create();
        $event->integration->update(['user_id' => $this->user->id]);

        // Manually set target_id to null to test the behavior
        $event->target_id = null;

        $result = $event->inheritLocationFromTarget();

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function inherit_location_returns_false_when_target_has_no_location(): void
    {
        $target = EventObject::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $event = Event::factory()->create([
            'target_id' => $target->id,
        ]);
        $event->integration->update(['user_id' => $this->user->id]);

        $result = $event->inheritLocationFromTarget();

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function has_location_scope(): void
    {
        $eventWithLocation = Event::factory()->create();
        $eventWithLocation->integration->update(['user_id' => $this->user->id]);
        $eventWithLocation->setLocation(51.5074, -0.1278, 'London, UK');

        $eventWithoutLocation = Event::factory()->create();
        $eventWithoutLocation->integration->update(['user_id' => $this->user->id]);

        $results = Event::hasLocation()->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->contains($eventWithLocation));
        $this->assertFalse($results->contains($eventWithoutLocation));
    }

    /**
     * @test
     */
    public function within_radius_scope(): void
    {
        // London coordinates: 51.5074, -0.1278
        $londonEvent = Event::factory()->create();
        $londonEvent->integration->update(['user_id' => $this->user->id]);
        $londonEvent->setLocation(51.5074, -0.1278, 'London, UK');

        // Manchester coordinates: 53.4808, -2.2426 (about 260km from London)
        $manchesterEvent = Event::factory()->create();
        $manchesterEvent->integration->update(['user_id' => $this->user->id]);
        $manchesterEvent->setLocation(53.4808, -2.2426, 'Manchester, UK');

        // Search within 100km of London
        $results = Event::withinRadius(51.5074, -0.1278, 100000)->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->contains($londonEvent));
        $this->assertFalse($results->contains($manchesterEvent));

        // Search within 300km of London (should include Manchester)
        $results = Event::withinRadius(51.5074, -0.1278, 300000)->get();

        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function within_bounds_scope(): void
    {
        // London
        $londonEvent = Event::factory()->create();
        $londonEvent->integration->update(['user_id' => $this->user->id]);
        $londonEvent->setLocation(51.5074, -0.1278, 'London, UK');

        // Paris (outside UK bounds)
        $parisEvent = Event::factory()->create();
        $parisEvent->integration->update(['user_id' => $this->user->id]);
        $parisEvent->setLocation(48.8566, 2.3522, 'Paris, France');

        // Bounding box around UK (rough approximation)
        // North: 58.6, South: 49.9, East: 1.8, West: -8.0
        $results = Event::withinBounds(58.6, 49.9, 1.8, -8.0)->get();

        $this->assertTrue($results->contains($londonEvent));
        $this->assertFalse($results->contains($parisEvent));
    }
}
