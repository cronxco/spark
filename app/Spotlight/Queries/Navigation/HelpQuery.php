<?php

namespace App\Spotlight\Queries\Navigation;

use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class HelpQuery
{
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forMode('help', function (string $query) {
            $helpItems = [
                [
                    'title' => 'Keyboard Shortcuts',
                    'subtitle' => 'Learn all available keyboard shortcuts',
                    'content' => 'Cmd+K: Open/close • Arrows: Navigate • Enter: Select • Tab: Apply filter',
                ],
                [
                    'title' => 'Search Modes',
                    'subtitle' => 'Use prefixes to filter your search',
                    'content' => '> Actions • # Tags • $ Metrics • @ Integrations • ! Admin',
                ],
                [
                    'title' => 'Context-Aware Commands',
                    'subtitle' => 'Different commands on different pages',
                    'content' => 'Commands change based on what page you\'re viewing',
                ],
                [
                    'title' => 'Quick Navigation',
                    'subtitle' => 'Jump to pages instantly',
                    'content' => 'Type: today, yesterday, tomorrow, tags, money, metrics, settings',
                ],
                [
                    'title' => 'Search Everything',
                    'subtitle' => 'Search events, objects, and blocks',
                    'content' => 'Just start typing to search across all your data',
                ],
                [
                    'title' => 'Token Filtering',
                    'subtitle' => 'Filter results by context',
                    'content' => 'On detail pages, results are automatically filtered to that context',
                ],
            ];

            return collect($helpItems)
                ->filter(function ($item) use ($query) {
                    if (blank($query)) {
                        return true;
                    }

                    return str_contains(strtolower($item['title']), strtolower($query)) ||
                           str_contains(strtolower($item['subtitle']), strtolower($query)) ||
                           str_contains(strtolower($item['content']), strtolower($query));
                })
                ->map(function ($item) {
                    return SpotlightResult::make()
                        ->setTitle($item['title'])
                        ->setSubtitle($item['subtitle'])
                        ->setTypeahead($item['content'])
                        ->setIcon('question-mark-circle')
                        ->setGroup('navigation')
                        ->setPriority(1)
                        ->setAction('dispatch_event', [
                            'name' => 'show-help-tip',
                            'data' => ['content' => $item['content']],
                        ]);
                });
        });
    }
}
