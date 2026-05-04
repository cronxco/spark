<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
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
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/map/data?bbox=bad')
            ->assertStatus(422);
    }

    #[Test]
    public function rejects_inverted_bbox(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

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

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

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

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

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
    public function returns_empty_marker_arrays_when_nothing_in_bbox(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/map/data?bbox=10.0,10.0,11.0,11.0')
            ->assertOk()
            ->assertJsonPath('clusters', [])
            ->assertJsonPath('markers.events', [])
            ->assertJsonPath('markers.places', []);
    }
}
