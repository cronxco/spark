<?php

namespace Tests\Feature\TaskPipeline;

use App\Jobs\TaskPipeline\Tasks\DetectTrendsTask;
use App\Models\Event;
use App\Models\Integration;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Services\TaskPipeline\TaskDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DetectTrendsTaskTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function does_nothing_when_event_has_no_value(): void
    {
        $integration = Integration::factory()->create();
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'value' => null,
            'value_unit' => null,
        ]);

        $job = new DetectTrendsTask($event, $this->makeTask());
        $job->handle();

        $this->assertDatabaseCount('metric_trends', 0);
    }

    #[Test]
    public function does_nothing_when_no_metric_statistic_exists(): void
    {
        $integration = Integration::factory()->create();
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
            'value' => 80,
            'value_multiplier' => 1,
            'value_unit' => 'percent',
        ]);

        $job = new DetectTrendsTask($event, $this->makeTask());
        $job->handle();

        $this->assertDatabaseCount('metric_trends', 0);
    }

    #[Test]
    public function creates_weekly_trend_when_significant_change_detected(): void
    {
        $integration = Integration::factory()->create();

        $metric = MetricStatistic::factory()->create([
            'user_id' => $integration->user_id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
            'value_unit' => 'percent',
            'mean_value' => 70.0,
            'stddev_value' => 5.0,
            'normal_lower_bound' => 60.0,
            'normal_upper_bound' => 80.0,
        ]);

        // Create historical events (4+ weeks ago) with low values
        for ($i = 5; $i <= 8; $i++) {
            Event::factory()->create([
                'integration_id' => $integration->id,
                'service' => 'oura',
                'action' => 'had_readiness_score',
                'value_unit' => 'percent',
                'value' => 60,
                'value_multiplier' => 1,
                'time' => now()->subWeeks($i),
            ]);
        }

        // Create current week events with significantly higher values
        for ($i = 1; $i <= 3; $i++) {
            Event::factory()->create([
                'integration_id' => $integration->id,
                'service' => 'oura',
                'action' => 'had_readiness_score',
                'value_unit' => 'percent',
                'value' => 85,
                'value_multiplier' => 1,
                'time' => now()->startOfWeek()->addDays($i),
            ]);
        }

        $triggerEvent = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
            'value_unit' => 'percent',
            'value' => 85,
            'value_multiplier' => 1,
            'time' => now(),
        ]);

        $job = new DetectTrendsTask($triggerEvent, $this->makeTask());
        $job->handle();

        $this->assertDatabaseHas('metric_trends', [
            'metric_statistic_id' => $metric->id,
            'type' => 'trend_up_weekly',
        ]);
    }

    #[Test]
    public function does_not_create_duplicate_unacknowledged_trend(): void
    {
        $integration = Integration::factory()->create();

        $metric = MetricStatistic::factory()->create([
            'user_id' => $integration->user_id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
            'value_unit' => 'percent',
            'mean_value' => 70.0,
            'stddev_value' => 5.0,
            'normal_lower_bound' => 60.0,
            'normal_upper_bound' => 80.0,
        ]);

        // Pre-existing unacknowledged trend for this week
        MetricTrend::factory()->create([
            'metric_statistic_id' => $metric->id,
            'type' => 'trend_up_weekly',
            'acknowledged_at' => null,
            'start_date' => now()->startOfWeek()->toDateString(),
        ]);

        // Create historical + current week data to trigger the same trend
        for ($i = 5; $i <= 8; $i++) {
            Event::factory()->create([
                'integration_id' => $integration->id,
                'service' => 'oura',
                'action' => 'had_readiness_score',
                'value_unit' => 'percent',
                'value' => 60,
                'value_multiplier' => 1,
                'time' => now()->subWeeks($i),
            ]);
        }

        $triggerEvent = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
            'value_unit' => 'percent',
            'value' => 85,
            'value_multiplier' => 1,
            'time' => now()->startOfWeek()->addDay(),
        ]);

        $job = new DetectTrendsTask($triggerEvent, $this->makeTask());
        $job->handle();

        // Weekly trend count unchanged — no duplicate created for the same period
        $this->assertEquals(1, MetricTrend::where('type', 'trend_up_weekly')->count());
    }

    protected function makeTask(): TaskDefinition
    {
        return new TaskDefinition(
            key: 'detect_trends',
            name: 'Detect Trends',
            description: 'Detect metric trends',
            jobClass: DetectTrendsTask::class,
            appliesTo: ['event'],
        );
    }
}
