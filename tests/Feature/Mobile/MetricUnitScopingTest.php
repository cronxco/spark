<?php

namespace Tests\Feature\Mobile;

use App\Models\Event;
use App\Models\Integration;
use App\Models\MetricStatistic;
use App\Models\User;
use App\Services\Mobile\HealthDashboardService;
use App\Services\Mobile\MetricTrendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A MetricStatistic is identified by service + action + value_unit, so an
 * action reported in two units has two baselines. These tests pin the readers
 * to the right one — which matters more now that presentation branches on
 * whether the resolved statistic is ordinal.
 */
class MetricUnitScopingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
        ]);
    }

    #[Test]
    public function trend_ignores_events_recorded_in_another_unit(): void
    {
        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_resilience_score',
            'value_unit' => 'resilience_level',
            'event_count' => 30,
            'mean_value' => 3.19,
            'stddev_value' => 0.49,
            'normal_lower_bound' => 2.2,
            'normal_upper_bound' => 4.17,
        ]);

        $this->createMetricEvent('had_resilience_score', 'resilience_level', 3, now()->subDay());
        $this->createMetricEvent('had_resilience_score', 'percent', 62, now());

        $trend = app(MetricTrendService::class)->trend(
            $this->user,
            'oura.had_resilience_score.resilience_level',
        );

        $this->assertNotNull($trend);
        $this->assertSame('resilience_level', $trend['unit']);
        $this->assertCount(1, $trend['daily_values']);
        $this->assertEqualsWithDelta(3.0, $trend['daily_values'][0]['value'], 0.001);
        $this->assertArrayNotHasKey('vs_baseline_pct', $trend['daily_values'][0]);
        $this->assertTrue($trend['is_ordinal']);
    }

    #[Test]
    public function trend_still_returns_values_for_a_unitless_metric(): void
    {
        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
            'value_unit' => 'percent',
            'event_count' => 30,
            'mean_value' => 80.0,
            'stddev_value' => 5.0,
            'normal_lower_bound' => 70.0,
            'normal_upper_bound' => 90.0,
        ]);

        $this->createMetricEvent('had_readiness_score', 'percent', 85, now());

        $trend = app(MetricTrendService::class)->trend($this->user, 'oura.had_readiness_score.percent');

        $this->assertNotNull($trend);
        $this->assertCount(1, $trend['daily_values']);
        $this->assertSame(6.3, $trend['daily_values'][0]['vs_baseline_pct']);
        $this->assertFalse($trend['is_ordinal']);
    }

    #[Test]
    public function health_dashboard_prefers_the_configured_unit_over_the_latest_event(): void
    {
        $this->createMetricEvent('had_resilience_score', 'percent', 62, now());
        $this->createMetricEvent('had_resilience_score', 'resilience_level', 2, now()->subHours(2));

        $event = $this->invokeFirstMetricEvent([
            'label' => 'Resilience',
            'service' => 'oura',
            'action' => 'had_resilience_score',
            'unit' => 'resilience_level',
        ]);

        $this->assertNotNull($event);
        $this->assertSame('resilience_level', $event->value_unit);
    }

    #[Test]
    public function health_dashboard_falls_back_when_no_event_carries_the_configured_unit(): void
    {
        $this->createMetricEvent('had_resilience_score', 'percent', 62, now());

        $event = $this->invokeFirstMetricEvent([
            'label' => 'Resilience',
            'service' => 'oura',
            'action' => 'had_resilience_score',
            'unit' => 'resilience_level',
        ]);

        $this->assertNotNull($event);
        $this->assertSame('percent', $event->value_unit);
    }

    /**
     * @param  array{label: string, service: string, action: string, unit: string}  $config
     */
    private function invokeFirstMetricEvent(array $config): ?Event
    {
        $events = Event::query()->where('service', 'oura')->get();

        $method = new \ReflectionMethod(HealthDashboardService::class, 'firstMetricEvent');

        return $method->invoke(app(HealthDashboardService::class), $events, $config);
    }

    private function createMetricEvent(string $action, string $unit, float $value, \DateTimeInterface $time): Event
    {
        return Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => $action,
            'value' => $value,
            'value_multiplier' => 1,
            'value_unit' => $unit,
            'time' => $time,
            'event_metadata' => [],
        ]);
    }
}
