<?php

namespace Tests\Feature;

use App\Jobs\Metrics\CalculateMetricStatisticsJob;
use App\Jobs\Metrics\DetectMetricAnomaliesJob;
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
    public function event_creation_dispatches_anomaly_detection_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);

        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        Event::create([
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

        Queue::assertPushed(DetectMetricAnomaliesJob::class);
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
        $this->assertNotNull($metric->mean_value);
        $this->assertNotNull($metric->stddev_value);
        $this->assertNotNull($metric->normal_lower_bound);
        $this->assertNotNull($metric->normal_upper_bound);
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
