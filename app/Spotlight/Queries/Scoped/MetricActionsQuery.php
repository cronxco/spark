<?php

namespace App\Spotlight\Queries\Scoped;

use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class MetricActionsQuery
{
    /**
     * Create Spotlight query for context-aware actions on metric detail pages.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('metric', function (string $query) {
            $results = collect();

            // Acknowledge All Trends
            if (blank($query) || str_contains(strtolower($query), 'acknowledge')) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Acknowledge All Trends')
                        ->setSubtitle('Mark all trends for this metric as acknowledged')
                        ->setIcon('check-circle')
                        ->setGroup('commands')
                        ->setPriority(1)
                        ->setAction('dispatch_event', [
                            'name' => 'acknowledge-all-trends',
                            'data' => [],
                            'close' => true,
                        ])
                );
            }

            // Calculate Statistics for This Metric
            if (blank($query) || str_contains(strtolower($query), 'calculate')) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Calculate Statistics for This Metric')
                        ->setSubtitle('Recalculate statistics for this metric only')
                        ->setIcon('calculator')
                        ->setGroup('commands')
                        ->setPriority(1)
                        ->setAction('dispatch_event', [
                            'name' => 'calculate-metric-statistics',
                            'data' => [],
                            'close' => true,
                        ])
                );
            }

            return $results;
        });
    }
}
