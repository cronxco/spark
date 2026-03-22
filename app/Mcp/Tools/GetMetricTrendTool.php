<?php

namespace App\Mcp\Tools;

use App\Mcp\Helpers\DateParser;
use App\Mcp\Helpers\MetricIdentifierMap;
use App\Models\Event;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsIdempotent]
#[IsReadOnly]
class GetMetricTrendTool extends Tool
{
    use DateParser;

    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Retrieve daily metric values over a date range with baseline comparison.
        Returns per-day values, vs_baseline_pct, anomaly flags, and summary statistics.
        Accepts flexible identifiers: "oura.had_sleep_score.percent", "oura.sleep_score", etc.
        The "had_" prefix and value_unit can be omitted when unambiguous.
        Use get-baselines to discover available metrics.
    MARKDOWN;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user) {
            return Response::error('Authentication required.');
        }

        $metricId = $request->get('metric');
        $statistic = MetricIdentifierMap::resolve($metricId, $user);

        if (! $statistic) {
            $service = explode('.', $metricId)[0] ?? '';

            return Response::error("Unknown metric identifier: {$metricId}. " . MetricIdentifierMap::availableForService($service, $user));
        }

        // Parse date range
        $from = $request->get('from', '30_days_ago');
        $to = $request->get('to', 'today');
        $dateRange = $this->parseDateRange($from, $to);

        if (! $dateRange) {
            return Response::error('Invalid date range.');
        }

        [$startDate, $endDate] = $dateRange;

        // Query events for the metric within date range
        $events = Event::query()
            ->whereHas('integration', fn ($q) => $q->where('user_id', $user->id))
            ->where('service', $statistic->service)
            ->where('action', $statistic->action)
            ->whereBetween('time', [$startDate, $endDate])
            ->orderBy('time', 'asc')
            ->get();

        // Group by date, take latest event per day
        $hasValidStats = $statistic->hasValidStatistics();
        $dailyValues = $events->groupBy(fn ($e) => $e->time->toDateString())
            ->map(function ($dayEvents) use ($statistic, $hasValidStats) {
                $latest = $dayEvents->sortByDesc('time')->first();
                $value = $latest->formatted_value;

                $entry = [
                    'date' => $latest->time->toDateString(),
                    'value' => $value,
                ];

                if ($hasValidStats) {
                    $baseline = $statistic->mean_value;
                    $entry['vs_baseline_pct'] = $baseline != 0
                        ? round((($value - $baseline) / abs($baseline)) * 100, 1)
                        : 0;
                    $entry['is_anomaly'] = $value < $statistic->normal_lower_bound
                        || $value > $statistic->normal_upper_bound;
                }

                return $entry;
            })->values()->all();

        // Calculate summary stats
        $values = collect($dailyValues)->pluck('value')->filter(fn ($v) => $v !== null);
        $summary = [];

        if ($values->isNotEmpty()) {
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
                'trend_direction' => $secondMean > $firstMean ? 'up' : ($secondMean < $firstMean ? 'down' : 'stable'),
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
        }

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
            $result['baseline'] = [
                'mean' => round($statistic->mean_value, 2),
                'stddev' => round($statistic->stddev_value, 2),
                'normal_lower' => round($statistic->normal_lower_bound, 2),
                'normal_upper' => round($statistic->normal_upper_bound, 2),
                'sample_days' => $statistic->event_count,
            ];
        }

        return Response::text(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'metric' => $schema->string()
                ->description('Metric identifier in dot notation (e.g. "oura.sleep_score", "oura.had_sleep_score.percent"). The "had_" prefix and value_unit can be omitted when unambiguous. Use get-baselines to discover available metrics.')
                ->required(),

            'from' => $schema->string()
                ->description('Start date. ISO format, relative ("yesterday", "7_days_ago"), or range keyword ("last_7_days", "this_week", "last_month"). Defaults to "30_days_ago".')
                ->default('30_days_ago'),

            'to' => $schema->string()
                ->description('End date. ISO format or relative. Defaults to "today".')
                ->default('today'),
        ];
    }
}
