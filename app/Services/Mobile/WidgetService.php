<?php

namespace App\Services\Mobile;

use App\Models\Event;
use App\Models\User;
use App\Services\DaySummaryService;
use Carbon\Carbon;

/**
 * Build small payloads for the iOS WidgetKit timeline entries.
 *
 * WidgetKit enforces a hard ~4 KB ceiling on encoded TimelineEntry objects,
 * so every shape returned here is ruthlessly minimal — the widget only needs
 * enough to render, not the full briefing.
 */
class WidgetService
{
    public function __construct(
        protected DaySummaryService $summaryService,
        protected MetricTrendService $trendService,
    ) {}

    /**
     * Collapse today's DaySummary into a ~1 KB headline payload.
     *
     * @return array<string, mixed>
     */
    public function today(User $user): array
    {
        $date = Carbon::today();
        $summary = $this->summaryService->generateSummary($user, $date);

        $metrics = $this->extractTopMetrics($summary);
        $headline = $this->buildHeadline($metrics, $summary['anomalies'] ?? []);
        $nextEvent = $this->extractNextEvent($user);

        return [
            'date' => $summary['date'] ?? $date->toDateString(),
            'headline' => $headline,
            'metrics' => $metrics,
            'next_event' => $nextEvent,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * 7-day sparkline for a single metric.
     *
     * @return array<string, mixed>|null
     */
    public function metric(User $user, string $metric): ?array
    {
        $trend = $this->trendService->trend($user, $metric, '7_days_ago', 'today');

        if ($trend === null) {
            return null;
        }

        $sparkline = collect($trend['daily_values'] ?? [])
            ->pluck('value')
            ->filter(fn ($v) => $v !== null)
            ->map(fn ($v) => (float) $v)
            ->take(-7)
            ->values()
            ->all();

        $current = end($sparkline);
        if ($current === false) {
            $current = null;
        }

        return [
            'metric' => $trend['metric'] ?? $metric,
            'value' => $current,
            'unit' => $trend['unit'] ?? null,
            'sparkline' => $sparkline,
        ];
    }

    /**
     * Today's Monzo spend + top 3 merchants.
     *
     * @return array<string, mixed>
     */
    public function spend(User $user): array
    {
        $integrationIds = $user->integrations()->where('service', 'monzo')->pluck('id')->all();

        if (empty($integrationIds)) {
            return [
                'date' => Carbon::today()->toDateString(),
                'total' => 0.0,
                'unit' => 'GBP',
                'currency' => 'GBP',
                'transaction_count' => 0,
                'top_merchants' => [],
            ];
        }

        $events = Event::query()
            ->whereIn('integration_id', $integrationIds)
            ->where('service', 'monzo')
            ->where('domain', 'money')
            ->whereDate('time', Carbon::today())
            ->with('target')
            ->get();

        $total = 0.0;
        $unit = 'GBP';
        $merchantTotals = [];

        foreach ($events as $event) {
            $amount = (float) $event->formatted_value;
            if ($amount <= 0) {
                continue;
            }

            $total += $amount;
            $unit = $event->value_unit ?? $unit;

            $merchantName = $event->target?->title ?? 'Unknown';
            $merchantTotals[$merchantName] = ($merchantTotals[$merchantName] ?? 0.0) + $amount;
        }

        arsort($merchantTotals);
        $top = array_slice($merchantTotals, 0, 3, true);

        $topMerchants = [];
        foreach ($top as $name => $amount) {
            $topMerchants[] = [
                'name' => $name,
                'amount' => round($amount, 2),
            ];
        }

        return [
            'date' => Carbon::today()->toDateString(),
            'total' => round($total, 2),
            'unit' => $unit,
            'currency' => $unit,
            'transaction_count' => $events->count(),
            'top_merchants' => $topMerchants,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function extractTopMetrics(array $summary): array
    {
        $metrics = [];

        foreach (['health', 'activity'] as $section) {
            $bucket = $summary['sections'][$section] ?? [];

            foreach ($bucket as $key => $entry) {
                if (count($metrics) >= 4) {
                    break 2;
                }

                if (! is_array($entry)) {
                    continue;
                }

                $value = $entry['score'] ?? $entry['value'] ?? null;
                if ($value === null) {
                    continue;
                }

                $metrics[] = [
                    'key' => $key,
                    'value' => $value,
                    'unit' => $entry['unit'] ?? null,
                ];
            }
        }

        return $metrics;
    }

    /**
     * @param  array<int, array<string, mixed>>  $metrics
     * @param  array<int, array<string, mixed>>  $anomalies
     */
    protected function buildHeadline(array $metrics, array $anomalies): ?string
    {
        if (! empty($anomalies)) {
            $first = $anomalies[0];
            $label = $first['action'] ?? $first['metric'] ?? 'metric';

            return "Anomaly detected: {$label}";
        }

        if (! empty($metrics)) {
            $first = $metrics[0];

            return ucwords(str_replace('_', ' ', (string) $first['key'])) . ' ' . $first['value'];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function extractNextEvent(User $user): ?array
    {
        $integrationIds = $user->integrations()->pluck('id')->all();

        if (empty($integrationIds)) {
            return null;
        }

        $event = Event::query()
            ->whereIn('integration_id', $integrationIds)
            ->where('time', '>=', now())
            ->orderBy('time', 'asc')
            ->limit(1)
            ->first();

        if (! $event) {
            return null;
        }

        return [
            'id' => $event->id,
            'time' => $event->time?->toIso8601String(),
            'action' => $event->action,
        ];
    }
}
