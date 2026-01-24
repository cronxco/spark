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
                    'title' => 'Sense Check',
                    'subtitle' => 'Review data integrity and consistency',
                    'icon' => 'beaker',
                    'route' => 'admin.sense-check.index',
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
                    'title' => 'Events',
                    'subtitle' => 'Browse all events',
                    'icon' => 'list-bullet',
                    'route' => 'admin.events.index',
                ],
                [
                    'title' => 'Objects',
                    'subtitle' => 'Browse all event objects',
                    'icon' => 'cube',
                    'route' => 'admin.objects.index',
                ],
                [
                    'title' => 'Blocks',
                    'subtitle' => 'Browse all data blocks',
                    'icon' => 'squares-2x2',
                    'route' => 'admin.blocks.index',
                ],
                [
                    'title' => 'GoCardless Admin',
                    'subtitle' => 'Manage GoCardless connections',
                    'icon' => 'credit-card',
                    'route' => 'admin.gocardless.index',
                ],
                [
                    'title' => 'Migrations',
                    'subtitle' => 'Run data migrations',
                    'icon' => 'arrow-path',
                    'route' => 'admin.migrations.index',
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
                            ->setTypeahead('Go to '.$routeConfig['title'])
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
