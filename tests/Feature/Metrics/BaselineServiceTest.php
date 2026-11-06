<?php

namespace Tests\Feature\Metrics;

use App\Models\Event;
use App\Models\Integration;
use App\Models\MetricBaseline;
use App\Models\MetricStatistic;
use App\Models\User;
use App\Services\Metrics\BaselineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BaselineServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-06 12:00:00', 'UTC'));

        $this->user = User::factory()->create();
        $this->integration = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'apple_health']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_reading_gets_a_mean_and_standard_deviation_baseline(): void
    {
        $this->days('oura', 'had_readiness_score', 'percent', 60, fn (int $back) => 70 + ($back % 20));

        $statistic = $this->statistic('oura', 'had_readiness_score', 'percent');
        $this->assertTrue(app(BaselineService::class)->recompute($statistic));

        $statistic = $statistic->fresh();
        $this->assertSame(60, $statistic->event_count);
        $this->assertEqualsWithDelta(79.5, (float) $statistic->mean_value, 0.1);
        $this->assertEqualsWithDelta((float) $statistic->mean_value - 2 * (float) $statistic->stddev_value, (float) $statistic->normal_lower_bound, 0.0001);

        $windows = MetricBaseline::where('metric_statistic_id', $statistic->id)->pluck('window_days')->unique()->sort()->values()->all();
        $this->assertSame([30, 90], $windows);
    }

    #[Test]
    public function readiness_without_a_weekday_effect_keeps_one_all_days_baseline(): void
    {
        $this->days('oura', 'had_readiness_score', 'percent', 70, fn (int $back) => 70 + (($back * 7) % 13));

        $statistic = $this->statistic('oura', 'had_readiness_score', 'percent');
        app(BaselineService::class)->recompute($statistic);

        $dayTypes = MetricBaseline::where('metric_statistic_id', $statistic->id)->pluck('day_type')->unique()->all();
        $this->assertSame(['all'], $dayTypes);
    }

    #[Test]
    public function steps_get_a_median_baseline_and_separate_weekday_and_weekend_baselines(): void
    {
        $this->days('apple_health', 'had_step_count', 'count', 70, function (int $back, Carbon $date) {
            $jitter = (($back * 37) % 11 - 5) * 80;

            return ($date->isWeekend() ? 5000 : 9000) + $jitter;
        });

        $statistic = $this->statistic('apple_health', 'had_step_count', 'count');
        app(BaselineService::class)->recompute($statistic);

        $statistic = $statistic->fresh();
        $weekday = MetricBaseline::where('metric_statistic_id', $statistic->id)->where('window_days', 90)->where('day_type', 'weekday')->first();
        $weekend = MetricBaseline::where('metric_statistic_id', $statistic->id)->where('window_days', 90)->where('day_type', 'weekend')->first();

        $this->assertNotNull($weekday);
        $this->assertNotNull($weekend);
        $this->assertGreaterThan(8000, $weekday->centre);
        $this->assertLessThan(6000, $weekend->centre);
        $this->assertLessThan(0.01, $weekday->effect_significance);
        $this->assertSame('median_mad', $weekday->method);
        // Most days are weekdays, so the whole-metric median sits near the weekday one.
        $this->assertEqualsWithDelta((float) $weekday->centre, (float) $statistic->mean_value, 600);
    }

    #[Test]
    public function the_lower_bound_never_goes_below_zero_for_a_quantity_that_was_never_negative(): void
    {
        $this->days('apple_health', 'had_step_count', 'count', 60, fn (int $back) => $back % 2 === 0 ? 100 : 12000);

        $statistic = $this->statistic('apple_health', 'had_step_count', 'count');
        app(BaselineService::class)->recompute($statistic);

        $this->assertGreaterThanOrEqual(0.0, (float) $statistic->fresh()->normal_lower_bound);
    }

    #[Test]
    public function too_little_history_leaves_no_baseline(): void
    {
        $this->days('oura', 'had_readiness_score', 'percent', 8, fn (int $back) => 70 + $back);

        $statistic = $this->statistic('oura', 'had_readiness_score', 'percent');

        $this->assertFalse(app(BaselineService::class)->recompute($statistic));
        $this->assertFalse($statistic->exists);
        $this->assertSame(0, MetricBaseline::count());
    }

    #[Test]
    public function a_day_still_filling_in_is_not_part_of_the_baseline(): void
    {
        $this->days('apple_health', 'had_step_count', 'count', 60, fn (int $back) => 9000 + ($back % 5) * 100);

        // Today's running total, written this morning.
        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'apple_health',
            'domain' => 'health',
            'action' => 'had_step_count',
            'value' => 300,
            'value_multiplier' => 1,
            'value_unit' => 'count',
            'time' => now()->utc()->startOfDay(),
            'event_metadata' => [],
        ]);

        $statistic = $this->statistic('apple_health', 'had_step_count', 'count');
        app(BaselineService::class)->recompute($statistic);

        $this->assertSame(60, $statistic->fresh()->event_count);
        $this->assertGreaterThan(8000, (float) $statistic->fresh()->min_value);
    }

    #[Test]
    public function zero_readings_are_left_out_of_a_reading_but_kept_for_spend(): void
    {
        $this->days('oura', 'had_readiness_score', 'percent', 60, fn (int $back) => $back % 3 === 0 ? 0 : 80 + ($back % 7));

        $statistic = $this->statistic('oura', 'had_readiness_score', 'percent');
        app(BaselineService::class)->recompute($statistic);

        $this->assertSame(40, $statistic->fresh()->event_count);
        $this->assertGreaterThan(79, (float) $statistic->fresh()->min_value);
    }

    #[Test]
    public function a_metric_with_no_variation_has_zero_spread_without_error(): void
    {
        $this->days('apple_health', 'had_step_count', 'count', 60, fn () => 8000);

        $statistic = $this->statistic('apple_health', 'had_step_count', 'count');
        $this->assertTrue(app(BaselineService::class)->recompute($statistic));

        $this->assertEqualsWithDelta(0.0, (float) $statistic->fresh()->stddev_value, 0.0001);
    }

    #[Test]
    public function describe_gives_the_display_band_one_spread_either_side(): void
    {
        $this->days('oura', 'had_readiness_score', 'percent', 60, fn (int $back) => 70 + ($back % 20));

        $statistic = $this->statistic('oura', 'had_readiness_score', 'percent');
        $service = app(BaselineService::class);
        $service->recompute($statistic);

        $described = $service->describe($statistic->fresh()->load('baselines'));

        $this->assertSame(30, $described['display']['window_days']);
        $this->assertEqualsWithDelta(
            $described['display']['upper'] - $described['display']['centre'],
            $described['display']['centre'] - $described['display']['lower'],
            0.0001,
        );
        $this->assertSame('mean_sd', $described['method']);
    }

    #[Test]
    public function excluding_a_day_removes_it_from_the_basis_without_saving(): void
    {
        $this->days('oura', 'had_readiness_score', 'percent', 60, fn (int $back) => $back === 1 ? 20 : 80 + ($back % 5));

        $statistic = $this->statistic('oura', 'had_readiness_score', 'percent');
        $service = app(BaselineService::class);
        $service->recompute($statistic);
        $statistic = $statistic->fresh();

        $basis = $service->excluding($statistic, now()->utc()->subDay()->startOfDay(), 'UTC');

        $this->assertSame(59, $basis->event_count);
        $this->assertGreaterThan((float) $statistic->mean_value, (float) $basis->mean_value);
        $this->assertLessThan((float) $statistic->stddev_value, (float) $basis->stddev_value);
        $this->assertSame(60, $statistic->fresh()->event_count);
    }

    /**
     * One event per day, ending yesterday.
     *
     * @param  callable(int, Carbon): float  $value  given days back and the date
     */
    private function days(string $service, string $action, string $unit, int $count, callable $value): void
    {
        for ($back = 1; $back <= $count; $back++) {
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
    }

    private function statistic(string $service, string $action, string $unit): MetricStatistic
    {
        return MetricStatistic::firstOrNew([
            'user_id' => $this->user->id,
            'service' => $service,
            'action' => $action,
            'value_unit' => $unit,
        ]);
    }
}
