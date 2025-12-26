<?php

namespace Tests\Unit\Models;

use App\Models\EventObject;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventObjectLocationTest extends TestCase
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
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        $object->setLocation(51.5074, -0.1278, 'London, UK', 'manual');
        $object->refresh();

        $this->assertInstanceOf(Point::class, $object->location);
        $this->assertEquals(51.5074, $object->location->getLatitude());
        $this->assertEquals(-0.1278, $object->location->getLongitude());
        $this->assertEquals('London, UK', $object->location_address);
        $this->assertEquals('manual', $object->location_source);
        $this->assertNotNull($object->location_geocoded_at);
    }

    /**
     * @test
     */
    public function latitude_accessor(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);
        $object->location = Point::makeGeodetic(51.5074, -0.1278);
        $object->save();
        $object->refresh();

        $this->assertEquals(51.5074, $object->latitude);
    }

    /**
     * @test
     */
    public function longitude_accessor(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);
        $object->location = Point::makeGeodetic(51.5074, -0.1278);
        $object->save();
        $object->refresh();

        $this->assertEquals(-0.1278, $object->longitude);
    }

    /**
     * @test
     */
    public function has_location_scope(): void
    {
        $objectWithLocation = EventObject::factory()->create(['user_id' => $this->user->id]);
        $objectWithLocation->setLocation(51.5074, -0.1278, 'London, UK');

        $objectWithoutLocation = EventObject::factory()->create(['user_id' => $this->user->id]);

        $results = EventObject::hasLocation()->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->contains($objectWithLocation));
        $this->assertFalse($results->contains($objectWithoutLocation));
    }

    /**
     * @test
     */
    public function within_radius_scope(): void
    {
        $londonObject = EventObject::factory()->create(['user_id' => $this->user->id]);
        $londonObject->setLocation(51.5074, -0.1278, 'London, UK');

        $manchesterObject = EventObject::factory()->create(['user_id' => $this->user->id]);
        $manchesterObject->setLocation(53.4808, -2.2426, 'Manchester, UK');

        // Search within 100km of London
        $results = EventObject::withinRadius(51.5074, -0.1278, 100000)->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->contains($londonObject));
        $this->assertFalse($results->contains($manchesterObject));
    }

    /**
     * @test
     */
    public function within_bounds_scope(): void
    {
        $londonObject = EventObject::factory()->create(['user_id' => $this->user->id]);
        $londonObject->setLocation(51.5074, -0.1278, 'London, UK');

        $parisObject = EventObject::factory()->create(['user_id' => $this->user->id]);
        $parisObject->setLocation(48.8566, 2.3522, 'Paris, France');

        // Bounding box around UK
        $results = EventObject::withinBounds(58.6, 49.9, 1.8, -8.0)->get();

        $this->assertTrue($results->contains($londonObject));
        $this->assertFalse($results->contains($parisObject));
    }
}
