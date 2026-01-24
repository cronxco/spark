<?php

namespace App\Spotlight\Queries\Search;

use App\Models\MetricTrend;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class MetricTrendsQuery
{
    /**
     * Query for trends when a metric token is active.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('metric', function (string $query, $metricToken) {
            $metricId = $metricToken->getParameter('id');

            $trendsQuery = MetricTrend::where('metric_statistic_id', $metricId);

            if (! blank($query)) {
                $trendsQuery->where('type', 'ilike', "%{$query}%");
            }

            return $trendsQuery
                ->latest('detected_at')
                ->limit(5)
                ->get()
                ->map(function (MetricTrend $trend) {
                    $subtitle = ucfirst(str_replace('_', ' ', $trend->type));
                    $subtitle .= ' • Detected '.$trend->detected_at->diffForHumans();

                    if (! $trend->acknowledged_at) {
                        $subtitle .= ' • Unacknowledged';
                    }

                    return SpotlightResult::make()
                        ->setTitle('Trend: '.ucfirst(str_replace('_', ' ', $trend->type)))
                        ->setSubtitle($subtitle)
                        ->setIcon('chart-bar')
                        ->setGroup('metrics')
                        ->setPriority($trend->acknowledged_at ? 2 : 1)
                        ->setAction('dispatch_event', [
                            'name' => 'view-trend',
                            'data' => ['trendId' => $trend->id],
                            'close' => true,
                        ]);
                });
        });
    }
}
