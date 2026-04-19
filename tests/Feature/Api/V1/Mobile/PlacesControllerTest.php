<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\EventObject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlacesControllerTest extends TestCase
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
        $place = $this->createPlace();
        $this->getJson("/api/v1/mobile/places/{$place->id}")->assertStatus(401);
    }

    #[Test]
    public function returns_place_shape(): void
    {
        $place = $this->createPlace();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson("/api/v1/mobile/places/{$place->id}")
            ->assertOk()
            ->assertJsonPath('id', $place->id)
            ->assertJsonPath('title', 'Climpson & Sons')
            ->assertJsonPath('latitude', 51.5464)
            ->assertJsonPath('longitude', -0.0583)
            ->assertJsonPath('category', 'coffee_shop');
    }

    #[Test]
    public function returns_404_when_object_is_not_a_place(): void
    {
        $object = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'merchant',
        ]);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson("/api/v1/mobile/places/{$object->id}")->assertStatus(404);
    }

    #[Test]
    public function returns_404_for_malformed_id(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/places/not-a-uuid')->assertStatus(404);
    }

    #[Test]
    public function returns_404_for_other_users_place(): void
    {
        $place = $this->createPlace();
        $other = User::factory()->create();
        Sanctum::actingAs($other, ['ios:read', 'ios:write']);

        $this->getJson("/api/v1/mobile/places/{$place->id}")->assertStatus(404);
    }

    protected function createPlace(): EventObject
    {
        return EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'place',
            'type' => 'cafe',
            'title' => 'Climpson & Sons',
            'metadata' => [
                'latitude' => 51.5464,
                'longitude' => -0.0583,
                'category' => 'coffee_shop',
            ],
        ]);
    }
}
