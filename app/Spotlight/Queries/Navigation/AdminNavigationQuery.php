<?php

namespace App\Spotlight\Queries\Navigation;

use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class AdminNavigationQuery
{
    /**
     * Create Spotlight query for admin navigation.
     * Only shows when admin mode is triggered with "!"
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forMode('admin', function (string $query) {
            $results = collect();

            // Admin routes
            $adminRoutes = [
                [
                    'title' => 'Pulse',
                    'subtitle' => 'Data checks and one-off fixes',
                    'icon' => 'heart',
                    'route' => 'admin.pulse.index',
                ],
                [
                    'title' => 'Search Analytics',
                    'subtitle' => 'Monitor semantic search performance',
                    'icon' => 'magnifying-glass',
                    'route' => 'admin.search.index',
                ],
                [
                    'title' => 'Activity Log',
                    'subtitle' => 'View system activity and changes',
                    'icon' => 'clock',
                    'route' => 'admin.activity.index',
                ],
                [
                    'title' => 'Data',
                    'subtitle' => 'Events, objects, blocks and relationships',
                    'icon' => 'list-bullet',
                    'route' => 'admin.events.index',
                ],
                [
                    'title' => 'GoCardless Admin',
                    'subtitle' => 'Manage GoCardless connections',
                    'icon' => 'credit-card',
                    'route' => 'admin.gocardless.index',
                ],
                [
                    'title' => 'Logs',
                    'subtitle' => 'View application logs',
                    'icon' => 'document-text',
                    'route' => 'admin.logs.index',
                ],
                [
                    'title' => 'Bin',
                    'subtitle' => 'View and manage deleted items',
                    'icon' => 'trash',
                    'route' => 'admin.bin.index',
                ],
            ];

            foreach ($adminRoutes as $routeConfig) {
                if (blank($query) || str_contains(strtolower($routeConfig['title']), strtolower($query))) {
                    $results->push(
                        SpotlightResult::make()
                            ->setTitle($routeConfig['title'])
                            ->setSubtitle($routeConfig['subtitle'])
                            ->setTypeahead('Go to ' . $routeConfig['title'])
                            ->setIcon($routeConfig['icon'])
                            ->setGroup('admin')
                            ->setPriority(1)
                            ->setAction('jump_to', ['path' => route($routeConfig['route'])])
                    );
                }
            }

            return $results;
        });
    }
}
