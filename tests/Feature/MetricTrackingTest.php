<?php

namespace Tests\Feature;

use App\Jobs\Metrics\CalculateMetricStatisticsJob;
use App\Jobs\Metrics\DetectMetricAnomaliesJob;
use App\Jobs\Metrics\DetectMetricTrendsJob;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Jobs\TaskPipeline\Tasks\CalculateMetricStatsTask;
use App\Jobs\TaskPipeline\Tasks\DetectAnomaliesTask;
use App\Mcp\Tools\GetBaselinesTool;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use App\Services\Mobile\AnomalyAcknowledgement;
use App\Services\TaskPipeline\TaskRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetricTrackingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function event_creation_dispatches_task_pipeline_and_anomaly_detection(): void
    {
        // Enable task pipeline for this test
        config(['app.enable_task_pipeline' => true]);

        Queue::fake();

        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);

        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        $event = Event::create([
            'source_id' => 'test-123',
            'time' => now(),
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_readiness_score',
            'value' => 85,
            'value_multiplier' => 1,
            'value_unit' => 'percent',
            'target_id' => $target->id,
        ]);

        // Verify ProcessTaskPipelineJob was dispatched
        Queue::assertPushed(ProcessTaskPipelineJob::class, function ($job) use ($event) {
            return $job->model->id === $event->id && $job->trigger === 'created';
        });

        // Phase 1: initial pipeline — dispatches CalculateMetricStatsTask;
        // DetectAnomaliesTask is held waiting because its dependency hasn't completed yet.
        $pipelineJob = new ProcessTaskPipelineJob($event, 'created');
        $pipelineJob->handle();

        Queue::assertPushed(CalculateMetricStatsTask::class, function ($job) use ($event) {
            return $job->model->id === $event->id;
        });

        // Phase 2: simulate CalculateMetricStatsTask completing — marks the task succeeded
        // and dispatches a secondary pipeline for dependent tasks.
        $statsTaskDef = TaskRegistry::getTask('calculate_metric_stats');
        $statsJob = new CalculateMetricStatsTask($event->fresh(), $statsTaskDef);
        $statsJob->handle();

        // Phase 3: run the secondary pipeline — dependency is now satisfied,
        // so DetectAnomaliesTask is dispatched.
        $dependentPipelineJob = new ProcessTaskPipelineJob(
            $event->fresh(),
            'manual',
            TaskRegistry::getDependentTaskKeys('calculate_metric_stats')
        );
        $dependentPipelineJob->handle();

        // Verify DetectAnomaliesTask was dispatched by the pipeline
        Queue::assertPushed(DetectAnomaliesTask::class, function ($job) use ($event) {
            return $job->model->id === $event->id;
        });
    }

    #[Test]
    public function calculate_metric_statistics_job_creates_statistics(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);

        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        // Create 40 events over 40 days with varying values
        for ($i = 0; $i < 40; $i++) {
            Event::create([
                'source_id' => 'test-' . $i,
                'time' => now()->subDays(40 - $i),
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'service' => 'oura',
                'domain' => 'health',
                'action' => 'had_readiness_score',
                'value' => 70 + ($i % 20), // Values between 70-90
                'value_multiplier' => 1,
                'value_unit' => 'percent',
                'target_id' => $target->id,
            ]);
        }

        $job = new CalculateMetricStatisticsJob;
        $job->handle();

        $this->assertDatabaseHas('metric_statistics', [
            'user_id' => $user->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
            'value_unit' => 'percent',
        ]);

        $metric = MetricStatistic::where('user_id', $user->id)->first();
        $this->assertEquals(40, $metric->event_count);

        // Values are 70..89 repeated twice → mean = 79.5, min = 70, max = 89.
        $this->assertEqualsWithDelta(79.5, (float) $metric->mean_value, 0.0001);
        $this->assertEqualsWithDelta(70.0, (float) $metric->min_value, 0.0001);
        $this->assertEqualsWithDelta(89.0, (float) $metric->max_value, 0.0001);

        // Population stddev of 70..89 twice is sqrt(variance). Compute it here
        // to pin the value independent of SQL implementation choices.
        $values = [];
        for ($i = 0; $i < 40; $i++) {
            $values[] = 70 + ($i % 20);
        }
        $mean = array_sum($values) / count($values);
        $expectedStddev = sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / count($values));

        $this->assertEqualsWithDelta($expectedStddev, (float) $metric->stddev_value, 0.0001);
        $this->assertEqualsWithDelta($mean - 2 * $expectedStddev, (float) $metric->normal_lower_bound, 0.0001);
        $this->assertEqualsWithDelta($mean + 2 * $expectedStddev, (float) $metric->normal_upper_bound, 0.0001);
    }

    #[Test]
    public function both_calculation_paths_produce_identical_statistics_with_zero_values(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        // 10 zero-value events (days 31–40 ago) + 30 non-zero events (days 1–30 ago).
        // Both paths should exclude the zeros and agree on the non-zero statistics.
        for ($i = 0; $i < 10; $i++) {
            Event::create([
                'source_id' => 'zero-' . $i,
                'time' => now()->subDays(40 - $i),
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'target_id' => $target->id,
                'service' => 'oura',
                'domain' => 'health',
                'action' => 'had_readiness_score',
                'value' => 0,
                'value_multiplier' => 1,
                'value_unit' => 'percent',
            ]);
        }

        $lastEvent = null;
        for ($i = 0; $i < 30; $i++) {
            $lastEvent = Event::create([
                'source_id' => 'nonzero-' . $i,
                'time' => now()->subDays(30 - $i),
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'target_id' => $target->id,
                'service' => 'oura',
                'domain' => 'health',
                'action' => 'had_readiness_score',
                'value' => 70 + ($i % 20), // Values 70–89
                'value_multiplier' => 1,
                'value_unit' => 'percent',
            ]);
        }

        // Run the task-pipeline path on the most recent event.
        $statsTaskDef = TaskRegistry::getTask('calculate_metric_stats');
        $statsJob = new CalculateMetricStatsTask($lastEvent->fresh(), $statsTaskDef);
        $statsJob->handle();

        $taskStat = MetricStatistic::where('user_id', $user->id)->first();
        $this->assertNotNull($taskStat, 'Task pipeline should produce a MetricStatistic');
        $this->assertEquals(30, $taskStat->event_count, 'Zero events must be excluded from the count');

        // Capture task-pipeline values.
        $taskMean = (float) $taskStat->mean_value;
        $taskStddev = (float) $taskStat->stddev_value;
        $taskMin = (float) $taskStat->min_value;
        $taskMax = (float) $taskStat->max_value;
        $taskLower = (float) $taskStat->normal_lower_bound;
        $taskUpper = (float) $taskStat->normal_upper_bound;

        // Delete the row so the batch job writes a fresh one.
        $taskStat->delete();

        // Run the batch job path.
        (new CalculateMetricStatisticsJob)->handle();

        $batchStat = MetricStatistic::where('user_id', $user->id)->first();
        $this->assertNotNull($batchStat, 'Batch job should produce a MetricStatistic');
        $this->assertEquals(30, $batchStat->event_count, 'Zero events must be excluded from the count');

        $delta = 0.0001;
        $this->assertEqualsWithDelta($taskMean, (float) $batchStat->mean_value, $delta);
        $this->assertEqualsWithDelta($taskStddev, (float) $batchStat->stddev_value, $delta);
        $this->assertEqualsWithDelta($taskMin, (float) $batchStat->min_value, $delta);
        $this->assertEqualsWithDelta($taskMax, (float) $batchStat->max_value, $delta);
        $this->assertEqualsWithDelta($taskLower, (float) $batchStat->normal_lower_bound, $delta);
        $this->assertEqualsWithDelta($taskUpper, (float) $batchStat->normal_upper_bound, $delta);
    }

    #[Test]
    public function financial_metrics_include_zeros_in_both_calculation_paths(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        // 10 zero-value events (days 31–40 ago) + 30 non-zero events (days 1–30 ago).
        // For money domain, zeros are legitimate and must be included.
        for ($i = 0; $i < 10; $i++) {
            Event::create([
                'source_id' => 'fin-zero-' . $i,
                'time' => now()->subDays(40 - $i),
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'target_id' => $target->id,
                'service' => 'monzo',
                'domain' => 'money',
                'action' => 'transaction',
                'value' => 0,
                'value_multiplier' => 1,
                'value_unit' => 'gbp',
            ]);
        }

        $lastEvent = null;
        for ($i = 0; $i < 30; $i++) {
            $lastEvent = Event::create([
                'source_id' => 'fin-nonzero-' . $i,
                'time' => now()->subDays(30 - $i),
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'target_id' => $target->id,
                'service' => 'monzo',
                'domain' => 'money',
                'action' => 'transaction',
                'value' => 100,
                'value_multiplier' => 1,
                'value_unit' => 'gbp',
            ]);
        }

        // Run the task-pipeline path.
        $statsTaskDef = TaskRegistry::getTask('calculate_metric_stats');
        $statsJob = new CalculateMetricStatsTask($lastEvent->fresh(), $statsTaskDef);
        $statsJob->handle();

        $taskStat = MetricStatistic::where('user_id', $user->id)->first();
        $this->assertNotNull($taskStat);
        $this->assertEquals(40, $taskStat->event_count, 'All 40 events including zeros must be counted for money domain');

        $taskMean = (float) $taskStat->mean_value;
        $taskStat->delete();

        // Run the batch job path.
        (new CalculateMetricStatisticsJob)->handle();

        $batchStat = MetricStatistic::where('user_id', $user->id)->first();
        $this->assertNotNull($batchStat);
        $this->assertEquals(40, $batchStat->event_count, 'All 40 events including zeros must be counted for money domain');
        $this->assertEqualsWithDelta($taskMean, (float) $batchStat->mean_value, 0.0001);
    }

    #[Test]
    public function calculate_metric_statistics_job_applies_value_multiplier(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);

        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        // 40 events with raw value = 100..199 (mod 100) + 100 and multiplier = 100.
        // With the accessor, formatted_value = value / 100, so effective values
        // are 1.00..1.99, mean = 1.495.
        for ($i = 0; $i < 40; $i++) {
            Event::create([
                'source_id' => 'mul-' . $i,
                'time' => now()->subDays(40 - $i),
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'target_id' => $target->id,
                'service' => 'monzo',
                'domain' => 'money',
                'action' => 'transaction',
                'value' => 100 + ($i % 100),
                'value_multiplier' => 100,
                'value_unit' => 'gbp',
            ]);
        }

        (new CalculateMetricStatisticsJob)->handle();

        $metric = MetricStatistic::where('user_id', $user->id)->first();

        $this->assertNotNull($metric);
        $this->assertEquals(40, $metric->event_count);

        // Recompute expected stats using the PHP accessor semantics.
        $values = [];
        for ($i = 0; $i < 40; $i++) {
            $values[] = (100 + ($i % 100)) / 100;
        }
        $expectedMean = array_sum($values) / count($values);

        $this->assertEqualsWithDelta($expectedMean, (float) $metric->mean_value, 0.0001);
        $this->assertEqualsWithDelta(min($values), (float) $metric->min_value, 0.0001);
        $this->assertEqualsWithDelta(max($values), (float) $metric->max_value, 0.0001);
    }

    #[Test]
    public function calculate_metric_statistics_job_handles_prefixed_table_aliases(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);

        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        for ($i = 0; $i < 40; $i++) {
            Event::create([
                'source_id' => 'prefixed-' . $i,
                'time' => now()->subDays(40 - $i),
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'target_id' => $target->id,
                'service' => 'apple_health',
                'domain' => 'health',
                'action' => 'had_apple_exercise_time',
                'value' => 20 + $i,
                'value_multiplier' => 1,
                'value_unit' => 'min',
            ]);
        }

        $connection = DB::connection();
        $originalPrefix = $connection->getTablePrefix();

        if ($originalPrefix === '') {
            DB::statement('CREATE TEMP VIEW dev_events AS SELECT * FROM events');
            DB::statement('CREATE TEMP VIEW dev_integrations AS SELECT * FROM integrations');
            DB::statement('CREATE TEMP TABLE dev_metric_statistics (LIKE metric_statistics INCLUDING DEFAULTS)');

            $connection->setTablePrefix('dev_');
        }

        try {
            $job = new class extends CalculateMetricStatisticsJob
            {
                public function calculate(string $userId, string $service, string $action, string $valueUnit): void
                {
                    $this->calculateMetricStatistics($userId, $service, $action, $valueUnit);
                }
            };

            $job->calculate($user->id, 'apple_health', 'had_apple_exercise_time', 'min');

            $this->assertDatabaseHas('metric_statistics', [
                'user_id' => $user->id,
                'service' => 'apple_health',
                'action' => 'had_apple_exercise_time',
                'value_unit' => 'min',
            ]);
        } finally {
            $connection->setTablePrefix($originalPrefix);
        }
    }

    #[Test]
    public function calculate_metric_statistics_job_skips_when_fewer_than_ten_events(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        // Only 5 events — below the threshold; no MetricStatistic should be written.
        for ($i = 0; $i < 5; $i++) {
            Event::create([
                'source_id' => 'few-' . $i,
                'time' => now()->subDays(40 - $i),
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'target_id' => $target->id,
                'service' => 'oura',
                'domain' => 'health',
                'action' => 'had_readiness_score',
                'value' => 80,
                'value_multiplier' => 1,
                'value_unit' => 'percent',
            ]);
        }

        (new CalculateMetricStatisticsJob)->handle();

        $this->assertDatabaseMissing('metric_statistics', [
            'user_id' => $user->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
        ]);
    }

    #[Test]
    public function calculate_metric_statistics_job_excludes_events_outside_rolling_window(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        // 20 old events outside the 90-day window with value = 100
        for ($i = 0; $i < 20; $i++) {
            Event::create([
                'source_id' => 'old-' . $i,
                'time' => now()->subDays(200 - $i),
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'target_id' => $target->id,
                'service' => 'oura',
                'domain' => 'health',
                'action' => 'had_readiness_score',
                'value' => 100,
                'value_multiplier' => 1,
                'value_unit' => 'percent',
            ]);
        }

        // 20 recent events inside the 90-day window with value = 70, spanning ~76 days
        for ($i = 0; $i < 20; $i++) {
            Event::create([
                'source_id' => 'new-' . $i,
                'time' => now()->subDays(85 - ($i * 4)),
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'target_id' => $target->id,
                'service' => 'oura',
                'domain' => 'health',
                'action' => 'had_readiness_score',
                'value' => 70,
                'value_multiplier' => 1,
                'value_unit' => 'percent',
            ]);
        }

        (new CalculateMetricStatisticsJob)->handle();

        $metric = MetricStatistic::where('user_id', $user->id)->first();
        $this->assertNotNull($metric);

        // Mean should be 70 (only window events), not ~85 (all-time mean)
        $this->assertEqualsWithDelta(70.0, (float) $metric->mean_value, 0.0001);
        $this->assertEquals(20, $metric->event_count);
        $this->assertEquals(MetricStatistic::DEFAULT_WINDOW_DAYS, $metric->baseline_window_days);
    }

    #[Test]
    public function calculate_metric_statistics_job_nulls_out_stale_stats_when_window_has_insufficient_data(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        // Existing stale MetricStatistic with valid all-time stats
        $metric = MetricStatistic::factory()->create([
            'user_id' => $user->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
            'value_unit' => 'percent',
            'mean_value' => 80,
            'stddev_value' => 5,
            'normal_lower_bound' => 70,
            'normal_upper_bound' => 90,
            'event_count' => 40,
            'last_calculated_at' => null, // force recalculation
        ]);

        // All events are outside the rolling window
        for ($i = 0; $i < 40; $i++) {
            Event::create([
                'source_id' => 'stale-' . $i,
                'time' => now()->subDays(200 - $i),
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'target_id' => $target->id,
                'service' => 'oura',
                'domain' => 'health',
                'action' => 'had_readiness_score',
                'value' => 80,
                'value_multiplier' => 1,
                'value_unit' => 'percent',
            ]);
        }

        (new CalculateMetricStatisticsJob)->handle();

        $metric->refresh();

        // Stats must be nulled out so anomaly detection cannot use stale bounds
        $this->assertNull($metric->mean_value);
        $this->assertNull($metric->stddev_value);
        $this->assertNull($metric->normal_lower_bound);
        $this->assertNull($metric->normal_upper_bound);
        $this->assertFalse($metric->hasValidStatistics());
    }

    #[Test]
    public function anomaly_detection_creates_trend_for_high_value(): void
    {
        $user = User::factory()->create();
        $metric = MetricStatistic::factory()->create([
            'user_id' => $user->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
            'value_unit' => 'percent',
            'mean_value' => 75,
            'stddev_value' => 5,
            'normal_lower_bound' => 65,
            'normal_upper_bound' => 85,
            'event_count' => 50,
        ]);

        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);

        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        // Create an event with a value significantly higher than normal
        $event = Event::create([
            'source_id' => 'anomaly-test',
            'time' => now(),
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_readiness_score',
            'value' => 95, // Much higher than upper bound of 85
            'value_multiplier' => 1,
            'value_unit' => 'percent',
            'target_id' => $target->id,
        ]);

        $job = new DetectMetricAnomaliesJob($event);
        $job->handle();

        $this->assertDatabaseHas('metric_trends', [
            'metric_statistic_id' => $metric->id,
            'type' => 'anomaly_high',
        ]);
    }

    #[Test]
    public function user_can_disable_metric_tracking(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->isMetricTrackingDisabled('oura', 'had_readiness_score', 'percent'));

        $user->disableMetricTracking('oura', 'had_readiness_score', 'percent');

        $this->assertTrue($user->isMetricTrackingDisabled('oura', 'had_readiness_score', 'percent'));
    }

    #[Test]
    public function user_can_enable_metric_tracking(): void
    {
        $user = User::factory()->create();
        $user->disableMetricTracking('oura', 'had_readiness_score', 'percent');

        $this->assertTrue($user->isMetricTrackingDisabled('oura', 'had_readiness_score', 'percent'));

        $user->enableMetricTracking('oura', 'had_readiness_score', 'percent');

        $this->assertFalse($user->isMetricTrackingDisabled('oura', 'had_readiness_score', 'percent'));
    }

    #[Test]
    public function metrics_overview_page_is_accessible(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('metrics.index'));

        $response->assertStatus(200);
    }

    #[Test]
    public function metric_detail_page_is_accessible(): void
    {
        $user = User::factory()->create();
        $metric = MetricStatistic::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('metrics.show', $metric));

        $response->assertStatus(200);
    }

    #[Test]
    public function user_cannot_view_another_users_metric(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $metric = MetricStatistic::factory()->create(['user_id' => $user2->id]);

        $response = $this->actingAs($user1)->get(route('metrics.show', $metric));

        $response->assertStatus(403);
    }

    #[Test]
    public function trend_can_be_acknowledged(): void
    {
        $trend = MetricTrend::factory()->create(['acknowledged_at' => null]);

        $this->assertNull($trend->acknowledged_at);

        $trend->acknowledge();

        $this->assertNotNull($trend->fresh()->acknowledged_at);
    }

    #[Test]
    public function user_can_set_anomaly_detection_mode_override(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->getAnomalyDetectionModeOverride('oura', 'had_readiness_score', 'percent'));

        $user->setAnomalyDetectionMode('oura', 'had_readiness_score', 'percent', 'retrospective');

        $this->assertEquals('retrospective', $user->getAnomalyDetectionModeOverride('oura', 'had_readiness_score', 'percent'));
    }

    #[Test]
    public function user_can_clear_anomaly_detection_mode_override(): void
    {
        $user = User::factory()->create();
        $user->setAnomalyDetectionMode('oura', 'had_readiness_score', 'percent', 'disabled');

        $this->assertEquals('disabled', $user->getAnomalyDetectionModeOverride('oura', 'had_readiness_score', 'percent'));

        $user->clearAnomalyDetectionModeOverride('oura', 'had_readiness_score', 'percent');

        $this->assertNull($user->getAnomalyDetectionModeOverride('oura', 'had_readiness_score', 'percent'));
    }

    #[Test]
    public function metric_statistic_can_delete_all_anomalies(): void
    {
        $user = User::factory()->create();
        $metric = MetricStatistic::factory()->create(['user_id' => $user->id]);

        // Create anomalies
        MetricTrend::factory()->create([
            'metric_statistic_id' => $metric->id,
            'type' => 'anomaly_high',
        ]);
        MetricTrend::factory()->create([
            'metric_statistic_id' => $metric->id,
            'type' => 'anomaly_low',
        ]);
        MetricTrend::factory()->create([
            'metric_statistic_id' => $metric->id,
            'type' => 'trend_up_weekly',
        ]);

        $this->assertEquals(3, $metric->trends()->count());

        $deletedCount = $metric->deleteAllAnomalies();

        $this->assertEquals(2, $deletedCount);
        $this->assertEquals(1, $metric->trends()->count());
        $this->assertEquals('trend_up_weekly', $metric->trends()->first()->type);
    }

    #[Test]
    public function anomaly_detection_skipped_when_user_override_is_disabled(): void
    {
        $user = User::factory()->create();
        $metric = MetricStatistic::factory()->create([
            'user_id' => $user->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
            'value_unit' => 'percent',
            'mean_value' => 75,
            'stddev_value' => 5,
            'normal_lower_bound' => 65,
            'normal_upper_bound' => 85,
            'event_count' => 50,
        ]);

        // Set user override to disabled
        $user->setAnomalyDetectionMode('oura', 'had_readiness_score', 'percent', 'disabled');

        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);

        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        // Create an event with anomalous value
        $event = Event::create([
            'source_id' => 'anomaly-test',
            'time' => now(),
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_readiness_score',
            'value' => 95, // Much higher than upper bound
            'value_multiplier' => 1,
            'value_unit' => 'percent',
            'target_id' => $target->id,
        ]);

        $job = new DetectMetricAnomaliesJob($event);
        $job->handle();

        // No anomaly should be created due to user override
        $this->assertEquals(0, MetricTrend::where('metric_statistic_id', $metric->id)->count());
    }

    // -------------------------------------------------------------------------
    // Baseline review flag (CRX-652)
    // -------------------------------------------------------------------------

    #[Test]
    public function monthly_trend_sets_baseline_reset_suggested_at(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        $metric = MetricStatistic::factory()->create([
            'user_id' => $user->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
            'value_unit' => 'percent',
            'baseline_reset_suggested_at' => null,
        ]);

        // 5 events in the comparison period (3 months back) with value 70
        $comparisonStart = now()->startOfMonth()->subMonths(3);
        for ($i = 0; $i < 5; $i++) {
            Event::create([
                'source_id' => 'monthly-comp-' . $i,
                'time' => $comparisonStart->copy()->addDays($i),
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'target_id' => $target->id,
                'service' => 'oura',
                'domain' => 'health',
                'action' => 'had_readiness_score',
                'value' => 70,
                'value_multiplier' => 1,
                'value_unit' => 'percent',
            ]);
        }

        // 5 events in the current month with value 90 (~29% higher — exceeds 15% threshold)
        $currentMonthStart = now()->startOfMonth();
        for ($i = 0; $i < 5; $i++) {
            Event::create([
                'source_id' => 'monthly-curr-' . $i,
                'time' => $currentMonthStart->copy()->addDays($i),
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'target_id' => $target->id,
                'service' => 'oura',
                'domain' => 'health',
                'action' => 'had_readiness_score',
                'value' => 90,
                'value_multiplier' => 1,
                'value_unit' => 'percent',
            ]);
        }

        $this->assertNull($metric->baseline_reset_suggested_at);

        (new DetectMetricTrendsJob)->handle();

        $metric->refresh();
        $this->assertNotNull($metric->baseline_reset_suggested_at);
    }

    #[Test]
    public function quarterly_trend_sets_baseline_reset_suggested_at(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        $metric = MetricStatistic::factory()->create([
            'user_id' => $user->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
            'value_unit' => 'percent',
            'baseline_reset_suggested_at' => null,
        ]);

        // 5 events 2 quarters back with value 70
        $comparisonStart = now()->startOfQuarter()->subQuarters(2);
        for ($i = 0; $i < 5; $i++) {
            Event::create([
                'source_id' => 'quarterly-comp-' . $i,
                'time' => $comparisonStart->copy()->addDays($i),
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'target_id' => $target->id,
                'service' => 'oura',
                'domain' => 'health',
                'action' => 'had_readiness_score',
                'value' => 70,
                'value_multiplier' => 1,
                'value_unit' => 'percent',
            ]);
        }

        // 5 events in the current quarter with value 90 (~29% higher)
        $currentQuarterStart = now()->startOfQuarter();
        for ($i = 0; $i < 5; $i++) {
            Event::create([
                'source_id' => 'quarterly-curr-' . $i,
                'time' => $currentQuarterStart->copy()->addDays($i),
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'target_id' => $target->id,
                'service' => 'oura',
                'domain' => 'health',
                'action' => 'had_readiness_score',
                'value' => 90,
                'value_multiplier' => 1,
                'value_unit' => 'percent',
            ]);
        }

        $this->assertNull($metric->baseline_reset_suggested_at);

        (new DetectMetricTrendsJob)->handle();

        $metric->refresh();
        $this->assertNotNull($metric->baseline_reset_suggested_at);
    }

    #[Test]
    public function acknowledging_last_sustained_trend_clears_baseline_reset_flag(): void
    {
        $metric = MetricStatistic::factory()->create([
            'baseline_reset_suggested_at' => now()->subDay(),
        ]);

        $trend = MetricTrend::factory()->create([
            'metric_statistic_id' => $metric->id,
            'type' => 'trend_up_monthly',
            'acknowledged_at' => null,
        ]);

        $trend->acknowledge();

        $metric->refresh();
        $this->assertNull($metric->baseline_reset_suggested_at);
    }

    #[Test]
    public function acknowledging_one_trend_does_not_clear_flag_while_others_remain(): void
    {
        $metric = MetricStatistic::factory()->create([
            'baseline_reset_suggested_at' => now()->subDay(),
        ]);

        $monthlyTrend = MetricTrend::factory()->create([
            'metric_statistic_id' => $metric->id,
            'type' => 'trend_up_monthly',
            'acknowledged_at' => null,
        ]);

        MetricTrend::factory()->create([
            'metric_statistic_id' => $metric->id,
            'type' => 'trend_up_quarterly',
            'acknowledged_at' => null,
        ]);

        $monthlyTrend->acknowledge();

        $metric->refresh();
        $this->assertNotNull($metric->baseline_reset_suggested_at);
    }

    #[Test]
    public function get_baselines_tool_includes_baseline_review_fields(): void
    {
        $metric = MetricStatistic::factory()->create([
            'baseline_reset_suggested_at' => now()->subDays(3),
        ]);

        $tool = new class extends GetBaselinesTool
        {
            public function publicFormatBaseline(MetricStatistic $statistic): array
            {
                return $this->formatBaseline($statistic);
            }
        };

        // Flag is set
        $result = $tool->publicFormatBaseline($metric);
        $this->assertTrue($result['baseline_review_suggested']);
        $this->assertEquals(now()->subDays(3)->toDateString(), $result['baseline_review_suggested_since']);

        // Flag is cleared
        $metric->update(['baseline_reset_suggested_at' => null]);
        $result = $tool->publicFormatBaseline($metric->fresh());
        $this->assertFalse($result['baseline_review_suggested']);
        $this->assertNull($result['baseline_review_suggested_since']);
    }

    // -------------------------------------------------------------------------
    // Significance scoring (CRX-654)
    // -------------------------------------------------------------------------

    #[Test]
    public function significance_score_increases_monotonically_with_z_score_distance(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        // Baseline: mean=100, stddev=10. Bounds are set tighter than 2σ so that
        // test events at exactly 2σ, 3σ, 5σ above the mean are all flagged.
        $metric = MetricStatistic::factory()->create([
            'user_id' => $user->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
            'value_unit' => 'percent',
            'mean_value' => 100,
            'stddev_value' => 10,
            'normal_lower_bound' => 80,
            'normal_upper_bound' => 119,
            'event_count' => 50,
        ]);

        // Events at 2σ, 3σ, and 5σ above the mean. With upper_bound=119, a value
        // of exactly 120 (2σ) is strictly anomalous (120 > 119).
        $sigmas = [2, 3, 5];
        $scores = [];

        foreach ($sigmas as $sigma) {
            $value = 100 + ($sigma * 10); // mean + n * stddev

            $event = Event::create([
                'source_id' => "sig-{$sigma}",
                'time' => now(),
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'target_id' => $target->id,
                'service' => 'oura',
                'domain' => 'health',
                'action' => 'had_readiness_score',
                'value' => $value,
                'value_multiplier' => 1,
                'value_unit' => 'percent',
            ]);

            $job = new DetectMetricAnomaliesJob($event);
            $job->handle();

            $trend = MetricTrend::where('metric_statistic_id', $metric->id)
                ->whereJsonContains('metadata->event_id', $event->id)
                ->first();

            $this->assertNotNull($trend, "Expected anomaly at {$sigma}σ");
            $scores[$sigma] = (float) $trend->significance_score;
        }

        // Scores must strictly increase with z-score distance.
        $this->assertGreaterThan($scores[2], $scores[3], '3σ must score higher than 2σ');
        $this->assertGreaterThan($scores[3], $scores[5], '5σ must score higher than 3σ');

        // A 2σ and a 5σ outlier must be meaningfully different (not both capped at 1.0).
        $this->assertGreaterThan(0.05, $scores[5] - $scores[2], '2σ and 5σ scores must differ by more than 0.05');

        // All scores must be in (0, 1) — the asymptotic tanh formula never reaches 1.0.
        foreach ($scores as $sigma => $score) {
            $this->assertGreaterThan(0, $score, "{$sigma}σ score must be > 0");
            $this->assertLessThan(1, $score, "{$sigma}σ score must be < 1 (tanh never reaches 1.0)");
        }
    }

    #[Test]
    public function active_high_suppression_prevents_anomaly_high_creation(): void
    {
        [
            'user' => $user,
            'metric' => $metric,
            'integration' => $integration,
            'actor' => $actor,
            'target' => $target,
        ] = $this->makeSuppressionFixtures();

        $metric->update(['anomaly_high_suppressed_until' => now()->addDays(7)]);

        $event = Event::create([
            'source_id' => 'supp-high-1',
            'time' => now(),
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_readiness_score',
            'value' => 95, // above upper bound of 85
            'value_multiplier' => 1,
            'value_unit' => 'percent',
            'target_id' => $target->id,
        ]);

        (new DetectMetricAnomaliesJob($event))->handle();

        $this->assertEquals(0, MetricTrend::where('metric_statistic_id', $metric->id)->count());
    }

    #[Test]
    public function active_low_suppression_prevents_anomaly_low_creation(): void
    {
        [
            'user' => $user,
            'metric' => $metric,
            'integration' => $integration,
            'actor' => $actor,
            'target' => $target,
        ] = $this->makeSuppressionFixtures();

        $metric->update(['anomaly_low_suppressed_until' => now()->addDays(7)]);

        $event = Event::create([
            'source_id' => 'supp-low-1',
            'time' => now(),
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_readiness_score',
            'value' => 50, // below lower bound of 65
            'value_multiplier' => 1,
            'value_unit' => 'percent',
            'target_id' => $target->id,
        ]);

        (new DetectMetricAnomaliesJob($event))->handle();

        $this->assertEquals(0, MetricTrend::where('metric_statistic_id', $metric->id)->count());
    }

    #[Test]
    public function high_suppression_does_not_prevent_anomaly_low_creation(): void
    {
        [
            'user' => $user,
            'metric' => $metric,
            'integration' => $integration,
            'actor' => $actor,
            'target' => $target,
        ] = $this->makeSuppressionFixtures();

        // Only suppress highs — lows should still fire.
        $metric->update(['anomaly_high_suppressed_until' => now()->addDays(7)]);

        $event = Event::create([
            'source_id' => 'supp-direc-1',
            'time' => now(),
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_readiness_score',
            'value' => 50, // below lower bound → anomaly_low
            'value_multiplier' => 1,
            'value_unit' => 'percent',
            'target_id' => $target->id,
        ]);

        (new DetectMetricAnomaliesJob($event))->handle();

        $this->assertDatabaseHas('metric_trends', [
            'metric_statistic_id' => $metric->id,
            'type' => 'anomaly_low',
        ]);
    }

    #[Test]
    public function low_suppression_does_not_prevent_anomaly_high_creation(): void
    {
        [
            'user' => $user,
            'metric' => $metric,
            'integration' => $integration,
            'actor' => $actor,
            'target' => $target,
        ] = $this->makeSuppressionFixtures();

        // Only suppress lows — highs should still fire.
        $metric->update(['anomaly_low_suppressed_until' => now()->addDays(7)]);

        $event = Event::create([
            'source_id' => 'supp-direc-2',
            'time' => now(),
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_readiness_score',
            'value' => 95, // above upper bound → anomaly_high
            'value_multiplier' => 1,
            'value_unit' => 'percent',
            'target_id' => $target->id,
        ]);

        (new DetectMetricAnomaliesJob($event))->handle();

        $this->assertDatabaseHas('metric_trends', [
            'metric_statistic_id' => $metric->id,
            'type' => 'anomaly_high',
        ]);
    }

    #[Test]
    public function expired_suppression_allows_anomaly_creation(): void
    {
        [
            'user' => $user,
            'metric' => $metric,
            'integration' => $integration,
            'actor' => $actor,
            'target' => $target,
        ] = $this->makeSuppressionFixtures();

        $metric->update(['anomaly_high_suppressed_until' => now()->subDay()]);

        $event = Event::create([
            'source_id' => 'supp-expired-1',
            'time' => now(),
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_readiness_score',
            'value' => 95,
            'value_multiplier' => 1,
            'value_unit' => 'percent',
            'target_id' => $target->id,
        ]);

        (new DetectMetricAnomaliesJob($event))->handle();

        $this->assertDatabaseHas('metric_trends', [
            'metric_statistic_id' => $metric->id,
            'type' => 'anomaly_high',
        ]);
    }

    #[Test]
    public function acknowledging_anomaly_high_with_suppress_until_sets_directional_column(): void
    {
        $user = User::factory()->create();
        $metric = MetricStatistic::factory()->create([
            'user_id' => $user->id,
            'anomaly_high_suppressed_until' => null,
            'anomaly_low_suppressed_until' => null,
        ]);

        $anomaly = MetricTrend::factory()->create([
            'metric_statistic_id' => $metric->id,
            'type' => 'anomaly_high',
            'acknowledged_at' => null,
        ]);

        $service = app(AnomalyAcknowledgement::class);
        $service->acknowledge($user, (string) $anomaly->id, [
            'suppress_until' => now()->addDays(14)->toDateString(),
        ]);

        $metric->refresh();
        $this->assertNotNull($metric->anomaly_high_suppressed_until);
        $this->assertNull($metric->anomaly_low_suppressed_until);
        $this->assertTrue($metric->anomaly_high_suppressed_until->isFuture());
    }

    #[Test]
    public function acknowledging_anomaly_low_with_suppress_until_sets_directional_column(): void
    {
        $user = User::factory()->create();
        $metric = MetricStatistic::factory()->create([
            'user_id' => $user->id,
            'anomaly_high_suppressed_until' => null,
            'anomaly_low_suppressed_until' => null,
        ]);

        $anomaly = MetricTrend::factory()->create([
            'metric_statistic_id' => $metric->id,
            'type' => 'anomaly_low',
            'acknowledged_at' => null,
        ]);

        $service = app(AnomalyAcknowledgement::class);
        $service->acknowledge($user, (string) $anomaly->id, [
            'suppress_until' => now()->addDays(14)->toDateString(),
        ]);

        $metric->refresh();
        $this->assertNotNull($metric->anomaly_low_suppressed_until);
        $this->assertNull($metric->anomaly_high_suppressed_until);
        $this->assertTrue($metric->anomaly_low_suppressed_until->isFuture());
    }

    // -------------------------------------------------------------------------
    // Directional suppression (CRX-655)
    // -------------------------------------------------------------------------

    private function makeSuppressionFixtures(string $service = 'oura', string $action = 'had_readiness_score', string $unit = 'percent'): array
    {
        $user = User::factory()->create();
        $metric = MetricStatistic::factory()->create([
            'user_id' => $user->id,
            'service' => $service,
            'action' => $action,
            'value_unit' => $unit,
            'mean_value' => 75,
            'stddev_value' => 5,
            'normal_lower_bound' => 65,
            'normal_upper_bound' => 85,
            'event_count' => 50,
        ]);
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        return ['user' => $user, 'metric' => $metric, 'integration' => $integration, 'actor' => $actor, 'target' => $target];
    }
}
