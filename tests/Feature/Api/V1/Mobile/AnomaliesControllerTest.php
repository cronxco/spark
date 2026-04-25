<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnomaliesControllerTest extends TestCase
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
    public function acknowledge_requires_write_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson('/api/v1/mobile/anomalies/00000000-0000-0000-0000-000000000000/acknowledge')
            ->assertStatus(403);
    }

    #[Test]
    public function acknowledge_marks_anomaly_as_acknowledged(): void
    {
        $stat = MetricStatistic::factory()->create(['user_id' => $this->user->id]);
        $anomaly = MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_high',
            'acknowledged_at' => null,
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/anomalies/' . $anomaly->id . '/acknowledge', [
            'note' => 'Was sick',
        ])->assertOk()->assertJsonPath('acknowledged', true);

        $fresh = $anomaly->fresh();
        $this->assertNotNull($fresh->acknowledged_at);
        $this->assertEquals('Was sick', $fresh->metadata['acknowledgement_note'] ?? null);
    }

    #[Test]
    public function acknowledge_denies_another_users_anomaly(): void
    {
        $other = User::factory()->create();
        $stat = MetricStatistic::factory()->create(['user_id' => $other->id]);
        $anomaly = MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_high',
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/anomalies/' . $anomaly->id . '/acknowledge')
            ->assertStatus(404);
    }

    #[Test]
    public function acknowledge_returns_404_when_missing(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/anomalies/00000000-0000-0000-0000-000000000000/acknowledge')
            ->assertStatus(404);
    }
}
