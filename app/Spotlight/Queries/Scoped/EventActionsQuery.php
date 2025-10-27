<?php

namespace App\Spotlight\Queries\Scoped;

use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class EventActionsQuery
{
    /**
     * Create Spotlight query for context-aware actions on event detail pages.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('event', function (string $query) {
            $results = collect();

            // Tag Event
            if (blank($query) || str_contains(strtolower($query), 'tag')) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Tag Event')
                        ->setSubtitle('Add tags to this event')
                        ->setIcon('tag')
                        ->setGroup('commands')
                        ->setPriority(1)
                        ->setAction('dispatch_event', [
                            'name' => 'open-tag-modal',
                            'data' => [],
                            'close' => true,
                        ])
                );
            }

            // Edit Event
            if (blank($query) || str_contains(strtolower($query), 'edit')) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Edit Event')
                        ->setSubtitle('Edit event details')
                        ->setIcon('pencil')
                        ->setGroup('commands')
                        ->setPriority(2)
                        ->setAction('dispatch_event', [
                            'name' => 'open-edit-event-modal',
                            'data' => [],
                            'close' => true,
                        ])
                );
            }

            // Delete Event
            if (blank($query) || str_contains(strtolower($query), 'delete')) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Delete Event')
                        ->setSubtitle('Move this event to the bin')
                        ->setIcon('trash')
                        ->setGroup('commands')
                        ->setPriority(3)
                        ->setAction('dispatch_event', [
                            'name' => 'delete-event',
                            'data' => [],
                            'close' => true,
                        ])
                );
            }

            return $results;
        });
    }
}
