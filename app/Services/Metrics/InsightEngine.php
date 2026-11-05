<?php

namespace App\Services\Metrics;

use App\Models\MetricBaseline;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use App\Services\MetricPresentation;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Plain-language comparisons that say what is happening to a metric:
 *
 *  - period: the last seven days against the four weeks before ("steps this
 *    week above the 4-week trend")
 *  - typical: today's value against what is usual for this kind of day
 *    ("readiness lower than normal for a Thursday"), worded by day only when
 *    the data shows days really differ
 *  - shift: seven days running on one side of the usual
 *
 * Stored as MetricTrend rows with the evidence in `metadata`, at most
 * `max_insights_per_day` per person per day, most significant first.
 */
class InsightEngine
{
    public const PERIOD = 'insight_period';

    public const TYPICAL = 'insight_typical';

    public const SHIFT = 'insight_shift';

    /**
     * Distance, in standard errors, a week must be from the four weeks before.
     */
    private const PERIOD_SIGNIFICANCE = 2.5;

    /**
     * Distance, in spreads, today must be from the usual to be worth saying.
     */
    private const TYPICAL_SIGNIFICANCE = 1.5;

    public function __construct(
        private readonly MetricCatalogue $catalogue,
        private readonly MetricSeries $series,
        private readonly AnomalyPolicy $policy,
        private readonly MetricPresentation $presentation,
        private readonly BaselineService $baselines,
        private readonly SpendSeries $spend,
    ) {}

    /**
     * Work out and store today's insights for one person. Safe to run often:
     * an insight is stored once per metric, kind and end date.
     *
     * @return int how many insights are stored for today
     */
    public function generateForUser(User $user, ?CarbonInterface $now = null): int
    {
        $now = $now ? Carbon::instance($now) : now();
        $timezone = $user->getTimezone() ?? 'UTC';

        $statistics = MetricStatistic::query()
            ->where('user_id', $user->id)
            ->with('baselines')
            ->get()
            ->filter(fn (MetricStatistic $s) => $this->catalogue->producesInsights($s->service, $s->action));

        if ($this->spend->hasSpend($user->id)) {
            $spend = SpendSeries::statisticFor($user);

            // Nothing else discovers the spend metric, so keep its baseline fresh here.
            if ($spend->last_calculated_at === null || $spend->last_calculated_at->lt($now->copy()->subHours(12))) {
                $this->baselines->recompute($spend, $now);
            }

            if ($this->catalogue->producesInsights($spend->service, $spend->action)) {
                $statistics->push($spend->load('baselines'));
            }
        }

        $candidates = [];

        foreach ($statistics as $statistic) {
            $series = $this->series->daily($statistic, $timezone, (int) config('metrics.detection_window_days', 90), $now);

            foreach ($this->candidates($statistic, $series, $now) as $candidate) {
                $candidates[] = $candidate;
            }
        }

        usort($candidates, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $kept = array_slice($candidates, 0, (int) config('metrics.max_insights_per_day', 5));

        $storedIds = [];
        foreach ($kept as $candidate) {
            $storedIds[] = $this->store($candidate, $now)->id;
        }

        $dayStart = $now->copy()->setTimezone($timezone)->startOfDay();
        $dayEnd = $now->copy()->setTimezone($timezone)->endOfDay();

        // Anything stored earlier today that has dropped out of the top
        // few, and has not been acted on, goes.
        MetricTrend::query()
            ->whereHas('metricStatistic', fn ($q) => $q->where('user_id', $user->id))
            ->insights()
            ->whereNull('acknowledged_at')
            ->whereBetween('detected_at', [$dayStart, $dayEnd])
            ->whereNotIn('id', $storedIds)
            ->delete();

        return count($storedIds);
    }

    /**
     * @param  array<string, float>  $series  final daily values, date => value
     * @return array<int, array<string, mixed>>
     */
    public function candidates(MetricStatistic $statistic, array $series, CarbonInterface $now): array
    {
        if ($series === [] || ! $statistic->hasValidStatistics()) {
            return [];
        }

        $lastDate = array_key_last($series);

        // A series that stopped a few days ago has nothing to say today.
        if (Carbon::parse($lastDate)->lt(Carbon::instance($now)->utc()->subDays(3)->startOfDay())) {
            return [];
        }

        return array_values(array_filter([
            $this->period($statistic, $series, $lastDate),
            $this->typical($statistic, $series, $lastDate),
            $this->shift($statistic, $series, $lastDate),
        ]));
    }

    /**
     * @param  array<string, float>  $series
     * @return array<string, mixed>|null
     */
    private function period(MetricStatistic $statistic, array $series, string $lastDate): ?array
    {
        $last = Carbon::parse($lastDate);
        $recent = $this->between($series, $last->copy()->subDays(6), $last);
        $prior = $this->between($series, $last->copy()->subDays(34), $last->copy()->subDays(7));

        if (count($recent) < 5 || count($prior) < 14) {
            return null;
        }

        $method = $this->catalogue->method($statistic->service, $statistic->action);
        $spread = $method === MetricCatalogue::METHOD_MEDIAN_MAD ? Stats::scaledMad($prior) : Stats::stddev($prior);
        $spread = $spread > 0 ? $spread : Stats::stddev($prior);

        if ($spread <= 0.0) {
            return null;
        }

        $current = Stats::mean($recent);
        $reference = Stats::mean($prior);
        $z = ($current - $reference) / ($spread * sqrt(1 / count($recent) + 1 / count($prior)));

        if (abs($z) < self::PERIOD_SIGNIFICANCE) {
            return null;
        }

        // A tiny move in a very steady metric is real but not worth a card.
        if ($reference != 0.0 && abs($current - $reference) / abs($reference) < 0.05) {
            return null;
        }

        return $this->candidate(
            $statistic, self::PERIOD, $current, $reference, $z,
            $last->copy()->subDays(6), $last,
            ['comparison' => '4w trend', 'days' => count($recent), 'prior_days' => count($prior)],
        );
    }

    /**
     * @param  array<string, float>  $series
     * @return array<string, mixed>|null
     */
    private function typical(MetricStatistic $statistic, array $series, string $lastDate): ?array
    {
        $value = $series[$lastDate];
        $iso = Carbon::parse($lastDate)->dayOfWeekIso;
        $detection = (int) config('metrics.detection_window_days', 90);

        $rows = $statistic->baselines
            ->whereNull('subject_id')
            ->where('window_days', $detection)
            ->keyBy('day_type');

        $baseline = $rows->get("dow_{$iso}")
            ?? $rows->get(MetricBaseline::dayTypeForIsoWeekday($iso))
            ?? $rows->get(MetricBaseline::DAY_TYPE_ALL);

        if ($baseline === null || $baseline->spread <= 0.0) {
            return null;
        }

        // Against the all-days baseline this says what an anomaly already
        // says. Against a day type it adds the day ("for a Thursday").
        if ($baseline->day_type === MetricBaseline::DAY_TYPE_ALL && $this->policy->judge($statistic, $value) !== null) {
            return null;
        }

        $z = ($value - $baseline->centre) / $baseline->spread;

        if (abs($z) < self::TYPICAL_SIGNIFICANCE) {
            return null;
        }

        $date = Carbon::parse($lastDate);

        return $this->candidate(
            $statistic, self::TYPICAL, $value, $baseline->centre, $z, $date, $date,
            [
                'day_type' => $baseline->day_type,
                'day_label' => match (true) {
                    str_starts_with($baseline->day_type, 'dow_') => 'a '.$date->format('l'),
                    $baseline->day_type === MetricBaseline::DAY_TYPE_WEEKDAY => 'a weekday',
                    $baseline->day_type === MetricBaseline::DAY_TYPE_WEEKEND => 'a weekend day',
                    default => null,
                },
                'day_type_p' => $baseline->effect_significance,
                'comparison' => 'usual',
            ],
        );
    }

    /**
     * @param  array<string, float>  $series
     * @return array<string, mixed>|null
     */
    private function shift(MetricStatistic $statistic, array $series, string $lastDate): ?array
    {
        $last = Carbon::parse($lastDate);
        $recent = $this->between($series, $last->copy()->subDays(6), $last);
        $centre = (float) $statistic->mean_value;
        $spread = (float) $statistic->stddev_value;

        if (count($recent) < 7 || $spread <= 0.0) {
            return null;
        }

        $above = count(array_filter($recent, fn ($v) => $v > $centre));
        $below = count(array_filter($recent, fn ($v) => $v < $centre));

        if ($above !== 7 && $below !== 7) {
            return null;
        }

        $mean = Stats::mean($recent);
        $z = ($mean - $centre) / $spread;

        if (abs($z) < 1.0) {
            return null;
        }

        return $this->candidate(
            $statistic, self::SHIFT, $mean, $centre, $z,
            $last->copy()->subDays(6), $last,
            ['comparison' => 'usual', 'days' => 7],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function candidate(MetricStatistic $statistic, string $kind, float $current, float $reference, float $z, CarbonInterface $start, CarbonInterface $end, array $extra): array
    {
        $direction = $current >= $reference ? 'up' : 'down';
        $delta = abs($current - $reference);

        return [
            'statistic' => $statistic,
            'kind' => $kind,
            'type' => "{$kind}_{$direction}",
            'direction' => $direction,
            'current' => $current,
            'reference' => $reference,
            'z' => $z,
            'score' => abs($z),
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'metadata' => $extra + [
                'kind' => $kind,
                'delta' => $delta,
                'delta_display' => $this->presentation->formatValue($statistic, $delta),
                'current_display' => $this->presentation->formatValue($statistic, $current),
                'reference_display' => $this->presentation->formatValue($statistic, $reference),
                'valence' => $this->presentation->valence($statistic, $direction),
                'name' => $this->presentation->displayName($statistic),
                'method' => $this->catalogue->method($statistic->service, $statistic->action),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function store(array $candidate, CarbonInterface $now): MetricTrend
    {
        /** @var MetricStatistic $statistic */
        $statistic = $candidate['statistic'];
        $opposite = $candidate['kind'].'_'.($candidate['direction'] === 'up' ? 'down' : 'up');

        // The same comparison flipping side replaces the earlier one.
        MetricTrend::where('metric_statistic_id', $statistic->id)
            ->where('type', $opposite)
            ->whereDate('end_date', $candidate['end'])
            ->whereNull('acknowledged_at')
            ->delete();

        $trend = MetricTrend::firstOrNew([
            'metric_statistic_id' => $statistic->id,
            'type' => $candidate['type'],
            'end_date' => $candidate['end'],
        ]);

        $trend->fill([
            'detected_at' => $trend->exists ? $trend->detected_at : $now,
            'start_date' => $candidate['start'],
            'baseline_value' => $candidate['reference'],
            'current_value' => $candidate['current'],
            'deviation' => $candidate['z'],
            'significance_score' => tanh(abs($candidate['z']) / 2),
            'metadata' => $candidate['metadata'],
        ])->save();

        return $trend;
    }

    /**
     * @param  array<string, float>  $series
     * @return array<string, float>
     */
    private function between(array $series, CarbonInterface $from, CarbonInterface $to): array
    {
        return array_filter(
            $series,
            fn (float $v, string $date) => $date >= $from->toDateString() && $date <= $to->toDateString(),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
