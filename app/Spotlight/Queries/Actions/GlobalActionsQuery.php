<?php

namespace App\Spotlight\Queries\Actions;

use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class GlobalActionsQuery
{
    /**
     * Create Spotlight query for global actions (triggered by ">" mode).
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forMode('actions', function (string $query) {
            $actions = [
                [
                    'title' => 'Calculate Statistics',
                    'subtitle' => 'Recalculate metric statistics for all metrics',
                    'icon' => 'calculator',
                    'event' => 'calculate-statistics',
                ],
                [
                    'title' => 'Detect Trends',
                    'subtitle' => 'Run trend detection on all metrics',
                    'icon' => 'chart-bar',
                    'event' => 'detect-trends',
                ],
                [
                    'title' => 'Trigger All Integration Updates',
                    'subtitle' => 'Fetch latest data from all integrations',
                    'icon' => 'arrow-path',
                    'event' => 'trigger-all-integrations',
                ],
                [
                    'title' => 'Create New Tag',
                    'subtitle' => 'Add a new tag to organize your data',
                    'icon' => 'tag',
                    'event' => 'open-create-tag-modal',
                ],
                [
                    'title' => 'View Recent Activity',
                    'subtitle' => 'See recent system activity and changes',
                    'icon' => 'clock',
                    'route' => 'admin.activity.index',
                ],
            ];

            $results = collect();

            foreach ($actions as $actionConfig) {
                if (blank($query) || str_contains(strtolower($actionConfig['title']), strtolower($query))) {
                    $result = SpotlightResult::make()
                        ->setTitle($actionConfig['title'])
                        ->setSubtitle($actionConfig['subtitle'])
                        ->setTypeahead('Action: ' . $actionConfig['title'])
                        ->setIcon($actionConfig['icon'])
                        ->setGroup('commands')
                        ->setPriority(1);

                    if (isset($actionConfig['event'])) {
                        $result->setAction('dispatch_event', [
                            'name' => $actionConfig['event'],
                            'data' => [],
                            'close' => true,
                        ]);
                    } elseif (isset($actionConfig['route'])) {
                        $result->setAction('jump_to', ['path' => route($actionConfig['route'])]);
                    }

                    $results->push($result);
                }
            }

            return $results;
        });
    }
}
