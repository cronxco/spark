<?php

namespace App\Spotlight\Queries\Navigation;

use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class FetchNavigationQuery
{
    /**
     * Create Spotlight query for Fetch feature navigation.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::asDefault(function (string $query) {
            $results = collect();

            $lowerQuery = strtolower($query);
            $isFetchQuery = blank($query) || str_contains($lowerQuery, 'fetch');

            // Main Fetch URLs entry
            if ($isFetchQuery) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Fetch URLs')
                        ->setSubtitle('Monitor and manage subscribed URLs')
                        ->setTypeahead('Go to Fetch URLs')
                        ->setIcon('link')
                        ->setGroup('navigation')
                        ->setPriority(1)
                        ->setAction('jump_to', ['path' => route('bookmarks').'?tab=urls'])
                );
            }

            // Fetch Discovery tab
            if ($isFetchQuery && (blank($query) || str_contains($lowerQuery, 'discover'))) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Fetch Discovery')
                        ->setSubtitle('Discover new URLs to monitor')
                        ->setTypeahead('Go to Fetch Discovery')
                        ->setIcon('sparkles')
                        ->setGroup('navigation')
                        ->setPriority(2)
                        ->setAction('jump_to', ['path' => route('bookmarks').'?tab=discovery'])
                );
            }

            // Fetch Cookies tab
            if ($isFetchQuery && (blank($query) || str_contains($lowerQuery, 'cookie'))) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Fetch Cookies')
                        ->setSubtitle('Manage authentication cookies')
                        ->setTypeahead('Go to Fetch Cookies')
                        ->setIcon('lock-open')
                        ->setGroup('navigation')
                        ->setPriority(3)
                        ->setAction('jump_to', ['path' => route('bookmarks').'?tab=cookies'])
                );
            }

            // Fetch Playwright tab
            if ($isFetchQuery && (blank($query) || str_contains($lowerQuery, 'playwright') || str_contains($lowerQuery, 'browser'))) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Fetch Playwright')
                        ->setSubtitle('Browser automation settings')
                        ->setTypeahead('Go to Fetch Playwright')
                        ->setIcon('cpu-chip')
                        ->setGroup('navigation')
                        ->setPriority(4)
                        ->setAction('jump_to', ['path' => route('bookmarks').'?tab=playwright'])
                );
            }

            // Fetch API tab
            if ($isFetchQuery && (blank($query) || str_contains($lowerQuery, 'api'))) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Fetch API')
                        ->setSubtitle('API access and documentation')
                        ->setTypeahead('Go to Fetch API')
                        ->setIcon('key')
                        ->setGroup('navigation')
                        ->setPriority(5)
                        ->setAction('jump_to', ['path' => route('bookmarks').'?tab=api'])
                );
            }

            return $results;
        });
    }
}
