<?php

namespace App\Services\Metrics;

use App\Models\MetricBaseline;
use App\Models\MetricStatistic;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Learns what is usual for a metric and stores it twice: on the
 * MetricStatistic row that older readers use (the 90-day, all-days baseline;
 * `mean_value` and `stddev_value` hold the centre and spread of whichever
 * method the metric uses), and on metric_baselines for the windows and day
 * types the insights compare against.
 */
class BaselineService
{
    public function __construct(
        private readonly MetricCatalogue $catalogue,
        private readonly MetricSeries $series,
    ) {}

    /**
     * Windows kept per metric: the one anomalies are detected on, and the one
     * shown to the person.
     *
     * @return array<int, int>
     */
    public static function windows(): array
    {
        return array_values(array_unique([
            (int) config('metrics.detection_window_days', 90),
            (int) config('metrics.display_window_days', 30),
        ]));
    }

    /**
     * Recompute and store the baselines for one metric. Returns false, and
     * clears the stored values, when there is not enough data to say what is
     * usual.
     */
    public function recompute(MetricStatistic $statistic, ?CarbonInterface $now = null): bool
    {
        $now = $now ? Carbon::instance($now) : now();
        $user = User::find($statistic->user_id);
        $timezone = $user?->getTimezone() ?? 'UTC';
        $detectionDays = (int) config('metrics.detection_window_days', 90);

        $series = $this->series->daily($statistic, $timezone, $detectionDays, $now);

        $dates = array_keys($series);
        $span = $dates === [] ? 0 : Carbon::parse($dates[0])->diffInDays(Carbon::parse(end($dates)));

        if (count($series) < 10 || $span < 30) {
            $this->clear($statistic, $now);

            return false;
        }

        $method = $this->catalogue->method($statistic->service, $statistic->action);
        $values = array_values($series);
        $summary = $this->summarise($values, $method);

        $statistic->forceFill([
            'event_count' => count($values),
            'first_event_at' => Carbon::parse($dates[0], 'UTC'),
            'last_event_at' => Carbon::parse(end($dates), 'UTC'),
            'min_value' => min($values),
            'max_value' => max($values),
            'mean_value' => $summary['centre'],
            'stddev_value' => $summary['spread'],
            'normal_lower_bound' => $summary['lower'],
            'normal_upper_bound' => $summary['upper'],
            'baseline_window_days' => $detectionDays,
            'last_calculated_at' => $now,
        ])->save();

        $this->storeBaselines($statistic, $series, $method, $now);

        return true;
    }

    /**
     * The statistic as it stands without one day, so a value is judged
     * against the history before it and does not pull its own baseline
     * towards itself. Returns the statistic unchanged when too little
     * history remains. The result is not saved.
     */
    public function excluding(MetricStatistic $statistic, CarbonInterface $day, string $timezone): MetricStatistic
    {
        $series = $this->series->daily($statistic, $timezone, (int) config('metrics.detection_window_days', 90));
        unset($series[Carbon::instance($day)->utc()->toDateString()]);

        if (count($series) < 10) {
            return $statistic;
        }

        $summary = $this->summarise(array_values($series), $this->catalogue->method($statistic->service, $statistic->action));
        $basis = $statistic->replicate();
        $basis->forceFill([
            'event_count' => count($series),
            'mean_value' => $summary['centre'],
            'stddev_value' => $summary['spread'],
            'normal_lower_bound' => $summary['lower'],
            'normal_upper_bound' => $summary['upper'],
        ]);

        return $basis;
    }

    /**
     * The baselines of one metric as clients read them: the detection
     * baseline, the 30-day display band (one spread either side of the
     * centre) and any day-type baselines that differ for real.
     *
     * @return array<string, mixed>
     */
    public function describe(MetricStatistic $statistic): array
    {
        $baselines = $statistic->relationLoaded('baselines') ? $statistic->baselines : $statistic->baselines()->get();
        $whole = $baselines->whereNull('subject_id');
        $detection = (int) config('metrics.detection_window_days', 90);
        $display = (int) config('metrics.display_window_days', 30);

        $displayRow = $whole->first(fn (MetricBaseline $b) => $b->window_days === $display && $b->day_type === MetricBaseline::DAY_TYPE_ALL);

        return [
            'method' => $this->catalogue->method($statistic->service, $statistic->action),
            'centre' => $statistic->mean_value !== null ? (float) $statistic->mean_value : null,
            'spread' => $statistic->stddev_value !== null ? (float) $statistic->stddev_value : null,
            'lower' => $statistic->normal_lower_bound !== null ? (float) $statistic->normal_lower_bound : null,
            'upper' => $statistic->normal_upper_bound !== null ? (float) $statistic->normal_upper_bound : null,
            'window_days' => $statistic->baseline_window_days ?? $detection,
            'display' => $displayRow ? [
                'window_days' => $display,
                'centre' => $displayRow->centre,
                'lower' => $displayRow->centre - $displayRow->spread,
                'upper' => $displayRow->centre + $displayRow->spread,
            ] : null,
            'day_types' => $whole
                ->filter(fn (MetricBaseline $b) => $b->window_days === $detection && $b->day_type !== MetricBaseline::DAY_TYPE_ALL)
                ->mapWithKeys(fn (MetricBaseline $b) => [$b->day_type => [
                    'centre' => $b->centre,
                    'lower' => $b->lower,
                    'upper' => $b->upper,
                    'samples' => $b->sample_count,
                    'p' => $b->effect_significance,
                ]])
                ->all(),
        ];
    }

    /**
     * Centre, spread and the usual range for a set of values.
     *
     * @param  array<int, float>  $values
     * @return array{centre: float, spread: float, lower: float, upper: float}
     */
    public function summarise(array $values, string $method): array
    {
        if ($method === MetricCatalogue::METHOD_MEDIAN_MAD) {
            $centre = Stats::median($values);
            $spread = Stats::scaledMad($values);

            // Over half the values equal the median: the deviation is
            // measuring nothing, so fall back to the ordinary one.
            if ($spread <= 0.0) {
                $spread = Stats::stddev($values);
            }
        } else {
            $centre = Stats::mean($values);
            $spread = Stats::stddev($values);
        }

        $lower = $centre - 2 * $spread;

        // A quantity that has never been negative cannot be usually negative.
        if (min($values) >= 0 && $lower < 0) {
            $lower = 0.0;
        }

        return [
            'centre' => $centre,
            'spread' => $spread,
            'lower' => $lower,
            'upper' => $centre + 2 * $spread,
        ];
    }

    /**
     * The day type each date falls in, tested for a real difference.
     *
     * Returns the day types worth keeping apart: weekday and weekend when
     * they differ (a two-sided Mann-Whitney test at the configured alpha),
     * and any single weekday that differs from the rest.
     *
     * @param  array<string, float>  $series
     * @return array<string, float> day type => p-value
     */
    public function significantDayTypes(array $series): array
    {
        $minWeeks = (int) config('metrics.day_type.min_weeks', 8);
        $minGroup = (int) config('metrics.day_type.min_group_samples', 5);
        $alpha = (float) config('metrics.day_type.alpha', 0.01);

        $dates = array_keys($series);

        if ($dates === [] || Carbon::parse($dates[0])->diffInWeeks(Carbon::parse(end($dates))) < $minWeeks) {
            return [];
        }

        $weekday = [];
        $weekend = [];
        $byDow = [];

        foreach ($series as $date => $value) {
            $iso = Carbon::parse($date)->dayOfWeekIso;
            $byDow[$iso][] = $value;
            if ($iso >= 6) {
                $weekend[] = $value;
            } else {
                $weekday[] = $value;
            }
        }

        $found = [];

        if (count($weekday) >= $minGroup && count($weekend) >= $minGroup) {
            $p = Stats::mannWhitneyP($weekday, $weekend);

            if ($p !== null && $p < $alpha) {
                $found[MetricBaseline::DAY_TYPE_WEEKDAY] = $p;
                $found[MetricBaseline::DAY_TYPE_WEEKEND] = $p;
            }
        }

        // Seven comparisons, so the bar is the alpha shared between them.
        foreach ($byDow as $iso => $group) {
            $rest = [];
            foreach ($byDow as $other => $otherValues) {
                if ($other !== $iso) {
                    $rest = array_merge($rest, $otherValues);
                }
            }

            if (count($group) < $minGroup || count($rest) < $minGroup) {
                continue;
            }

            $p = Stats::mannWhitneyP($group, $rest);

            if ($p !== null && $p < $alpha / 7) {
                $found["dow_{$iso}"] = $p;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, float>  $series
     */
    private function storeBaselines(MetricStatistic $statistic, array $series, string $method, CarbonInterface $now): void
    {
        $rows = [];
        $significant = $this->significantDayTypes($series);

        foreach (self::windows() as $window) {
            $cutoff = $now->copy()->subDays($window)->startOfDay()->toDateString();
            $windowed = array_filter($series, fn ($v, $date) => $date >= $cutoff, ARRAY_FILTER_USE_BOTH);

            if (count($windowed) < 5) {
                continue;
            }

            $groups = [MetricBaseline::DAY_TYPE_ALL => [$windowed, null]];

            foreach ($significant as $dayType => $p) {
                $groups[$dayType] = [
                    array_filter($windowed, fn ($v, $date) => $this->inDayType((string) $date, $dayType), ARRAY_FILTER_USE_BOTH),
                    $p,
                ];
            }

            foreach ($groups as $dayType => [$group, $p]) {
                if (count($group) < ($dayType === MetricBaseline::DAY_TYPE_ALL ? 5 : 3)) {
                    continue;
                }

                $summary = $this->summarise(array_values($group), $method);

                $rows[$window . '|' . $dayType] = [
                    'window_days' => $window,
                    'day_type' => $dayType,
                    'method' => $method,
                    'centre' => $summary['centre'],
                    'spread' => $summary['spread'],
                    'lower' => $summary['lower'],
                    'upper' => $summary['upper'],
                    'sample_count' => count($group),
                    'effect_significance' => $p,
                ];
            }
        }

        foreach ($rows as $row) {
            MetricBaseline::updateOrCreate(
                [
                    'metric_statistic_id' => $statistic->id,
                    'subject_id' => null,
                    'window_days' => $row['window_days'],
                    'day_type' => $row['day_type'],
                ],
                $row + ['algorithm_version' => MetricBaseline::ALGORITHM_VERSION, 'computed_at' => $now],
            );
        }

        // Day types that stopped being significant, and windows that no longer fill.
        $keep = array_keys($rows);
        MetricBaseline::where('metric_statistic_id', $statistic->id)
            ->whereNull('subject_id')
            ->get()
            ->reject(fn (MetricBaseline $b) => in_array($b->window_days . '|' . $b->day_type, $keep, true))
            ->each->delete();
    }

    private function inDayType(string $date, string $dayType): bool
    {
        $iso = Carbon::parse($date)->dayOfWeekIso;

        return match (true) {
            $dayType === MetricBaseline::DAY_TYPE_WEEKDAY => $iso <= 5,
            $dayType === MetricBaseline::DAY_TYPE_WEEKEND => $iso >= 6,
            str_starts_with($dayType, 'dow_') => $iso === (int) substr($dayType, 4),
            default => true,
        };
    }

    private function clear(MetricStatistic $statistic, CarbonInterface $now): void
    {
        if (! $statistic->exists) {
            return;
        }

        $statistic->forceFill([
            'event_count' => 0,
            'first_event_at' => null,
            'last_event_at' => null,
            'min_value' => null,
            'max_value' => null,
            'mean_value' => null,
            'stddev_value' => null,
            'normal_lower_bound' => null,
            'normal_upper_bound' => null,
            'baseline_window_days' => (int) config('metrics.detection_window_days', 90),
            'last_calculated_at' => $now,
        ])->save();

        MetricBaseline::where('metric_statistic_id', $statistic->id)->delete();
    }
}
