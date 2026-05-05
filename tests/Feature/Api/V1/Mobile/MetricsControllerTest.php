<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\MetricStatistic;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetricsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);

        $this->user = User::factory()->create();

        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
        ]);
    }

    #[Test]
    public function requires_authentication(): void
    {
        $this->getJson('/api/v1/mobile/metrics')->assertStatus(401);
        $this->getJson('/api/v1/mobile/metrics/oura.sleep_score')->assertStatus(401);
    }

    #[Test]
    public function lists_all_metrics_for_user(): void
    {
        $this->seedMetric();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/metrics')
            ->assertOk()
            ->assertJsonStructure([
                '*' => [
                    'id', 'identifier', 'display_name', 'service',
                    'domain', 'action', 'unit', 'event_count', 'mean', 'last_event_at',
                ],
            ])
            ->assertJsonPath('0.identifier', 'oura.sleep_score')
            ->assertJsonPath('0.service', 'oura')
            ->assertJsonPath('0.domain', 'health')
            ->assertJsonPath('0.action', 'had_sleep_score');
    }

    #[Test]
    public function lists_empty_data_when_no_metrics_exist(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/metrics')
            ->assertOk()
            ->assertExactJson([]);
    }

    #[Test]
    public function returns_trend_payload_with_baseline(): void
    {
        $this->seedMetric();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/metrics/oura.sleep_score?from=today&to=today')
            ->assertOk()
            ->assertJsonStructure([
                'metric', 'service', 'action', 'unit',
                'range' => ['from', 'to'],
                'daily_values',
                'summary',
                'baseline' => ['mean', 'stddev', 'normal_lower', 'normal_upper', 'sample_days'],
            ])
            ->assertJsonPath('service', 'oura')
            ->assertJsonPath('action', 'had_sleep_score');
    }

    #[Test]
    public function returns_trend_payload_for_range_query(): void
    {
        $this->seedMetric();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        Carbon::setTestNow('2026-05-03 12:00:00');

        try {
            $this->getJson('/api/v1/mobile/metrics/oura.sleep_score?range=7d')
                ->assertOk()
                ->assertJsonPath('range.from', '2026-04-27')
                ->assertJsonPath('range.to', '2026-05-03');
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function returns_404_for_unknown_metric(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/metrics/bogus.metric')
            ->assertStatus(404)
            ->assertJsonStructure(['message', 'hint']);
    }

    #[Test]
    public function etag_returns_304_on_match(): void
    {
        $this->seedMetric();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $first = $this->getJson('/api/v1/mobile/metrics/oura.sleep_score?from=today&to=today')
            ->assertOk();
        $etag = $first->headers->get('ETag');

        $this->getJson(
            '/api/v1/mobile/metrics/oura.sleep_score?from=today&to=today',
            ['If-None-Match' => $etag],
        )->assertStatus(304);
    }

    protected function seedMetric(): void
    {
        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
            'mean_value' => 80,
            'stddev_value' => 5,
            'normal_lower_bound' => 70,
            'normal_upper_bound' => 90,
            'event_count' => 100,
        ]);

        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value' => 85,
            'value_multiplier' => 1,
            'value_unit' => 'percent',
            'time' => Carbon::today()->setHour(8),
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);
    }
}
