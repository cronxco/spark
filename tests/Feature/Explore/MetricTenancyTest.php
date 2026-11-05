<?php

namespace Tests\Feature\Explore;

use App\Jobs\Metrics\CalculateMetricStatisticsJob;
use App\Jobs\Metrics\DetectMetricTrendsJob;
use App\Livewire\MetricDetail;
use App\Livewire\MetricsOverview;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\MetricStatistic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EX-01 and the all-tenant metric jobs: a metric chart mixed in other users'
 * values, and the "recalculate" buttons ran the jobs across every tenant.
 */
class MetricTenancyTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.enable_task_pipeline' => false]);
        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();
    }

    #[Test]
    public function the_metric_chart_only_plots_the_owners_values(): void
    {
        $this->readings($this->alice, [42]);
        $this->readings($this->bob, [999999]);
        $metric = MetricStatistic::factory()->create([
            'user_id' => $this->alice->id, 'service' => 'oura', 'action' => 'had_readiness_score', 'value_unit' => 'percent',
        ]);

        $this->actingAs($this->alice);

        $chartData = Livewire::test(MetricDetail::class, ['metric' => $metric])->viewData('chartData');

        $this->assertContains(42, array_map('intval', $chartData));
        $this->assertNotContains(999999, array_map('intval', $chartData));
    }

    #[Test]
    public function recalculate_buttons_only_queue_work_for_the_signed_in_user(): void
    {
        Queue::fake();
        $metric = MetricStatistic::factory()->create(['user_id' => $this->alice->id]);
        $this->actingAs($this->alice);

        Livewire::test(MetricsOverview::class)->call('calculateStatistics')->call('detectTrends');
        Livewire::test(MetricDetail::class, ['metric' => $metric])->call('calculateMetricStatistics');

        Queue::assertPushed(CalculateMetricStatisticsJob::class, fn ($job) => $job->userId === $this->alice->id);
        Queue::assertPushed(DetectMetricTrendsJob::class, fn ($job) => $job->userId === $this->alice->id);
        Queue::assertNotPushed(CalculateMetricStatisticsJob::class, fn ($job) => $job->userId === null);
        Queue::assertNotPushed(DetectMetricTrendsJob::class, fn ($job) => $job->userId === null);
    }

    #[Test]
    public function a_user_scoped_statistics_run_leaves_other_users_alone(): void
    {
        $this->readings($this->alice, range(60, 72));
        $this->readings($this->bob, range(60, 72));

        (new CalculateMetricStatisticsJob($this->alice->id))->handle();

        $this->assertDatabaseHas('metric_statistics', ['user_id' => $this->alice->id, 'action' => 'had_readiness_score']);
        $this->assertDatabaseMissing('metric_statistics', ['user_id' => $this->bob->id]);

        (new CalculateMetricStatisticsJob)->handle();

        $this->assertDatabaseHas('metric_statistics', ['user_id' => $this->bob->id, 'action' => 'had_readiness_score']);
    }

    #[Test]
    public function a_user_scoped_trend_run_only_reads_that_users_metrics(): void
    {
        $alices = MetricStatistic::factory()->create(['user_id' => $this->alice->id]);
        MetricStatistic::factory()->create(['user_id' => $this->bob->id]);

        $job = new class($this->alice->id) extends DetectMetricTrendsJob
        {
            /** @var array<int, string> */
            public array $seen = [];

            protected function detectTrendsForMetric(MetricStatistic $metric): void
            {
                $this->seen[] = (string) $metric->id;
            }
        };

        $job->handle();

        $this->assertSame([(string) $alices->id], $job->seen);
    }

    #[Test]
    public function only_one_run_per_scope_can_be_pending(): void
    {
        $this->assertSame($this->alice->id, (new CalculateMetricStatisticsJob($this->alice->id))->uniqueId());
        $this->assertSame('all', (new DetectMetricTrendsJob)->uniqueId());
    }

    /**
     * Readings spread over 60 days so the statistics job has enough history.
     *
     * @param  array<int, int>  $values
     */
    private function readings(User $user, array $values): void
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'oura']);
        $integration = Integration::factory()->create(['user_id' => $user->id, 'integration_group_id' => $group->id, 'service' => 'oura']);

        foreach (array_values($values) as $index => $value) {
            Event::factory()->create([
                'integration_id' => $integration->id,
                'service' => 'oura',
                'domain' => 'health',
                'action' => 'had_readiness_score',
                'value' => $value,
                'value_multiplier' => 1,
                'value_unit' => 'percent',
                'time' => now()->subDays(count($values) > 1 ? (int) round(59 * $index / (count($values) - 1)) : 1),
            ]);
        }
    }
}
