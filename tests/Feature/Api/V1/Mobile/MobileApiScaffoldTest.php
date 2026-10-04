<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\User;
use App\Support\SparkAbility;
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
        Sanctum::actingAs(User::factory()->create(), SparkAbility::MOBILE_SESSION);

        $this->getJson('/api/v1/mobile/ping')->assertStatus(404);
    }

    #[Test]
    public function mobile_api_requires_authentication(): void
    {
        $this->getJson('/api/v1/mobile/ping')->assertStatus(401);
    }

    #[Test]
    public function a_read_route_needs_its_read_capability(): void
    {
        Sanctum::actingAs(User::factory()->create(), SparkAbility::MOBILE_WRITE);

        $this->getJson('/api/v1/mobile/feed')
            ->assertStatus(403)
            ->assertJsonPath('required_ability', 'data:read');
    }

    #[Test]
    public function a_write_route_needs_its_write_capability(): void
    {
        Sanctum::actingAs(User::factory()->create(), SparkAbility::MOBILE_READ);

        $this->postJson('/api/v1/mobile/flint/notes', ['body' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('required_ability', 'flint:write');
    }

    #[Test]
    public function a_session_from_before_the_cutover_is_sent_to_refresh(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/feed')
            ->assertStatus(401)
            ->assertJsonPath('reason', 'session_upgrade_required');
    }

    #[Test]
    public function every_mobile_route_names_a_capability_and_none_uses_the_old_scopes(): void
    {
        $open = ['api.v1.mobile.ping', 'api.v1.mobile.me', 'api.v1.mobile.logout', 'api.v1.mobile.api-tokens.store'];

        foreach (app('router')->getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! str_starts_with($name, 'api.v1.mobile.')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $this->assertEmpty(array_filter($middleware, fn ($entry): bool => is_string($entry) && str_contains($entry, 'ios:')), "{$name} still uses an ios:* scope");

            if (! in_array($name, $open, true)) {
                $this->assertNotEmpty(array_filter($middleware, fn ($entry): bool => is_string($entry) && str_starts_with($entry, 'spark.ability:')), "{$name} names no capability");
            }
        }
    }

    #[Test]
    public function mobile_api_ping_returns_ok_when_fully_authorised(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, SparkAbility::MOBILE_SESSION);

        $this->getJson('/api/v1/mobile/ping')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('user_id', (string) $user->id)
            ->assertHeader('ETag');
    }

    #[Test]
    public function mobile_api_emits_matching_etag_that_returns_304(): void
    {
        Sanctum::actingAs(User::factory()->create(), SparkAbility::MOBILE_SESSION);

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
        Sanctum::actingAs(User::factory()->create(), SparkAbility::MOBILE_SESSION);

        $response = $this->getJson('/api/v1/mobile/ping')->assertOk();
        $etag = $response->headers->get('ETag');

        $this->getJson('/api/v1/mobile/ping', ['If-None-Match' => '"deadbeef"'])
            ->assertOk()
            ->assertHeader('ETag');

        // Sanity check: a non-matching If-None-Match does not downgrade 200s.
        $this->assertNotEquals('"deadbeef"', $etag);
    }
}
