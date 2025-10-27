<?php

namespace App\Spotlight\Queries\Scoped;

use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class BlockActionsQuery
{
    /**
     * Create Spotlight query for context-aware actions on block detail pages.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('block', function (string $query) {
            $results = collect();

            // View Parent Event
            if (blank($query) || str_contains(strtolower($query), 'event') || str_contains(strtolower($query), 'parent')) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('View Parent Event')
                        ->setSubtitle('Jump to the event this block belongs to')
                        ->setIcon('arrow-up-circle')
                        ->setGroup('commands')
                        ->setPriority(1)
                        ->setAction('dispatch_event', [
                            'name' => 'jump-to-parent-event',
                            'data' => [],
                            'close' => true,
                        ])
                );
            }

            // Edit Block
            if (blank($query) || str_contains(strtolower($query), 'edit')) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Edit Block')
                        ->setSubtitle('Edit block details')
                        ->setIcon('pencil')
                        ->setGroup('commands')
                        ->setPriority(2)
                        ->setAction('dispatch_event', [
                            'name' => 'open-edit-block-modal',
                            'data' => [],
                            'close' => true,
                        ])
                );
            }

            // Delete Block
            if (blank($query) || str_contains(strtolower($query), 'delete')) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Delete Block')
                        ->setSubtitle('Move this block to the bin')
                        ->setIcon('trash')
                        ->setGroup('commands')
                        ->setPriority(3)
                        ->setAction('dispatch_event', [
                            'name' => 'delete-block',
                            'data' => [],
                            'close' => true,
                        ])
                );
            }

            return $results;
        });
    }
}
