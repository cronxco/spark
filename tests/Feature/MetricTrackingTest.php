<?php

namespace Tests\Feature;

use App\Jobs\Metrics\CalculateMetricStatisticsJob;
use App\Jobs\Metrics\DetectMetricAnomaliesJob;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Jobs\TaskPipeline\Tasks\DetectAnomaliesTask;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetricTrackingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
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

        // Process the pipeline manually to verify it dispatches the anomaly detection task
        $pipelineJob = new ProcessTaskPipelineJob($event, 'created');
        $pipelineJob->handle();

        // Verify DetectAnomaliesTask was dispatched by the pipeline
        Queue::assertPushed(DetectAnomaliesTask::class, function ($job) use ($event) {
            return $job->model->id === $event->id;
        });
    }

    /**
     * @test
     */
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

    /**
     * @test
     */
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

    /**
     * @test
     */
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

    /**
     * @test
     */
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

    /**
     * @test
     */
    public function user_can_disable_metric_tracking(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->isMetricTrackingDisabled('oura', 'had_readiness_score', 'percent'));

        $user->disableMetricTracking('oura', 'had_readiness_score', 'percent');

        $this->assertTrue($user->isMetricTrackingDisabled('oura', 'had_readiness_score', 'percent'));
    }

    /**
     * @test
     */
    public function user_can_enable_metric_tracking(): void
    {
        $user = User::factory()->create();
        $user->disableMetricTracking('oura', 'had_readiness_score', 'percent');

        $this->assertTrue($user->isMetricTrackingDisabled('oura', 'had_readiness_score', 'percent'));

        $user->enableMetricTracking('oura', 'had_readiness_score', 'percent');

        $this->assertFalse($user->isMetricTrackingDisabled('oura', 'had_readiness_score', 'percent'));
    }

    /**
     * @test
     */
    public function metrics_overview_page_is_accessible(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('metrics.index'));

        $response->assertStatus(200);
    }

    /**
     * @test
     */
    public function metric_detail_page_is_accessible(): void
    {
        $user = User::factory()->create();
        $metric = MetricStatistic::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('metrics.show', $metric));

        $response->assertStatus(200);
    }

    /**
     * @test
     */
    public function user_cannot_view_another_users_metric(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $metric = MetricStatistic::factory()->create(['user_id' => $user2->id]);

        $response = $this->actingAs($user1)->get(route('metrics.show', $metric));

        $response->assertStatus(403);
    }

    /**
     * @test
     */
    public function trend_can_be_acknowledged(): void
    {
        $trend = MetricTrend::factory()->create(['acknowledged_at' => null]);

        $this->assertNull($trend->acknowledged_at);

        $trend->acknowledge();

        $this->assertNotNull($trend->fresh()->acknowledged_at);
    }

    /**
     * @test
     */
    public function user_can_set_anomaly_detection_mode_override(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->getAnomalyDetectionModeOverride('oura', 'had_readiness_score', 'percent'));

        $user->setAnomalyDetectionMode('oura', 'had_readiness_score', 'percent', 'retrospective');

        $this->assertEquals('retrospective', $user->getAnomalyDetectionModeOverride('oura', 'had_readiness_score', 'percent'));
    }

    /**
     * @test
     */
    public function user_can_clear_anomaly_detection_mode_override(): void
    {
        $user = User::factory()->create();
        $user->setAnomalyDetectionMode('oura', 'had_readiness_score', 'percent', 'disabled');

        $this->assertEquals('disabled', $user->getAnomalyDetectionModeOverride('oura', 'had_readiness_score', 'percent'));

        $user->clearAnomalyDetectionModeOverride('oura', 'had_readiness_score', 'percent');

        $this->assertNull($user->getAnomalyDetectionModeOverride('oura', 'had_readiness_score', 'percent'));
    }

    /**
     * @test
     */
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

    /**
     * @test
     */
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
}
