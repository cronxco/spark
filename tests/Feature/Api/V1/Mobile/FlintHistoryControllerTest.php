<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlintHistoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true, 'app.enable_task_pipeline' => false]);
        Carbon::setTestNow('2026-09-14 12:00:00');
        $this->user = User::factory()->create();
        $this->user->setTimezone('Europe/London');
        $this->integration = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'flint']);
        Sanctum::actingAs($this->user, ['ios:read']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function range_history_is_cursor_paginated_and_list_safe(): void
    {
        foreach (['2026-09-12', '2026-09-13', '2026-09-14'] as $date) {
            Event::factory()->create([
                'integration_id' => $this->integration->id,
                'service' => 'flint',
                'action' => 'had_summary',
                'time' => Carbon::parse("{$date} 08:00", 'Europe/London')->utc(),
                'event_metadata' => ['local_date' => $date, 'period' => 'morning', 'title' => "Digest {$date}"],
            ]);
        }

        $first = $this->getJson('/api/v1/mobile/flint/digests?from=2026-09-12&to=2026-09-14&limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.blocks');
        $this->assertNotNull($first->json('next_cursor'));
        $first->assertJsonPath('has_more', true);

        $this->getJson('/api/v1/mobile/flint/digests?from=2026-09-12&to=2026-09-14&limit=2&cursor=' . urlencode($first->json('next_cursor')))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('has_more', false);
    }

    #[Test]
    public function invalid_and_empty_ranges_use_the_new_contract(): void
    {
        $this->getJson('/api/v1/mobile/flint/digests?from=2026-08-15&to=2026-09-14')->assertUnprocessable();
        $this->getJson('/api/v1/mobile/flint/digests?from=2027-01-01&to=2027-01-02')->assertUnprocessable();
        $this->getJson('/api/v1/mobile/flint/digests?from=2026-09-01&to=2026-09-02')
            ->assertOk()
            ->assertExactJson([
                'data' => [],
                'next_cursor' => null,
                'has_more' => false,
                'meta' => [
                    'from' => '2026-09-01',
                    'to' => '2026-09-02',
                    'effective_timezone' => 'Europe/London',
                    'account_id' => (string) $this->user->id,
                ],
            ]);
    }
}
