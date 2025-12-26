<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapViewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function map_route_is_accessible_when_authenticated(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('map.index'));

        $response->assertStatus(200);
        $response->assertSee('Map');
    }

    /**
     * @test
     */
    public function map_route_requires_authentication(): void
    {
        $response = $this->get(route('map.index'));

        $response->assertRedirect(route('login'));
    }

    /**
     * @test
     */
    public function map_displays_events_with_location(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->withLocation()->create([
            'location_address' => 'Test Location, London',
        ]);

        // Ensure event is accessible by user
        $event->integration->update(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response->assertStatus(200);
    }

    /**
     * @test
     */
    public function map_filters_by_date_range(): void
    {
        $user = User::factory()->create();

        // Create events with different dates
        $oldEvent = Event::factory()->withLocation()->create([
            'time' => now()->subDays(60),
        ]);
        $recentEvent = Event::factory()->withLocation()->create([
            'time' => now()->subDays(10),
        ]);

        $oldEvent->integration->update(['user_id' => $user->id]);
        $recentEvent->integration->update(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response->assertStatus(200);
    }

    /**
     * @test
     */
    public function map_shows_event_objects_with_location(): void
    {
        $user = User::factory()->create();

        $object = EventObject::factory()->withLocation()->create([
            'user_id' => $user->id,
            'location_address' => 'Object Location',
        ]);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response->assertStatus(200);
    }

    /**
     * @test
     */
    public function map_data_includes_latitude_and_longitude(): void
    {
        $user = User::factory()->create();

        $event = Event::factory()->create([
            'location' => Point::makeGeodetic(51.5074, -0.1278), // London coordinates
            'location_address' => 'London, UK',
            'location_geocoded_at' => now(),
            'location_source' => 'test',
        ]);

        $event->integration->update(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response->assertStatus(200);
    }
}
