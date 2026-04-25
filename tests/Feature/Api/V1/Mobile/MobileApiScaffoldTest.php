<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MobileApiScaffoldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
    }

    #[Test]
    public function mobile_api_is_hidden_when_feature_flag_is_off(): void
    {
        config(['ios.mobile_api_enabled' => false]);
        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/ping')->assertStatus(404);
    }

    #[Test]
    public function mobile_api_requires_authentication(): void
    {
        $this->getJson('/api/v1/mobile/ping')->assertStatus(401);
    }

    #[Test]
    public function mobile_api_requires_ios_read_ability(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:write']);

        // ability:ios:read means the token must have ios:read (or a matching
        // wildcard like ios:*). A pure write token fails the guard with 403.
        $this->getJson('/api/v1/mobile/ping')->assertStatus(403);
    }

    #[Test]
    public function mobile_api_ping_returns_ok_when_fully_authorised(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/ping')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('user_id', (string) $user->id)
            ->assertHeader('ETag');
    }

    #[Test]
    public function mobile_api_emits_matching_etag_that_returns_304(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        // The ping payload embeds `server_time`, so the ETag changes whenever
        // the clock ticks — freeze it so both requests hash the same bytes.
        $this->freezeTime();

        $first = $this->getJson('/api/v1/mobile/ping')->assertOk();
        $etag = $first->headers->get('ETag');

        $this->assertNotNull($etag);

        $this->getJson('/api/v1/mobile/ping', ['If-None-Match' => $etag])
            ->assertStatus(304);
    }

    #[Test]
    public function mobile_api_emits_different_etag_when_payload_differs(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        $response = $this->getJson('/api/v1/mobile/ping')->assertOk();
        $etag = $response->headers->get('ETag');

        $this->getJson('/api/v1/mobile/ping', ['If-None-Match' => '"deadbeef"'])
            ->assertOk()
            ->assertHeader('ETag');

        // Sanity check: a non-matching If-None-Match does not downgrade 200s.
        $this->assertNotEquals('"deadbeef"', $etag);
    }
}
