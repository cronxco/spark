<?php

namespace App\Spotlight\Queries\Navigation;

use App\Support\AdminPages;
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

            $adminRoutes = AdminPages::visible();

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
