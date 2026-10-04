<?php

namespace Tests\Feature\Explore;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MobileSessionAbilities;
use Tests\TestCase;

/**
 * EX-02: the app sent `date`, the server ignored it and returned recent pins.
 */
class MapDateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ios.mobile_api_enabled' => true, 'app.enable_task_pipeline' => false]);
        $this->user = User::factory()->create();
        $this->user->setTimezone('Europe/London');
        $this->integration = Integration::factory()->create(['user_id' => $this->user->id]);
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read']));
    }

    #[Test]
    public function a_date_limits_events_to_that_local_day_across_a_dst_change(): void
    {
        // 29 March 2026: clocks go forward in London, so the day is 23 hours long
        // and runs from 00:00Z to 23:00Z.
        $before = $this->eventAt('2026-03-28 23:30:00');
        $inside = $this->eventAt('2026-03-29 22:30:00');
        $after = $this->eventAt('2026-03-29 23:30:00');
        EventObject::factory()->create([
            'user_id' => $this->user->id, 'concept' => 'place', 'title' => 'Home', 'location' => Point::makeGeodetic(51.5, -0.1),
        ]);

        $response = $this->getJson('/api/v1/mobile/map/data?bbox=51.0,-0.5,52.0,0.5&date=2026-03-29')
            ->assertOk()
            ->assertJsonPath('meta.timezone', 'Europe/London')
            ->assertJsonPath('meta.from', '2026-03-29T00:00:00+00:00')
            ->assertJsonPath('meta.to', '2026-03-29T23:00:00+00:00');

        $ids = collect($response->json('markers.events'))->pluck('id');
        $this->assertEquals([$inside->id], $ids->all());
        $this->assertNotContains($before->id, $ids);
        $this->assertNotContains($after->id, $ids);
        $this->assertCount(1, $response->json('markers.places'), 'Places are a catalogue and stay visible.');
    }

    #[Test]
    public function an_invalid_date_is_rejected(): void
    {
        $this->getJson('/api/v1/mobile/map/data?bbox=51.0,-0.5,52.0,0.5&date=2026-02-30')->assertStatus(422);
        $this->getJson('/api/v1/mobile/map/data?bbox=51.0,-0.5,52.0,0.5&date=yesterday')->assertStatus(422);
    }

    #[Test]
    public function without_a_date_the_response_is_unchanged(): void
    {
        $this->eventAt('2026-03-29 12:00:00');

        $response = $this->getJson('/api/v1/mobile/map/data?bbox=51.0,-0.5,52.0,0.5')->assertOk();

        $this->assertArrayNotHasKey('meta', $response->json());
        $this->assertCount(1, $response->json('markers.events'));
    }

    private function eventAt(string $time): Event
    {
        return Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'location' => Point::makeGeodetic(51.5, -0.1),
            'time' => $time,
        ]);
    }
}
