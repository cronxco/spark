<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\EventObject;
use App\Models\User;
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
            'metadata' => ['latitude' => 51.5, 'longitude' => 0.0],
        ]);

        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'place',
            'title' => 'Outside',
            'metadata' => ['latitude' => 48.8, 'longitude' => 2.3],
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $response = $this->getJson('/api/v1/mobile/map/data?bbox=51.0,-0.5,52.0,0.5')
            ->assertOk()
            ->assertJsonStructure(['clusters', 'markers' => ['events', 'places']]);

        $this->assertCount(1, $response->json('markers.places'));
        $this->assertEquals('Inside', $response->json('markers.places.0.title'));
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
