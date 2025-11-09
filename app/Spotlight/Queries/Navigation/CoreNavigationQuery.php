<?php

namespace App\Spotlight\Queries\Navigation;

use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class CoreNavigationQuery
{
    /**
     * Create Spotlight query for core feature navigation.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::asDefault(function (string $query) {
            $results = collect();

            if (blank($query) || str_contains('tags', strtolower($query))) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Tags')
                        ->setSubtitle('Browse and manage tags')
                        ->setTypeahead('Go to Tags')
                        ->setIcon('tag')
                        ->setGroup('navigation')
                        ->setPriority(1)
                        ->setAction('jump_to', ['path' => route('tags.index')])
                );
            }

            if (blank($query) || str_contains('money', strtolower($query)) || str_contains('finance', strtolower($query))) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Money')
                        ->setSubtitle('View financial accounts and transactions')
                        ->setTypeahead('Go to Money')
                        ->setIcon('currency-pound')
                        ->setGroup('navigation')
                        ->setPriority(1)
                        ->setAction('jump_to', ['path' => route('money')])
                );
            }

            if (blank($query) || str_contains('metrics', strtolower($query)) || str_contains('trends', strtolower($query))) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Metrics')
                        ->setSubtitle('Track trends and anomalies in your data')
                        ->setTypeahead('Go to Metrics')
                        ->setIcon('chart-bar')
                        ->setGroup('navigation')
                        ->setPriority(1)
                        ->setAction('jump_to', ['path' => route('metrics.index')])
                );
            }

            if (blank($query) || str_contains('updates', strtolower($query))) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Updates')
                        ->setSubtitle('View recent integration updates')
                        ->setTypeahead('Go to Updates')
                        ->setIcon('arrow-path')
                        ->setGroup('navigation')
                        ->setPriority(2)
                        ->setAction('jump_to', ['path' => route('updates.index')])
                );
            }

            if (blank($query) || str_contains('bookmarks', strtolower($query))) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Bookmarks')
                        ->setSubtitle('View and manage your saved content')
                        ->setTypeahead('Go to Bookmarks')
                        ->setIcon('bookmark')
                        ->setGroup('navigation')
                        ->setPriority(2)
                        ->setAction('jump_to', ['path' => route('bookmarks.index')])
                );
            }

            return $results;
        });
    }
}
