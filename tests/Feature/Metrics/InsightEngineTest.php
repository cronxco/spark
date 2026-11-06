<?php

namespace Tests\Feature\Metrics;

use App\Models\Event;
use App\Models\Integration;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use App\Services\Metrics\BaselineService;
use App\Services\Metrics\InsightEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InsightEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        // A Friday, afternoon in London.
        Carbon::setTestNow(Carbon::parse('2026-11-06 15:00:00', 'UTC'));

        $this->user = User::factory()->create();
        $this->integration = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'apple_health']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_week_well_above_the_four_week_trend_is_an_insight(): void
    {
        // Steady 8,000 a day with a little noise, then a busy last week of 11,000.
        $this->seedMetric('apple_health', 'had_step_count', 'count', fn (int $back) => ($back <= 7 ? 11000 : 8000) + (($back * 37) % 11 - 5) * 60);

        app(InsightEngine::class)->generateForUser($this->user);

        $insight = MetricTrend::insights()->where('type', 'insight_period_up')->first();

        $this->assertNotNull($insight);
        $this->assertSame('period', str_replace('insight_', '', $insight->metadata['kind']));
        $this->assertSame('4w trend', $insight->metadata['comparison']);
        $this->assertEqualsWithDelta(11000, (float) $insight->current_value, 150);
        $this->assertEqualsWithDelta(8000, (float) $insight->baseline_value, 150);
        $this->assertSame('good', $insight->metadata['valence']);
    }

    #[Test]
    public function an_ordinary_week_is_not_an_insight(): void
    {
        $this->seedMetric('apple_health', 'had_step_count', 'count', fn (int $back) => 8000 + (($back * 37) % 11 - 5) * 60);

        app(InsightEngine::class)->generateForUser($this->user);

        $this->assertSame(0, MetricTrend::insights()->count());
    }

    #[Test]
    public function a_low_day_is_worded_for_the_weekday_only_when_days_really_differ(): void
    {
        // Weekdays ~9,000, weekends ~5,000, and yesterday (Thursday) only 7,300.
        $this->seedMetric('apple_health', 'had_step_count', 'count', function (int $back, Carbon $date) {
            if ($back === 1) {
                return 7300;
            }

            return ($date->isWeekend() ? 5000 : 9000) + (($back * 37) % 11 - 5) * 80;
        });

        app(InsightEngine::class)->generateForUser($this->user);

        $insight = MetricTrend::insights()->where('type', 'insight_typical_down')->first();

        $this->assertNotNull($insight);
        $this->assertContains($insight->metadata['day_type'], ['weekday', 'dow_4']);
        $this->assertNotNull($insight->metadata['day_label']);
        $this->assertLessThan(0.01, $insight->metadata['day_type_p']);
    }

    #[Test]
    public function a_low_day_has_no_day_wording_when_days_do_not_differ(): void
    {
        $this->seedMetric('oura', 'had_readiness_score', 'percent', function (int $back) {
            // Yesterday is clearly lower than usual, but inside the anomaly range.
            return $back === 1 ? 73 : 80 + (($back * 7) % 13 - 6);
        });

        app(InsightEngine::class)->generateForUser($this->user);

        $insight = MetricTrend::insights()->where('type', 'insight_typical_down')->first();

        $this->assertNotNull($insight);
        $this->assertSame('all', $insight->metadata['day_type']);
        $this->assertNull($insight->metadata['day_label']);
    }

    #[Test]
    public function a_move_big_enough_to_be_an_anomaly_is_not_also_an_insight(): void
    {
        $this->seedMetric('oura', 'had_readiness_score', 'percent', fn (int $back) => $back === 1 ? 20 : 80 + (($back * 7) % 13 - 6));

        app(InsightEngine::class)->generateForUser($this->user);

        $this->assertNull(MetricTrend::insights()->where('type', 'insight_typical_down')->first());
    }

    #[Test]
    public function generating_again_changes_nothing(): void
    {
        $this->seedMetric('apple_health', 'had_step_count', 'count', fn (int $back) => ($back <= 7 ? 11000 : 8000) + (($back * 37) % 11 - 5) * 60);

        app(InsightEngine::class)->generateForUser($this->user);
        $first = MetricTrend::insights()->pluck('id')->sort()->values()->all();

        app(InsightEngine::class)->generateForUser($this->user);

        $this->assertSame($first, MetricTrend::insights()->pluck('id')->sort()->values()->all());
    }

    #[Test]
    public function no_more_than_the_daily_limit_are_kept(): void
    {
        config(['metrics.max_insights_per_day' => 1]);

        $this->seedMetric('apple_health', 'had_step_count', 'count', fn (int $back) => ($back <= 7 ? 11000 : 8000) + (($back * 37) % 11 - 5) * 60);
        $this->seedMetric('apple_health', 'had_apple_exercise_time', 'min', fn (int $back) => ($back <= 7 ? 60 : 30) + (($back * 37) % 11 - 5));

        $stored = app(InsightEngine::class)->generateForUser($this->user);

        $this->assertSame(1, $stored);
        $this->assertSame(1, MetricTrend::insights()->count());
    }

    #[Test]
    public function a_metric_that_is_not_listed_for_insights_produces_none(): void
    {
        $this->seedMetric('apple_health', 'had_basal_energy_burned', 'kcal', fn (int $back) => ($back <= 7 ? 3000 : 1600) + (($back * 37) % 11 - 5) * 10);

        app(InsightEngine::class)->generateForUser($this->user);

        $this->assertSame(0, MetricTrend::insights()->count());
    }

    #[Test]
    public function insights_are_not_broadcast_as_anomalies(): void
    {
        $this->seedMetric('apple_health', 'had_step_count', 'count', fn (int $back) => ($back <= 7 ? 11000 : 8000) + (($back * 37) % 11 - 5) * 60);

        app(InsightEngine::class)->generateForUser($this->user);

        $this->assertSame(0, MetricTrend::anomalies()->count());
        $this->assertSame(0, MetricTrend::trends()->count());
    }

    /**
     * One event per day for the 90 days up to and including yesterday.
     *
     * @param  callable(int, Carbon): float  $value  given days back and the date
     */
    private function seedMetric(string $service, string $action, string $unit, callable $value, int $days = 90): MetricStatistic
    {
        for ($back = 1; $back <= $days; $back++) {
            $date = now()->utc()->subDays($back)->startOfDay();

            Event::factory()->create([
                'integration_id' => $this->integration->id,
                'service' => $service,
                'domain' => 'health',
                'action' => $action,
                'value' => $value($back, $date),
                'value_multiplier' => 1,
                'value_unit' => $unit,
                'time' => $date,
                'event_metadata' => [],
            ]);
        }

        $statistic = MetricStatistic::firstOrNew([
            'user_id' => $this->user->id,
            'service' => $service,
            'action' => $action,
            'value_unit' => $unit,
        ]);
        app(BaselineService::class)->recompute($statistic);

        return $statistic->fresh();
    }
}
