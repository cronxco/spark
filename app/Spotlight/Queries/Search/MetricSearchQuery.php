<?php

namespace App\Spotlight\Queries\Search;

use App\Models\MetricStatistic;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class MetricSearchQuery
{
    /**
     * Create Spotlight query for searching metrics (mode-specific).
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forMode('metrics', function (string $query) {
            $metricsQuery = MetricStatistic::with('trends')
                ->withCount(['trends as recent_anomalies_count' => function ($q) {
                    $q->where('detected_at', '>=', now()->subWeek())
                        ->where('type', 'like', 'anomaly_%');
                }])
                ->withCount(['trends as unacknowledged_trends_count' => function ($q) {
                    $q->whereNull('acknowledged_at');
                }]);

            if (! blank($query)) {
                $metricsQuery->where(function ($q) use ($query) {
                    $q->where('service', 'ilike', "%{$query}%")
                        ->orWhere('action', 'ilike', "%{$query}%")
                        ->orWhere('value_unit', 'ilike', "%{$query}%");
                });
            }

            return $metricsQuery
                ->limit(5)
                ->get()
                ->map(function (MetricStatistic $metric) {
                    $formattedValue = format_event_value_display(
                        $metric->mean_value,
                        $metric->value_unit,
                        $metric->service,
                        $metric->action
                    );

                    $subtitle = ucfirst($metric->service) . ' • ' . $formattedValue;

                    if ($metric->recent_anomalies_count > 0) {
                        $subtitle .= ' • ' . $metric->recent_anomalies_count . ' recent ' . str('anomaly')->plural($metric->recent_anomalies_count);
                    }

                    // Boost priority for metrics with unacknowledged trends
                    $priority = $metric->unacknowledged_trends_count > 0 ? 1 : 2;

                    return SpotlightResult::make()
                        ->setTitle($metric->getDisplayName())
                        ->setSubtitle($subtitle)
                        ->setTypeahead('Metric: ' . $metric->getDisplayName())
                        ->setIcon('chart-bar')
                        ->setGroup('metrics')
                        ->setPriority($priority)
                        ->setAction('jump_to', ['path' => route('metrics.show', $metric)]);
                });
        });
    }
}
