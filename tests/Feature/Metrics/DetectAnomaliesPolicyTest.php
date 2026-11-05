<?php

namespace Tests\Feature\Metrics;

use App\Jobs\TaskPipeline\Tasks\DetectAnomaliesTask;
use App\Models\Event;
use App\Models\Integration;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use App\Services\TaskPipeline\TaskRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the anomaly task raises under the shared policy: listed metrics only,
 * adverse moves only, nothing without a spread, and retrospective
 * integrations are judged too.
 */
class DetectAnomaliesPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-06 12:00:00', 'UTC'));

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function integration(string $service, ?string $instanceType = null): Integration
    {
        return Integration::factory()->create(array_filter([
            'user_id' => $this->user->id,
            'service' => $service,
            'instance_type' => $instanceType,
        ]));
    }

    private function statistic(string $service, string $action, array $overrides = []): MetricStatistic
    {
        return MetricStatistic::factory()->create($overrides + [
            'user_id' => $this->user->id,
            'service' => $service,
            'action' => $action,
            'value_unit' => 'percent',
            'mean_value' => 80,
            'stddev_value' => 5,
            'normal_lower_bound' => 70,
            'normal_upper_bound' => 90,
            'event_count' => 60,
        ]);
    }

    private function event(Integration $integration, string $service, string $action, float $value): Event
    {
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => $service,
            'domain' => 'health',
            'action' => $action,
            'value' => $value,
            'value_multiplier' => 1,
            'value_unit' => 'percent',
            'time' => Carbon::parse('2026-11-04 00:00:00', 'UTC'),
            'event_metadata' => [],
        ]);

        // Settled: written two days ago.
        Event::whereKey($event->id)->update(['updated_at' => now()->subDays(2)]);

        return $event->fresh();
    }

    private function detect(Event $event): void
    {
        $task = new DetectAnomaliesTask($event, TaskRegistry::getTask('detect_anomalies'));

        (fn () => $this->execute())->call($task);
    }

    #[Test]
    public function a_low_readiness_is_raised(): void
    {
        $statistic = $this->statistic('oura', 'had_readiness_score');

        $this->detect($this->event($this->integration('oura'), 'oura', 'had_readiness_score', 55));

        $this->assertDatabaseHas('metric_trends', ['metric_statistic_id' => $statistic->id, 'type' => 'anomaly_low']);
    }

    #[Test]
    public function an_unusually_good_readiness_is_not_raised(): void
    {
        $this->statistic('oura', 'had_readiness_score');

        $this->detect($this->event($this->integration('oura'), 'oura', 'had_readiness_score', 99));

        $this->assertSame(0, MetricTrend::count());
    }

    #[Test]
    public function a_metric_that_is_not_listed_is_not_judged(): void
    {
        $this->statistic('untappd', 'drank');

        $this->detect($this->event($this->integration('untappd'), 'untappd', 'drank', 500));

        $this->assertSame(0, MetricTrend::count());
    }

    #[Test]
    public function a_metric_with_no_spread_is_skipped_without_error(): void
    {
        $this->statistic('apple_health', 'had_respiratory_rate', [
            'stddev_value' => 0,
            'mean_value' => 80,
            'normal_lower_bound' => 80,
            'normal_upper_bound' => 80,
        ]);

        $this->detect($this->event($this->integration('apple_health', 'metrics'), 'apple_health', 'had_respiratory_rate', 95));

        $this->assertSame(0, MetricTrend::count());
    }

    #[Test]
    public function a_value_still_being_rewritten_is_not_judged(): void
    {
        $this->statistic('oura', 'had_readiness_score');
        $event = $this->event($this->integration('oura'), 'oura', 'had_readiness_score', 55);
        Event::whereKey($event->id)->update(['updated_at' => now()->subHour()]);

        $this->detect($event->fresh());

        $this->assertSame(0, MetricTrend::count());
    }

    #[Test]
    public function an_anomaly_is_removed_when_the_metric_leaves_the_list(): void
    {
        $statistic = $this->statistic('untappd', 'drank');
        $event = $this->event($this->integration('untappd'), 'untappd', 'drank', 500);
        MetricTrend::factory()->create([
            'metric_statistic_id' => $statistic->id,
            'type' => 'anomaly_high',
            'metadata' => ['event_id' => $event->id],
        ]);

        $this->detect($event);

        $this->assertSame(0, MetricTrend::count());
    }

    #[Test]
    public function retrospective_integrations_are_judged(): void
    {
        $definition = TaskRegistry::getTask('detect_anomalies');
        $retrospective = $this->event($this->integration('apple_health', 'metrics'), 'apple_health', 'had_respiratory_rate', 80);

        $this->assertTrue($definition->isApplicableTo($retrospective));
    }
}
