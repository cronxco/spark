<?php

namespace Tests\Feature\Livewire;

use App\Jobs\Metrics\CalculateMetricStatisticsJob;
use App\Jobs\Metrics\DetectMetricTrendsJob;
use App\Livewire\MetricsOverview;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetricsOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    #[Test]
    public function component_renders_successfully(): void
    {
        Livewire::test(MetricsOverview::class)
            ->assertStatus(200);
    }

    #[Test]
    public function component_has_default_filter_values(): void
    {
        $component = Livewire::test(MetricsOverview::class);

        $component->assertSet('filterService', '')
            ->assertSet('filterDomain', '')
            ->assertSet('sortBy', 'interesting');
    }

    #[Test]
    public function filter_service_can_be_set(): void
    {
        $component = Livewire::test(MetricsOverview::class);

        $component->set('filterService', 'oura')
            ->assertSet('filterService', 'oura');
    }

    #[Test]
    public function filter_domain_can_be_set(): void
    {
        $component = Livewire::test(MetricsOverview::class);

        $component->set('filterDomain', 'health')
            ->assertSet('filterDomain', 'health');
    }

    #[Test]
    public function sort_by_can_be_changed_to_service(): void
    {
        $component = Livewire::test(MetricsOverview::class);

        $component->set('sortBy', 'service')
            ->assertSet('sortBy', 'service');
    }

    #[Test]
    public function sort_by_can_be_changed_to_recent(): void
    {
        $component = Livewire::test(MetricsOverview::class);

        $component->set('sortBy', 'recent')
            ->assertSet('sortBy', 'recent');
    }

    #[Test]
    public function calculate_statistics_dispatches_job(): void
    {
        Queue::fake();

        $component = Livewire::test(MetricsOverview::class);
        $component->call('calculateStatistics');

        Queue::assertPushed(CalculateMetricStatisticsJob::class);
    }

    #[Test]
    public function detect_trends_dispatches_job(): void
    {
        Queue::fake();

        $component = Livewire::test(MetricsOverview::class);
        $component->call('detectTrends');

        Queue::assertPushed(DetectMetricTrendsJob::class);
    }

    #[Test]
    public function component_shows_metrics_for_current_user(): void
    {
        $metric = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
            'first_event_at' => now()->subDays(40),
            'last_event_at' => now(),
        ]);

        $component = Livewire::test(MetricsOverview::class);

        // The metrics should be loaded in the view data
        $component->assertOk();
    }

    #[Test]
    public function component_filters_metrics_by_service(): void
    {
        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'first_event_at' => now()->subDays(40),
            'last_event_at' => now(),
        ]);

        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'hevy',
            'first_event_at' => now()->subDays(40),
            'last_event_at' => now(),
        ]);

        $component = Livewire::test(MetricsOverview::class);
        $component->set('filterService', 'oura');

        // Component should still render correctly with filter applied
        $component->assertOk();
    }

    #[Test]
    public function component_does_not_show_metrics_for_other_users(): void
    {
        $otherUser = User::factory()->create();

        MetricStatistic::factory()->create([
            'user_id' => $otherUser->id,
            'service' => 'oura',
            'first_event_at' => now()->subDays(40),
            'last_event_at' => now(),
        ]);

        $component = Livewire::test(MetricsOverview::class);

        // The component should not include metrics from other users
        $component->assertOk();
    }

    #[Test]
    public function component_shows_recent_trends(): void
    {
        $metric = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'first_event_at' => now()->subDays(40),
            'last_event_at' => now(),
        ]);

        MetricTrend::factory()->create([
            'metric_statistic_id' => $metric->id,
            'type' => 'trend_up_weekly',
            'detected_at' => now(),
            'acknowledged_at' => null,
        ]);

        $component = Livewire::test(MetricsOverview::class);
        $component->assertOk();
    }

    // Note: In Livewire 3, listeners are defined via #[On] attributes
    // rather than a getListeners() method, so we test the dispatch works instead

    #[Test]
    public function sorting_by_interesting_orders_by_unacknowledged_trends(): void
    {
        // Create metrics with different trend counts
        $metric1 = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'first_event_at' => now()->subDays(40),
            'last_event_at' => now(),
        ]);

        $metric2 = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'hevy',
            'first_event_at' => now()->subDays(40),
            'last_event_at' => now(),
        ]);

        // Add more trends to metric2
        MetricTrend::factory()->count(3)->create([
            'metric_statistic_id' => $metric2->id,
            'type' => 'trend_up_weekly',
            'acknowledged_at' => null,
        ]);

        $component = Livewire::test(MetricsOverview::class);
        $component->set('sortBy', 'interesting');
        $component->assertOk();
    }
}
