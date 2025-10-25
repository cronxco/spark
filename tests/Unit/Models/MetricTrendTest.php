<?php

namespace Tests\Unit\Models;

use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetricTrendTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function it_belongs_to_a_metric_statistic(): void
    {
        $metric = MetricStatistic::factory()->create();
        $trend = MetricTrend::factory()->create(['metric_statistic_id' => $metric->id]);

        $this->assertInstanceOf(MetricStatistic::class, $trend->metricStatistic);
        $this->assertEquals($metric->id, $trend->metricStatistic->id);
    }

    /**
     * @test
     */
    public function unacknowledged_scope_filters_correctly(): void
    {
        MetricTrend::factory()->create(['acknowledged_at' => now()]);
        $unacknowledged = MetricTrend::factory()->create(['acknowledged_at' => null]);

        $results = MetricTrend::unacknowledged()->get();

        $this->assertCount(1, $results);
        $this->assertEquals($unacknowledged->id, $results->first()->id);
    }

    /**
     * @test
     */
    public function acknowledged_scope_filters_correctly(): void
    {
        $acknowledged = MetricTrend::factory()->create(['acknowledged_at' => now()]);
        MetricTrend::factory()->create(['acknowledged_at' => null]);

        $results = MetricTrend::acknowledged()->get();

        $this->assertCount(1, $results);
        $this->assertEquals($acknowledged->id, $results->first()->id);
    }

    /**
     * @test
     */
    public function of_type_scope_filters_correctly(): void
    {
        $anomalyHigh = MetricTrend::factory()->create(['type' => 'anomaly_high']);
        MetricTrend::factory()->create(['type' => 'trend_up_weekly']);

        $results = MetricTrend::ofType('anomaly_high')->get();

        $this->assertCount(1, $results);
        $this->assertEquals($anomalyHigh->id, $results->first()->id);
    }

    /**
     * @test
     */
    public function anomalies_scope_returns_only_anomalies(): void
    {
        MetricTrend::factory()->create(['type' => 'anomaly_high']);
        MetricTrend::factory()->create(['type' => 'anomaly_low']);
        MetricTrend::factory()->create(['type' => 'trend_up_weekly']);

        $results = MetricTrend::anomalies()->get();

        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function trends_scope_excludes_anomalies(): void
    {
        MetricTrend::factory()->create(['type' => 'anomaly_high']);
        MetricTrend::factory()->create(['type' => 'trend_up_weekly']);
        MetricTrend::factory()->create(['type' => 'trend_down_monthly']);

        $results = MetricTrend::trends()->get();

        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function acknowledge_sets_acknowledged_at(): void
    {
        $trend = MetricTrend::factory()->create(['acknowledged_at' => null]);

        $this->assertNull($trend->acknowledged_at);

        $trend->acknowledge();

        $this->assertNotNull($trend->fresh()->acknowledged_at);
    }

    /**
     * @test
     */
    public function is_anomaly_returns_true_for_anomaly_types(): void
    {
        $anomalyHigh = MetricTrend::factory()->create(['type' => 'anomaly_high']);
        $anomalyLow = MetricTrend::factory()->create(['type' => 'anomaly_low']);

        $this->assertTrue($anomalyHigh->isAnomaly());
        $this->assertTrue($anomalyLow->isAnomaly());
    }

    /**
     * @test
     */
    public function is_anomaly_returns_false_for_trend_types(): void
    {
        $trend = MetricTrend::factory()->create(['type' => 'trend_up_weekly']);

        $this->assertFalse($trend->isAnomaly());
    }

    /**
     * @test
     */
    public function is_trend_returns_true_for_trend_types(): void
    {
        $trend = MetricTrend::factory()->create(['type' => 'trend_up_weekly']);

        $this->assertTrue($trend->isTrend());
    }

    /**
     * @test
     */
    public function get_type_label_returns_human_readable_labels(): void
    {
        $anomalyHigh = MetricTrend::factory()->create(['type' => 'anomaly_high']);
        $trendUp = MetricTrend::factory()->create(['type' => 'trend_up_weekly']);

        $this->assertEquals('Significantly High', $anomalyHigh->getTypeLabel());
        $this->assertEquals('Trending Up (Weekly)', $trendUp->getTypeLabel());
    }

    /**
     * @test
     */
    public function get_direction_returns_correct_direction_for_upward_trends(): void
    {
        $trendUp = MetricTrend::factory()->create(['type' => 'trend_up_weekly']);
        $anomalyHigh = MetricTrend::factory()->create(['type' => 'anomaly_high']);

        $this->assertEquals('up', $trendUp->getDirection());
        $this->assertEquals('up', $anomalyHigh->getDirection());
    }

    /**
     * @test
     */
    public function get_direction_returns_correct_direction_for_downward_trends(): void
    {
        $trendDown = MetricTrend::factory()->create(['type' => 'trend_down_monthly']);
        $anomalyLow = MetricTrend::factory()->create(['type' => 'anomaly_low']);

        $this->assertEquals('down', $trendDown->getDirection());
        $this->assertEquals('down', $anomalyLow->getDirection());
    }
}
