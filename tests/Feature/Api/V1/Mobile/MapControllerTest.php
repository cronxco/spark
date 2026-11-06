<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Support\SparkAbility;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MapControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
        $this->user = User::factory()->create();
    }

    #[Test]
    public function requires_authentication(): void
    {
        $this->getJson('/api/v1/mobile/map/data?bbox=51.0,-0.5,52.0,0.5')->assertStatus(401);
    }

    #[Test]
    public function rejects_malformed_bbox(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->getJson('/api/v1/mobile/map/data?bbox=bad')
            ->assertStatus(422);
    }

    #[Test]
    public function rejects_inverted_bbox(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        // sw > ne should fail validation
        $this->getJson('/api/v1/mobile/map/data?bbox=52.0,0.5,51.0,-0.5')
            ->assertStatus(422);
    }

    #[Test]
    public function returns_places_within_bbox(): void
    {
        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'place',
            'title' => 'Inside',
            'location' => Point::makeGeodetic(51.5, 0.0),
        ]);

        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'place',
            'title' => 'Outside',
            'location' => Point::makeGeodetic(48.8, 2.3),
        ]);

        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $response = $this->getJson('/api/v1/mobile/map/data?bbox=51.0,-0.5,52.0,0.5')
            ->assertOk()
            ->assertJsonStructure(['clusters', 'markers' => ['events', 'places']]);

        $this->assertCount(1, $response->json('markers.places'));
        $this->assertEquals('Inside', $response->json('markers.places.0.title'));
        $this->assertEquals('place', $response->json('markers.places.0.kind'));
        $this->assertEquals(51.5, $response->json('markers.places.0.lat'));
        $this->assertEquals(0.0, $response->json('markers.places.0.lng'));
        $this->assertArrayNotHasKey('latitude', $response->json('markers.places.0'));
        $this->assertArrayNotHasKey('longitude', $response->json('markers.places.0'));
    }

    #[Test]
    public function returns_events_as_compact_map_pins_using_target_location(): void
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);
        $actor = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'account',
            'title' => 'Current Account',
        ]);
        $target = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'counterparty',
            'title' => 'Craft Metropolis',
            'location' => Point::makeGeodetic(51.5225, -0.0745),
        ]);

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'value' => 3000,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'time' => '2026-04-25 14:27:02',
        ]);

        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'location' => null,
        ]);

        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $response = $this->getJson('/api/v1/mobile/map/data?bbox=51.0,-0.5,52.0,0.5')
            ->assertOk()
            ->assertJsonPath('markers.events.0.id', $event->id)
            ->assertJsonPath('markers.events.0.kind', 'transaction')
            ->assertJsonPath('markers.events.0.lat', 51.5225)
            ->assertJsonPath('markers.events.0.lng', -0.0745)
            ->assertJsonPath('markers.events.0.title', 'Craft Metropolis')
            ->assertJsonPath('markers.events.0.service', 'monzo');

        $this->assertCount(1, $response->json('markers.events'));
        $this->assertArrayHasKey('subtitle', $response->json('markers.events.0'));
        $this->assertArrayNotHasKey('action', $response->json('markers.events.0'));
        $this->assertArrayNotHasKey('actor', $response->json('markers.events.0'));
        $this->assertArrayNotHasKey('target', $response->json('markers.events.0'));
    }

    #[Test]
    public function event_markers_carry_their_domain_and_address(): void
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'counterparty',
            'title' => 'Kaffateria',
            'location' => Point::makeGeodetic(51.5, 0.0),
            'location_address' => '5 Dundas Street, Glasgow, G1 2AH, United Kingdom',
        ]);

        Event::factory()->create([
            'integration_id' => $integration->id,
            'target_id' => $target->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'location' => null,
            'location_address' => null,
        ]);

        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->getJson('/api/v1/mobile/map/data?bbox=51.0,-0.5,52.0,0.5')
            ->assertOk()
            ->assertJsonPath('markers.events.0.domain', 'money')
            ->assertJsonPath('markers.events.0.address', '5 Dundas Street, Glasgow, G1 2AH, United Kingdom');
    }

    #[Test]
    public function place_markers_carry_their_visit_summary(): void
    {
        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'place',
            'type' => 'discovered_place',
            'title' => '27 Gilmour Road, Edinburgh',
            'location' => Point::makeGeodetic(51.5, 0.0),
            'location_address' => '27 Gilmour Road, Edinburgh, EH16 5NS, United Kingdom',
            'metadata' => [
                'visit_count' => 2,
                'last_visit_at' => '2026-10-30T14:45:42+00:00',
                'category' => 'home',
            ],
        ]);

        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->getJson('/api/v1/mobile/map/data?bbox=51.0,-0.5,52.0,0.5')
            ->assertOk()
            ->assertJsonPath('markers.places.0.visit_count', 2)
            ->assertJsonPath('markers.places.0.last_visit_at', '2026-10-30T14:45:42+00:00')
            ->assertJsonPath('markers.places.0.category', 'home')
            ->assertJsonPath('markers.places.0.address', '27 Gilmour Road, Edinburgh, EH16 5NS, United Kingdom');
    }

    #[Test]
    public function place_markers_without_visits_send_nulls(): void
    {
        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'place',
            'title' => 'Liverpool Street',
            'location' => Point::makeGeodetic(51.5, 0.0),
            'metadata' => ['last_visit_at' => 'not a date'],
        ]);

        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->getJson('/api/v1/mobile/map/data?bbox=51.0,-0.5,52.0,0.5')
            ->assertOk()
            ->assertJsonPath('markers.places.0.visit_count', null)
            ->assertJsonPath('markers.places.0.last_visit_at', null)
            ->assertJsonPath('markers.places.0.category', null);
    }

    #[Test]
    public function maps_event_kind_from_domain_and_action(): void
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);

        $workout = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'apple_health',
            'domain' => 'health',
            'action' => 'did_workout',
            'location' => Point::makeGeodetic(51.5, 0.1),
        ]);

        $generic = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'calendar',
            'domain' => 'calendar',
            'action' => 'attended',
            'location' => Point::makeGeodetic(51.6, 0.2),
        ]);

        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $markers = collect(
            $this->getJson('/api/v1/mobile/map/data?bbox=51.0,-0.5,52.0,0.5')
                ->assertOk()
                ->json('markers.events')
        )->keyBy('id');

        $this->assertSame('workout', $markers[$workout->id]['kind']);
        $this->assertSame('event', $markers[$generic->id]['kind']);
    }

    #[Test]
    public function returns_empty_marker_arrays_when_nothing_in_bbox(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->getJson('/api/v1/mobile/map/data?bbox=10.0,10.0,11.0,11.0')
            ->assertOk()
            ->assertJsonPath('clusters', [])
            ->assertJsonPath('markers.events', [])
            ->assertJsonPath('markers.places', []);
    }

    #[Test]
    public function a_world_bbox_returns_events_and_places(): void
    {
        // The app asks for the whole world first. As a geography envelope
        // that box has antipodal edges and PostGIS refused the query.
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'location' => Point::makeGeodetic(40.4, -3.7),
        ]);
        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'place',
            'title' => 'Madrid',
            'location' => Point::makeGeodetic(40.4, -3.7),
        ]);

        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $response = $this->getJson('/api/v1/mobile/map/data?bbox=-90.0,-180.0,90.0,180.0')
            ->assertOk();

        $this->assertSame([$event->id], array_column($response->json('markers.events'), 'id'));
        $this->assertSame(['Madrid'], array_column($response->json('markers.places'), 'title'));
    }

    #[Test]
    public function a_wide_bbox_keeps_points_near_its_southern_edge(): void
    {
        // A geography envelope's southern edge is a great circle that bows
        // towards the pole, so it used to drop this point.
        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'place',
            'title' => 'London',
            'location' => Point::makeGeodetic(51.0, 0.0),
        ]);

        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->getJson('/api/v1/mobile/map/data?bbox=50.0,-80.0,60.0,80.0')
            ->assertOk()
            ->assertJsonPath('markers.places.0.title', 'London');
    }
}
