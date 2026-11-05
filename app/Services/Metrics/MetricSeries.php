<?php

namespace App\Services\Metrics;

use App\Models\Event;
use App\Models\MetricStatistic;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * A metric's values as one number per day, for baselines and insights.
 *
 * Days are the UTC date of the event stamp, which is the day a daily
 * aggregate covers. When a day has more than one event the latest wins.
 */
class MetricSeries
{
    public function __construct(
        private readonly MetricCatalogue $catalogue,
        private readonly MetricCompleteness $completeness,
        private readonly SpendSeries $spend,
    ) {}

    /**
     * Daily values keyed by date (Y-m-d), oldest first. Days still filling in
     * are left out, so that neither a baseline nor a comparison is built on
     * an unfinished total. (Whether one value has stopped being revised is a
     * question for judging that value, not for the history behind it.)
     *
     * @return array<string, float>
     */
    public function daily(MetricStatistic $statistic, string $timezone, int $days, ?CarbonInterface $now = null, bool $finalOnly = true): array
    {
        $now = $now ? Carbon::instance($now) : now();
        $service = $statistic->service;
        $action = $statistic->action;
        $includeZeros = $this->keepsZeros($statistic);

        if (SpendSeries::isSpend($statistic)) {
            return $this->finalDays($statistic, $this->spend->daily($statistic->user_id, $days, $now), $timezone, $now, $finalOnly);
        }

        $services = array_unique(array_merge([$service], $this->catalogue->aliasesOf($this->catalogue->canonicalService($service)), [$this->catalogue->canonicalService($service)]));

        $events = Event::query()
            ->withoutInternal()
            ->whereHas('integration', fn ($q) => $q->where('user_id', $statistic->user_id))
            ->whereIn('service', $services)
            ->where('action', $action)
            ->where('value_unit', $statistic->value_unit)
            ->whereNotNull('value')
            ->where('time', '>=', $now->copy()->subDays($days)->startOfDay())
            ->orderBy('time')
            ->get(['id', 'time', 'value', 'value_multiplier']);

        $byDay = [];

        foreach ($events as $event) {
            if ($finalOnly && ! $this->completeness->isFinal($service, $action, $event->time, $timezone, $now)) {
                continue;
            }

            $value = $event->formatted_value;

            if ($value === null || (! $includeZeros && (float) $value === 0.0)) {
                continue;
            }

            $byDay[Carbon::instance($event->time)->utc()->toDateString()] = (float) $value;
        }

        ksort($byDay);

        return $byDay;
    }

    /**
     * Zero is a real value for money and no reading elsewhere.
     */
    public function keepsZeros(MetricStatistic $statistic): bool
    {
        return SpendSeries::isSpend($statistic)
            || in_array($statistic->service, config('metrics.spend.services', []), true);
    }

    /**
     * @param  array<string, float>  $byDay
     * @return array<string, float>
     */
    private function finalDays(MetricStatistic $statistic, array $byDay, string $timezone, CarbonInterface $now, bool $finalOnly): array
    {
        if (! $finalOnly) {
            return $byDay;
        }

        return array_filter($byDay, fn (float $v, string $date) => $this->completeness->isFinal(
            $statistic->service,
            $statistic->action,
            Carbon::parse($date, 'UTC'),
            $timezone,
            $now,
        ), ARRAY_FILTER_USE_BOTH);
    }
}
