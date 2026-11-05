<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\Mobile\EventRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MobileSessionAbilities;
use Tests\TestCase;

/**
 * EOB-D6: the app shows a read-only map of a workout's GPS route.
 */
class EventRouteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);

        $this->user = User::factory()->create();
        $this->integration = $this->integrationFor($this->user);
    }

    #[Test]
    public function a_workout_route_is_returned_with_its_summary(): void
    {
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read']));
        $event = $this->workout([
            ['lat' => 51.5, 'lng' => -0.12, 'alt' => 10],
            ['lat' => null, 'lng' => null],
            ['lat' => 51.51, 'lng' => -0.13],
            ['lat' => 'nope', 'lng' => 2],
        ], ['distance' => 5.02, 'distance_unit' => 'km', 'duration_seconds' => 1500]);

        $this->getJson("/api/v1/mobile/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('has_route', true)
            ->assertJsonMissingPath('route_points');

        $this->getJson("/api/v1/mobile/events/{$event->id}/route")
            ->assertOk()
            ->assertHeader('ETag')
            ->assertExactJson([
                'points' => [['lat' => 51.5, 'lng' => -0.12], ['lat' => 51.51, 'lng' => -0.13]],
                'total_points' => 2,
                'distance' => 5.02,
                'distance_unit' => 'km',
                'duration_seconds' => 1500,
            ]);
    }

    #[Test]
    public function an_event_without_a_route_has_no_flag_and_no_route(): void
    {
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read']));
        $plain = Event::factory()->create(['integration_id' => $this->integration->id, 'service' => 'monzo', 'event_metadata' => []]);
        $indoor = $this->workout([['lat' => null, 'lng' => null]]);

        $this->getJson("/api/v1/mobile/events/{$plain->id}")->assertOk()->assertJsonMissingPath('has_route');
        $this->getJson("/api/v1/mobile/events/{$plain->id}/route")->assertNotFound();
        $this->getJson("/api/v1/mobile/events/{$indoor->id}")->assertOk()->assertJsonMissingPath('has_route');
        $this->getJson("/api/v1/mobile/events/{$indoor->id}/route")->assertNotFound();
    }

    #[Test]
    public function another_users_route_is_not_found(): void
    {
        $other = User::factory()->create();
        $event = Event::factory()->create([
            'integration_id' => $this->integrationFor($other)->id,
            'service' => 'apple_health',
            'event_metadata' => ['route_points' => [['lat' => 1, 'lng' => 1]]],
        ]);
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read']));

        $this->getJson("/api/v1/mobile/events/{$event->id}/route")->assertNotFound();
    }

    #[Test]
    public function a_token_with_the_data_read_capability_can_read_a_route(): void
    {
        Sanctum::actingAs($this->user, ['mobile:session', 'data:read']);
        $event = $this->workout([['lat' => 1, 'lng' => 2]]);

        $this->getJson("/api/v1/mobile/events/{$event->id}/route")->assertOk()->assertJsonPath('total_points', 1);
    }

    #[Test]
    public function a_token_without_read_scope_is_forbidden(): void
    {
        Sanctum::actingAs($this->user, ['mobile:session', 'flint:write']);
        $event = $this->workout([['lat' => 1, 'lng' => 2]]);

        $this->getJson("/api/v1/mobile/events/{$event->id}/route")->assertForbidden();
    }

    #[Test]
    public function long_routes_are_thinned_keeping_both_ends(): void
    {
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read']));
        $points = array_map(fn (int $index) => ['lat' => 50 + $index / 10000, 'lng' => 0.0], range(0, 2499));
        $event = $this->workout($points);

        $response = $this->getJson("/api/v1/mobile/events/{$event->id}/route")->assertOk();

        $this->assertCount(EventRoute::MAX_POINTS, $response->json('points'));
        $this->assertSame(2500, $response->json('total_points'));
        $this->assertEquals(50.0, $response->json('points.0.lat'));
        $this->assertEquals(50.2499, $response->json('points.' . (EventRoute::MAX_POINTS - 1) . '.lat'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $points
     * @param  array<string, mixed>  $metadata
     */
    private function workout(array $points, array $metadata = []): Event
    {
        return Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'apple_health',
            'action' => 'did_workout',
            'event_metadata' => [...$metadata, 'route_points' => $points],
        ]);
    }

    private function integrationFor(User $user): Integration
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'monzo']);

        return Integration::factory()->create(['user_id' => $user->id, 'integration_group_id' => $group->id, 'service' => 'monzo']);
    }
}
