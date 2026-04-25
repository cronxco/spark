<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SyncControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
        $this->user = User::factory()->create();
        $this->integration = Integration::factory()->create(['user_id' => $this->user->id]);
    }

    #[Test]
    public function delta_requires_authentication(): void
    {
        $this->getJson('/api/v1/mobile/sync/delta')->assertStatus(401);
    }

    #[Test]
    public function delta_returns_empty_arrays_when_nothing_changed(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $cursor = now()->addMinute()->toIso8601String();

        $this->getJson('/api/v1/mobile/sync/delta?since=' . urlencode($cursor))
            ->assertOk()
            ->assertJsonPath('created', [])
            ->assertJsonPath('updated', [])
            ->assertJsonPath('deleted', []);
    }

    #[Test]
    public function delta_returns_created_events_after_cursor(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $cursor = now()->subMinute();
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'time' => now(),
        ]);

        $response = $this->getJson('/api/v1/mobile/sync/delta?since=' . urlencode($cursor->toIso8601String()))
            ->assertOk();

        $this->assertCount(1, $response->json('created'));
        $this->assertNotEmpty($response->json('next_cursor'));
    }

    #[Test]
    public function delta_reports_deleted_events_with_their_ids(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'time' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        $cursor = now()->subMinutes(5)->toIso8601String();
        $event->delete();

        $response = $this->getJson('/api/v1/mobile/sync/delta?since=' . urlencode($cursor))
            ->assertOk();

        $this->assertContains($event->id, $response->json('deleted'));
    }
}
