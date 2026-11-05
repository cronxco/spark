<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PSEC-03 / APO-04.
 *
 * `POST /api/clear-card-cache` computed a per-user key pattern, ignored it, and
 * called Cache::flush() behind nothing but `auth:sanctum` — so any authenticated
 * token could evict every tenant's cache entries.
 */
class ClearCardCacheRouteRemovedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_route_is_no_longer_registered(): void
    {
        $this->assertNull(
            Route::getRoutes()->getByName('api.clear-card-cache'),
            'The globally destructive cache-flush route must not be registered.',
        );
    }

    #[Test]
    public function the_endpoint_is_unreachable_for_an_authenticated_user(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $response = $this->postJson('/api/clear-card-cache');

        $this->assertContains(
            $response->getStatusCode(),
            [404, 405],
            'The cache-flush endpoint must not respond successfully.',
        );
    }

    #[Test]
    public function no_authenticated_request_can_evict_another_users_cache(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        Cache::put("card_stream_{$alice->id}_seed", 'alice-value', 600);
        Cache::put("card_stream_{$bob->id}_seed", 'bob-value', 600);

        Sanctum::actingAs($alice, ['*']);
        $this->postJson('/api/clear-card-cache');

        $this->assertSame('bob-value', Cache::get("card_stream_{$bob->id}_seed"));
        $this->assertSame('alice-value', Cache::get("card_stream_{$alice->id}_seed"));
    }
}
