<?php

namespace Tests\Feature;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Jobs\TaskPipeline\DispatchRetrospectiveAnomalyTasksJob;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Jobs\TaskPipeline\Tasks\DetectAnomaliesTask;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use App\Services\TaskPipeline\TaskExecutionStore;
use App\Services\TaskPipeline\TaskRegistry;
use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Oura writes the day's heart-rate average at about 03:00 from a few overnight
 * readings, then rewrites it through the day. Judging the 03:00 value raised a
 * "below its usual range" anomaly every morning.
 */
class DetectAnomaliesTaskTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    private MetricStatistic $heartRate;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-05 07:30:00', 'Europe/London'));

        $this->user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        $group = IntegrationGroup::factory()->create(['user_id' => $this->user->id]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
        ]);

        $this->heartRate = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_heart_rate',
            'value_unit' => 'bpm',
            'mean_value' => 98.4,
            'stddev_value' => 9,
            'normal_lower_bound' => 80.4,
            'normal_upper_bound' => 116.4,
            'event_count' => 90,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function todays_heart_rate_average_is_not_judged_while_the_day_is_still_filling(): void
    {
        $event = $this->heartRateEvent('2026-11-05 00:00:00', 76);

        $this->detect($event);

        $this->assertSame(0, MetricTrend::where('metric_statistic_id', $this->heartRate->id)->count());
    }

    #[Test]
    public function a_finished_day_is_judged(): void
    {
        $event = $this->heartRateEvent('2026-11-04 00:00:00', 76);

        $this->detect($event);

        $this->assertDatabaseHas('metric_trends', [
            'metric_statistic_id' => $this->heartRate->id,
            'type' => 'anomaly_low',
        ]);
    }

    #[Test]
    public function an_anomaly_is_removed_when_the_events_value_comes_back_into_range(): void
    {
        $event = $this->heartRateEvent('2026-11-04 00:00:00', 76);
        $this->detect($event);

        $event->update(['value' => 101]);
        $this->detect($event->fresh());

        $this->assertSame(0, MetricTrend::where('metric_statistic_id', $this->heartRate->id)->count());
    }

    #[Test]
    public function an_acknowledged_anomaly_is_kept_when_the_value_comes_back_into_range(): void
    {
        $event = $this->heartRateEvent('2026-11-04 00:00:00', 76);
        $this->detect($event);
        MetricTrend::where('metric_statistic_id', $this->heartRate->id)->update(['acknowledged_at' => now()]);

        $event->update(['value' => 101]);
        $this->detect($event->fresh());

        $this->assertSame(1, MetricTrend::where('metric_statistic_id', $this->heartRate->id)->count());
    }

    #[Test]
    public function a_recorded_anomaly_follows_the_events_latest_value(): void
    {
        $event = $this->heartRateEvent('2026-11-04 00:00:00', 76);
        $this->detect($event);

        $event->update(['value' => 70]);
        $this->detect($event->fresh());

        $trends = MetricTrend::where('metric_statistic_id', $this->heartRate->id)->get();
        $this->assertCount(1, $trends);
        $this->assertEqualsWithDelta(70, (float) $trends->first()->current_value, 0.01);
    }

    #[Test]
    public function west_of_utc_the_day_is_still_filling_until_it_ends_locally(): void
    {
        $this->user->update(['settings' => ['timezone' => 'America/Los_Angeles']]);
        Carbon::setTestNow(Carbon::parse('2026-11-05 20:00:00', 'America/Los_Angeles'));
        $event = $this->heartRateEvent('2026-11-05 00:00:00', 76, 'UTC');

        $this->detect($event->fresh());

        $this->assertSame(0, MetricTrend::where('metric_statistic_id', $this->heartRate->id)->count());
    }

    #[Test]
    public function a_day_still_filling_is_recorded_as_deferred(): void
    {
        $event = $this->heartRateEvent('2026-11-05 00:00:00', 76);

        $task = $this->detect($event);

        $outcome = Closure::bind(fn () => $this->outcome, $task, BaseTaskJob::class)();
        $this->assertSame(DetectAnomaliesTask::OUTCOME_DAY_IN_PROGRESS, $outcome);
    }

    #[Test]
    public function the_daily_sweep_runs_a_deferred_day_again_once_it_has_ended(): void
    {
        $deferred = $this->heartRateEvent('2026-11-04 00:00:00', 76);
        $judged = $this->heartRateEvent('2026-11-03 00:00:00', 99);
        $store = app(TaskExecutionStore::class);
        $definition = TaskRegistry::getTask('detect_anomalies');
        $store->recordStatus($deferred, $definition, 'success', ['outcome' => DetectAnomaliesTask::OUTCOME_DAY_IN_PROGRESS]);
        $store->recordStatus($judged, $definition, 'success');

        (new DispatchRetrospectiveAnomalyTasksJob)->handle();

        Queue::assertPushed(ProcessTaskPipelineJob::class, fn ($job) => $job->model->is($deferred) && $job->force);
        Queue::assertNotPushed(ProcessTaskPipelineJob::class, fn ($job) => $job->model->is($judged));
    }

    #[Test]
    public function a_zero_reading_clears_an_earlier_anomaly(): void
    {
        $event = $this->heartRateEvent('2026-11-04 00:00:00', 76);
        $this->detect($event);

        $event->update(['value' => 0]);
        $this->detect($event->fresh());

        $this->assertSame(0, MetricTrend::where('metric_statistic_id', $this->heartRate->id)->count());
    }

    #[Test]
    public function a_zero_valued_update_still_runs_the_task(): void
    {
        $event = $this->heartRateEvent('2026-11-04 00:00:00', 0);

        $this->assertTrue(TaskRegistry::getTask('detect_anomalies')->isApplicableTo($event));
    }

    #[Test]
    public function the_task_runs_again_when_an_event_is_updated(): void
    {
        $this->assertTrue(TaskRegistry::getTask('detect_anomalies')->runOnUpdate);
    }

    private function heartRateEvent(string $day, float $value, string $timezone = 'Europe/London'): Event
    {
        return Event::create([
            'source_id' => 'oura-hr-' . $day,
            'time' => Carbon::parse($day, $timezone),
            'integration_id' => $this->integration->id,
            'actor_id' => EventObject::factory()->create(['user_id' => $this->user->id])->id,
            'target_id' => EventObject::factory()->create(['user_id' => $this->user->id])->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_heart_rate',
            'value' => $value,
            'value_multiplier' => 1,
            'value_unit' => 'bpm',
        ]);
    }

    /**
     * Runs the task body directly: `handle()` also waits on the stats task,
     * which these tests set up by hand.
     */
    private function detect(Event $event): DetectAnomaliesTask
    {
        $task = new DetectAnomaliesTask($event, TaskRegistry::getTask('detect_anomalies'));

        (fn () => $this->execute())->call($task);

        return $task;
    }
}
