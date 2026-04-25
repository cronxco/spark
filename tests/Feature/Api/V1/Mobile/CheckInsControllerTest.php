<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
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

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'period' => 'morning',
            'physical' => 4,
            'mental' => 3,
            'date' => '2026-04-19',
        ], $overrides);
    }
}
