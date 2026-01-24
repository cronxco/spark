<?php

namespace App\Spotlight\Queries\Actions;

use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class BookmarkUrlQuery
{
    /**
     * Create Spotlight query that detects URLs and offers to bookmark them.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::asDefault(function (string $query) {
            // Don't show if query is empty or too short
            if (blank($query) || strlen($query) < 5) {
                return collect();
            }

            // Check if input looks like a URL
            if (! self::isValidUrl($query)) {
                return collect();
            }

            return collect([
                SpotlightResult::make()
                    ->setTitle('Bookmark This URL')
                    ->setSubtitle($query)
                    ->setTypeahead('Bookmark: '.$query)
                    ->setIcon('bookmark')
                    ->setGroup('actions')
                    ->setPriority(1)
                    ->setAction('dispatch_event', [
                        'name' => 'bookmark-url',
                        'data' => ['url' => $query],
                        'close' => true,
                    ]),
            ]);
        });
    }

    /**
     * Check if the input string is a valid URL.
     */
    private static function isValidUrl(string $input): bool
    {
        // Check for basic URL pattern with protocol
        if (preg_match('~^(?:f|ht)tps?://~i', $input)) {
            return filter_var($input, FILTER_VALIDATE_URL) !== false;
        }

        // Try adding http:// and validate
        $withProtocol = 'http://'.$input;
        if (filter_var($withProtocol, FILTER_VALIDATE_URL) !== false) {
            // Make sure it has at least a domain-like structure
            return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}/', $input);
        }

        return false;
    }
}
