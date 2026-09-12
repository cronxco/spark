<?php

namespace App\Services\Mobile;

use App\Mcp\Helpers\DateParser;
use App\Mcp\Helpers\MetricIdentifierMap;
use App\Models\Event;
use App\Models\MetricStatistic;
use App\Models\User;
use App\Services\MetricPresentation;

class MetricTrendService
{
    use DateParser;

    /**
     * Resolve an opaque metric identifier to a MetricStatistic for the user.
     */
    public function resolve(string $metricId, User $user): ?MetricStatistic
    {
        return MetricIdentifierMap::resolve($metricId, $user);
    }

    /**
     * Build the full trend payload: per-day values, baseline comparison, summary stats.
     *
     * Mirrors GetMetricTrendTool exactly — so mobile and MCP return the same
     * shape. The service deliberately returns an array (not a DTO) because
     * the payload is leaf-like and consumers just forward it to JSON.
     *
     * @return array<string, mixed>|null
     */
    public function trend(User $user, string $metricId, string $from = '30_days_ago', string $to = 'today'): ?array
    {
        $statistic = $this->resolve($metricId, $user);

        if (! $statistic) {
            return null;
        }

        $dateRange = $this->parseDateRange($from, $to);
        if (! $dateRange) {
            return null;
        }

        [$startDate, $endDate] = $dateRange;

        // Select only the columns we actually use — `events` rows carry a
        // 1536-dim embeddings vector and JSON metadata, and pulling them is
        // pure egress waste.
        $events = Event::query()
            ->withoutInternal()
            ->whereHas('integration', fn ($q) => $q->where('user_id', $user->id))
            ->where('service', $statistic->service)
            ->where('action', $statistic->action)
            // A MetricStatistic is identified by service + action + value_unit,
            // so an action reported in more than one unit has more than one
            // baseline. Without this filter the other unit's events are scored
            // against this statistic — and since presentation now branches on
            // whether the statistic is ordinal, a continuous value could be
            // rendered as an ordinal band.
            ->when(
                $statistic->value_unit === null,
                fn ($q) => $q->whereNull('value_unit'),
                fn ($q) => $q->where('value_unit', $statistic->value_unit),
            )
            ->whereBetween('time', [$startDate, $endDate])
            ->orderBy('time', 'asc')
            ->get(['id', 'time', 'value', 'value_multiplier']);

        $hasValidStats = $statistic->hasValidStatistics();
        $presentation = app(MetricPresentation::class);
        $isOrdinal = $presentation->isOrdinal($statistic);

        $dailyValues = $events->groupBy(fn ($e) => $e->time->toDateString())
            ->map(function ($dayEvents) use ($statistic, $hasValidStats, $presentation, $isOrdinal) {
                $latest = $dayEvents->sortByDesc('time')->first();
                $value = $latest->formatted_value;

                $entry = [
                    'date' => $latest->time->toDateString(),
                    'value' => $value,
                ];

                if ($hasValidStats) {
                    // Ordinal metrics carry their band instead of a percentage
                    // against a fractional mean — see
                    // MetricPresentation::baselineDeltaPct().
                    if ($isOrdinal) {
                        $entry['band'] = $presentation->formatValue($statistic, $value);
                    } else {
                        $entry['vs_baseline_pct'] = $presentation->baselineDeltaPct($statistic, $value);
                    }

                    $entry['is_anomaly'] = $presentation->isAnomalous($statistic, $value);
                }

                return $entry;
            })->values()->all();

        $summary = $this->buildSummary($dailyValues, $hasValidStats);

        $result = [
            'metric' => $statistic->getIdentifier(),
            'service' => $statistic->service,
            'action' => $statistic->action,
            'unit' => $statistic->value_unit,
            'range' => [
                'from' => $startDate->toDateString(),
                'to' => $endDate->toDateString(),
            ],
            'daily_values' => $dailyValues,
            'summary' => $summary,
        ];

        if ($hasValidStats) {
            $result['is_ordinal'] = $isOrdinal;
            $result['baseline'] = [
                'mean' => round($statistic->mean_value, 2),
                'stddev' => round($statistic->stddev_value, 2),
                'normal_lower' => round($statistic->normal_lower_bound, 2),
                'normal_upper' => round($statistic->normal_upper_bound, 2),
                'sample_days' => $statistic->event_count,
            ];

            if ($isOrdinal) {
                $result['baseline']['usual_band'] = $presentation->formatValue(
                    $statistic,
                    round((float) $statistic->mean_value)
                );
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $dailyValues
     * @return array<string, mixed>
     */
    private function buildSummary(array $dailyValues, bool $hasValidStats): array
    {
        $values = collect($dailyValues)->pluck('value')->filter(fn ($v) => $v !== null);

        if ($values->isEmpty()) {
            return [];
        }

        $halfPoint = (int) floor($values->count() / 2);
        $firstHalf = $values->take($halfPoint);
        $secondHalf = $values->skip($halfPoint);

        $firstMean = $firstHalf->isNotEmpty() ? $firstHalf->avg() : 0;
        $secondMean = $secondHalf->isNotEmpty() ? $secondHalf->avg() : 0;

        $summary = [
            'min' => round($values->min(), 2),
            'max' => round($values->max(), 2),
            'mean' => round($values->avg(), 2),
            'data_points' => $values->count(),
            'trend_direction' => $secondMean > $firstMean
                ? 'up'
                : ($secondMean < $firstMean ? 'down' : 'stable'),
        ];

        if ($hasValidStats) {
            $anomalyStreak = 0;
            $currentStreak = 0;

            foreach ($dailyValues as $day) {
                if ($day['is_anomaly'] ?? false) {
                    $currentStreak++;
                    $anomalyStreak = max($anomalyStreak, $currentStreak);
                } else {
                    $currentStreak = 0;
                }
            }

            $summary['max_anomaly_streak'] = $anomalyStreak;
        }

        return $summary;
    }
}
