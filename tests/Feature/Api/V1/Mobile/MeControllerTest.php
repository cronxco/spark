<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
    }

    #[Test]
    public function requires_authentication(): void
    {
        $this->getJson('/api/v1/mobile/me')->assertStatus(401);
    }

    #[Test]
    public function returns_user_profile_shape(): void
    {
        $user = User::factory()->create([
            'name' => 'Will Scott',
            'email' => 'will@example.com',
        ]);

        Sanctum::actingAs($user, ['ios:read']);

        $this->getJson('/api/v1/mobile/me')
            ->assertOk()
            ->assertJsonStructure(['id', 'name', 'email', 'timezone', 'avatar_url'])
            ->assertJsonPath('id', (string) $user->id)
            ->assertJsonPath('name', 'Will Scott')
            ->assertJsonPath('email', 'will@example.com')
            ->assertJsonPath('avatar_url', null);
    }

    #[Test]
    public function id_is_returned_as_string(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read']);

        $response = $this->getJson('/api/v1/mobile/me')->assertOk();

        $this->assertIsString($response->json('id'));
    }

    #[Test]
    public function timezone_defaults_to_utc(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read']);

        $this->getJson('/api/v1/mobile/me')
            ->assertOk()
            ->assertJsonPath('timezone', 'UTC');
    }

    #[Test]
    public function etag_header_is_present(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read']);

        $this->getJson('/api/v1/mobile/me')
            ->assertOk()
            ->assertHeader('ETag');
    }
}
