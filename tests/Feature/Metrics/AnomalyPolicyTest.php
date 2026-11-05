<?php

namespace Tests\Feature\Metrics;

use App\Models\MetricStatistic;
use App\Services\Metrics\AnomalyPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnomalyPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_low_value_is_raised_when_higher_is_better(): void
    {
        $verdict = app(AnomalyPolicy::class)->judge($this->statistic('oura', 'had_readiness_score'), 60.0);

        $this->assertSame('anomaly_low', $verdict['type']);
        $this->assertEqualsWithDelta(4.0, $verdict['deviation'], 0.0001);
    }

    #[Test]
    public function a_move_in_the_good_direction_is_not_an_anomaly(): void
    {
        $this->assertNull(app(AnomalyPolicy::class)->judge($this->statistic('oura', 'had_readiness_score'), 140.0));
    }

    #[Test]
    public function a_metric_with_no_declared_polarity_is_raised_both_ways(): void
    {
        $statistic = $this->statistic('apple_health', 'had_respiratory_rate');

        $this->assertSame('anomaly_high', app(AnomalyPolicy::class)->judge($statistic, 150.0)['type']);
        $this->assertSame('anomaly_low', app(AnomalyPolicy::class)->judge($statistic, 50.0)['type']);
    }

    #[Test]
    public function a_metric_that_is_not_listed_is_never_judged(): void
    {
        $this->assertNull(app(AnomalyPolicy::class)->judge($this->statistic('untappd', 'drank'), 500.0));
        $this->assertNull(app(AnomalyPolicy::class)->judge($this->statistic('apple_health', 'had_active_energy'), 10000.0));
    }

    #[Test]
    public function a_duplicate_of_a_preferred_source_is_not_judged(): void
    {
        $this->assertNull(app(AnomalyPolicy::class)->judge($this->statistic('apple_health', 'had_heart_rate'), 200.0));
    }

    #[Test]
    public function zero_spread_judges_nothing_and_does_not_divide_by_zero(): void
    {
        $statistic = $this->statistic('apple_health', 'had_respiratory_rate', [
            'stddev_value' => 0,
            'normal_lower_bound' => 100,
            'normal_upper_bound' => 100,
        ]);

        $this->assertNull(app(AnomalyPolicy::class)->judge($statistic, 101.0));
    }

    #[Test]
    public function ordinal_levels_are_judged_by_bands(): void
    {
        $statistic = $this->statistic('oura', 'had_stress_score', [
            'mean_value' => 3.2,
            'stddev_value' => 0.5,
            'normal_lower_bound' => 2.2,
            'normal_upper_bound' => 4.2,
        ]);

        // Stress: higher is worse. One band above the usual is ordinary variation.
        $this->assertNull(app(AnomalyPolicy::class)->judge($statistic, 4.0));
        $this->assertSame('anomaly_high', app(AnomalyPolicy::class)->judge($statistic, 5.0)['type']);
        // Two bands below the usual is the good direction.
        $this->assertNull(app(AnomalyPolicy::class)->judge($statistic, 1.0));
    }

    #[Test]
    public function a_single_day_needs_three_spreads_and_a_long_streak_is_noise(): void
    {
        $policy = app(AnomalyPolicy::class);

        $this->assertTrue($policy->isNoise(2.4, 1));
        $this->assertFalse($policy->isNoise(3.2, 1));
        $this->assertFalse($policy->isNoise(2.4, 3));
        $this->assertTrue($policy->isNoise(5.0, 7));
    }

    private function statistic(string $service, string $action, array $overrides = []): MetricStatistic
    {
        return MetricStatistic::factory()->create($overrides + [
            'service' => $service,
            'action' => $action,
            'mean_value' => 100,
            'stddev_value' => 10,
            'normal_lower_bound' => 80,
            'normal_upper_bound' => 120,
            'event_count' => 60,
        ]);
    }
}
