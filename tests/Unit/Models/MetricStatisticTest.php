<?php

namespace Tests\Unit\Models;

use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetricStatisticTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function it_belongs_to_a_user(): void
    {
        $user = User::factory()->create();
        $metric = MetricStatistic::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $metric->user);
        $this->assertEquals($user->id, $metric->user->id);
    }

    /**
     * @test
     */
    public function it_has_many_trends(): void
    {
        $metric = MetricStatistic::factory()->create();
        MetricTrend::factory()->count(3)->create(['metric_statistic_id' => $metric->id]);

        $this->assertCount(3, $metric->trends);
        $this->assertInstanceOf(MetricTrend::class, $metric->trends->first());
    }

    /**
     * @test
     */
    public function with_sufficient_data_scope_filters_metrics_with_30_days_data(): void
    {
        // Metric with insufficient time range
        MetricStatistic::factory()->create([
            'first_event_at' => now()->subDays(20),
            'last_event_at' => now(),
        ]);

        // Metric with sufficient time range
        $validMetric = MetricStatistic::factory()->create([
            'first_event_at' => now()->subDays(40),
            'last_event_at' => now(),
        ]);

        $results = MetricStatistic::withSufficientData()->get();

        $this->assertCount(1, $results);
        $this->assertEquals($validMetric->id, $results->first()->id);
    }

    /**
     * @test
     */
    public function needs_recalculation_scope_returns_metrics_never_calculated(): void
    {
        $neverCalculated = MetricStatistic::factory()->create(['last_calculated_at' => null]);
        MetricStatistic::factory()->create(['last_calculated_at' => now()]);

        $results = MetricStatistic::needsRecalculation()->get();

        $this->assertCount(1, $results);
        $this->assertEquals($neverCalculated->id, $results->first()->id);
    }

    /**
     * @test
     */
    public function needs_recalculation_scope_returns_metrics_calculated_over_an_hour_ago(): void
    {
        $oldCalculation = MetricStatistic::factory()->create([
            'last_calculated_at' => now()->subHours(2),
        ]);
        MetricStatistic::factory()->create(['last_calculated_at' => now()->subMinutes(30)]);

        $results = MetricStatistic::needsRecalculation()->get();

        $this->assertCount(1, $results);
        $this->assertEquals($oldCalculation->id, $results->first()->id);
    }

    /**
     * @test
     */
    public function get_display_name_formats_action_properly(): void
    {
        $metric = MetricStatistic::factory()->create(['action' => 'had_readiness_score']);

        $displayName = $metric->getDisplayName();

        $this->assertIsString($displayName);
        $this->assertNotEquals('had_readiness_score', $displayName);
    }

    /**
     * @test
     */
    public function get_identifier_returns_correct_format(): void
    {
        $metric = MetricStatistic::factory()->create([
            'service' => 'oura',
            'action' => 'had_readiness_score',
            'value_unit' => 'percent',
        ]);

        $identifier = $metric->getIdentifier();

        $this->assertEquals('oura.had_readiness_score.percent', $identifier);
    }

    /**
     * @test
     */
    public function has_valid_statistics_returns_true_with_complete_data(): void
    {
        $metric = MetricStatistic::factory()->create([
            'event_count' => 50,
            'mean_value' => 75.5,
            'stddev_value' => 10.2,
            'normal_lower_bound' => 55.1,
            'normal_upper_bound' => 95.9,
        ]);

        $this->assertTrue($metric->hasValidStatistics());
    }

    /**
     * @test
     */
    public function has_valid_statistics_returns_false_with_insufficient_events(): void
    {
        $metric = MetricStatistic::factory()->create([
            'event_count' => 5,
            'mean_value' => 75.5,
            'stddev_value' => 10.2,
            'normal_lower_bound' => 55.1,
            'normal_upper_bound' => 95.9,
        ]);

        $this->assertFalse($metric->hasValidStatistics());
    }

    /**
     * @test
     */
    public function has_valid_statistics_returns_false_with_missing_statistics(): void
    {
        $metric = MetricStatistic::factory()->create([
            'event_count' => 50,
            'mean_value' => null,
            'stddev_value' => null,
            'normal_lower_bound' => null,
            'normal_upper_bound' => null,
        ]);

        $this->assertFalse($metric->hasValidStatistics());
    }
}
