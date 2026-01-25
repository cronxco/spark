<?php

namespace App\Spotlight\Queries\Scoped;

use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class MetricAnomaliesQuery
{
    /**
     * Create Spotlight query for listing detected anomalies and trends for a metric.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('metric', function (string $query, $metricToken) {
            $metricId = $metricToken->getParameter('id');
            if (! $metricId) {
                return collect();
            }

            $metric = MetricStatistic::find($metricId);
            if (! $metric) {
                return collect();
            }

            // Get trends for this metric
            $trendsQuery = MetricTrend::where('metric_statistic_id', $metricId);

            // Filter by type if query provided
            if (! blank($query)) {
                $trendsQuery->where(function ($q) use ($query) {
                    $q->where('type', 'ilike', "%{$query}%")
                        ->orWhere('description', 'ilike', "%{$query}%");
                });
            }

            $trends = $trendsQuery
                ->latest('detected_at')
                ->limit(10)
                ->get();

            if ($trends->isEmpty()) {
                return collect();
            }

            return $trends->map(function ($trend) {
                // Determine icon and priority based on trend type
                $icon = match (true) {
                    str_contains($trend->type, 'anomaly_high') => 'arrow-trending-up',
                    str_contains($trend->type, 'anomaly_low') => 'arrow-trending-down',
                    str_contains($trend->type, 'spike') => 'bolt',
                    str_contains($trend->type, 'drop') => 'arrow-down',
                    str_contains($trend->type, 'increase') => 'chart-bar',
                    str_contains($trend->type, 'decrease') => 'chart-bar',
                    default => 'exclamation-triangle',
                };

                // Build title from type
                $title = match ($trend->type) {
                    'anomaly_high' => 'High Anomaly',
                    'anomaly_low' => 'Low Anomaly',
                    'sustained_increase' => 'Sustained Increase',
                    'sustained_decrease' => 'Sustained Decrease',
                    'spike' => 'Spike Detected',
                    'drop' => 'Drop Detected',
                    default => ucfirst(str_replace('_', ' ', $trend->type)),
                };

                // Build subtitle
                $subtitleParts = [];

                if ($trend->description) {
                    $subtitleParts[] = $trend->description;
                }

                // Add detection date
                $dateFormat = $trend->detected_at->format('M j, Y');
                $humanDate = $trend->detected_at->diffForHumans();
                $subtitleParts[] = "Detected {$humanDate}";

                // Add acknowledgement status
                if ($trend->acknowledged_at) {
                    $subtitleParts[] = 'Acknowledged';
                } else {
                    $subtitleParts[] = 'Unacknowledged';
                }

                $subtitle = implode(' • ', $subtitleParts);

                // Boost priority for unacknowledged and recent trends
                $priority = 5;
                if (! $trend->acknowledged_at) {
                    $priority -= 3;
                }
                if ($trend->detected_at->isAfter(now()->subWeek())) {
                    $priority -= 1;
                }

                return SpotlightResult::make()
                    ->setTitle($title)
                    ->setSubtitle($subtitle)
                    ->setIcon($icon)
                    ->setGroup('metrics')
                    ->setPriority($priority)
                    ->setAction('jump_to', ['path' => route('metrics.show', $trend->metric_statistic_id) . '#trend-' . $trend->id]);
            });
        });
    }
}
