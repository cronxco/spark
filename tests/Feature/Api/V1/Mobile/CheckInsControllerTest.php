<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckInsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
        $this->user = User::factory()->create();
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/mobile/check-ins
    // -------------------------------------------------------------------------

    #[Test]
    public function store_requires_write_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload())
            ->assertStatus(403);
    }

    #[Test]
    public function store_creates_event_via_plugin(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload())
            ->assertStatus(201);

        $this->assertDatabaseHas('events', [
            'service' => 'daily_checkin',
            'action' => 'had_morning_checkin',
            'source_id' => 'daily_checkin_morning_2026-04-19',
        ]);
    }

    #[Test]
    public function store_rejects_out_of_range_ratings(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload(['physical' => 9]))
            ->assertStatus(422);
    }

    #[Test]
    public function store_is_idempotent_for_same_period_and_date(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload())->assertStatus(201);
        $this->postJson('/api/v1/mobile/check-ins', $this->payload(['physical' => 5]))->assertStatus(201);

        $this->assertEquals(1, Event::where('source_id', 'daily_checkin_morning_2026-04-19')->count());
    }

    #[Test]
    public function store_persists_notes_in_event_metadata(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload(['notes' => 'Feeling great today']))
            ->assertStatus(201);

        $event = Event::where('source_id', 'daily_checkin_morning_2026-04-19')->firstOrFail();
        $this->assertEquals('Feeling great today', $event->event_metadata['notes']);
    }

    #[Test]
    public function store_works_without_notes(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload())
            ->assertStatus(201);

        $event = Event::where('source_id', 'daily_checkin_morning_2026-04-19')->firstOrFail();
        $this->assertNull($event->event_metadata['notes']);
    }

    #[Test]
    public function store_rejects_notes_exceeding_max_length(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload(['notes' => str_repeat('a', 1001)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notes']);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/mobile/check-ins?date=YYYY-MM-DD
    // -------------------------------------------------------------------------

    #[Test]
    public function index_returns_completion_status_for_date(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/check-ins?date=2026-04-19')
            ->assertStatus(200)
            ->assertJson([
                'date' => '2026-04-19',
                'morning' => ['completed' => false, 'event' => null],
                'afternoon' => ['completed' => false, 'event' => null],
            ]);
    }

    #[Test]
    public function index_shows_completed_when_event_exists(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload())->assertStatus(201);

        $response = $this->getJson('/api/v1/mobile/check-ins?date=2026-04-19')
            ->assertStatus(200);

        $this->assertTrue($response->json('morning.completed'));
        $this->assertNotNull($response->json('morning.event'));
        $this->assertFalse($response->json('afternoon.completed'));
        $this->assertNull($response->json('afternoon.event'));
    }

    #[Test]
    public function index_requires_date_parameter(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/check-ins')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    #[Test]
    public function index_requires_valid_date_format(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/check-ins?date=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/mobile/check-ins/history?from=YYYY-MM-DD&to=YYYY-MM-DD
    // -------------------------------------------------------------------------

    #[Test]
    public function history_returns_day_by_day_summary(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload(['date' => '2026-04-19']))->assertStatus(201);
        $this->postJson('/api/v1/mobile/check-ins', $this->payload(['date' => '2026-04-19', 'period' => 'afternoon', 'physical' => 3, 'mental' => 4]))->assertStatus(201);

        $response = $this->getJson('/api/v1/mobile/check-ins/history?from=2026-04-18&to=2026-04-20')
            ->assertStatus(200);

        $this->assertEquals('2026-04-18', $response->json('from'));
        $this->assertEquals('2026-04-20', $response->json('to'));

        $days = $response->json('days');
        $this->assertCount(3, $days);

        $targetDay = collect($days)->firstWhere('date', '2026-04-19');
        $this->assertTrue($targetDay['morning']['completed']);
        $this->assertEquals(4, $targetDay['morning']['physical']);
        $this->assertEquals(3, $targetDay['morning']['mental']);
        $this->assertEquals(7, $targetDay['morning']['combined']);
        $this->assertTrue($targetDay['afternoon']['completed']);
        $this->assertFalse(collect($days)->firstWhere('date', '2026-04-18')['morning']['completed']);
    }

    #[Test]
    public function history_rejects_range_exceeding_90_days(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/check-ins/history?from=2026-01-01&to=2026-04-15')
            ->assertStatus(422);
    }

    #[Test]
    public function history_rejects_to_before_from(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/check-ins/history?from=2026-04-20&to=2026-04-10')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    #[Test]
    public function history_requires_read_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:write']);

        $this->getJson('/api/v1/mobile/check-ins/history?from=2026-04-10&to=2026-04-19')
            ->assertStatus(403);
    }

    #[Test]
    public function history_includes_notes_when_present(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload(['notes' => 'Great morning']))->assertStatus(201);

        $response = $this->getJson('/api/v1/mobile/check-ins/history?from=2026-04-19&to=2026-04-19')
            ->assertStatus(200);

        $this->assertEquals('Great morning', $response->json('days.0.morning.notes'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'period' => 'morning',
            'physical' => 4,
            'mental' => 3,
            'date' => '2026-04-19',
        ], $overrides);
    }

    protected function actingAsUserWithIntegration(): Integration
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'daily_checkin',
        ]);

        return Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'daily_checkin',
            'integration_group_id' => $group->id,
        ]);
    }
}
