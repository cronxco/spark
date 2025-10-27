<?php

namespace App\Spotlight\Queries\Navigation;

use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class DailyNavigationQuery
{
    /**
     * Create Spotlight query for daily navigation.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::asDefault(function (string $query) {
            $results = collect();

            // Always show if query is empty or matches
            if (blank($query) || str_contains('today', strtolower($query))) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Today')
                        ->setSubtitle('View today\'s events and timeline')
                        ->setTypeahead('Go to Today')
                        ->setIcon('calendar-days')
                        ->setGroup('navigation')
                        ->setPriority(1)
                        ->setAction('jump_to', ['path' => route('today.main')])
                );
            }

            if (blank($query) || str_contains('yesterday', strtolower($query))) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Yesterday')
                        ->setSubtitle('View yesterday\'s events and timeline')
                        ->setTypeahead('Go to Yesterday')
                        ->setIcon('calendar-days')
                        ->setGroup('navigation')
                        ->setPriority(2)
                        ->setAction('jump_to', ['path' => route('day.yesterday')])
                );
            }

            if (blank($query) || str_contains('tomorrow', strtolower($query))) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Tomorrow')
                        ->setSubtitle('View tomorrow\'s schedule')
                        ->setTypeahead('Go to Tomorrow')
                        ->setIcon('calendar-days')
                        ->setGroup('navigation')
                        ->setPriority(3)
                        ->setAction('jump_to', ['path' => route('tomorrow')])
                );
            }

            return $results;
        });
    }
}
