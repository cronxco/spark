<?php

namespace App\Spotlight\Queries\Scoped;

use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class ObjectActionsQuery
{
    /**
     * Create Spotlight query for context-aware actions on object detail pages.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('object', function (string $query) {
            $results = collect();

            // Tag Object
            if (blank($query) || str_contains(strtolower($query), 'tag')) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Tag Object')
                        ->setSubtitle('Add tags to this object')
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

            // View Timeline
            if (blank($query) || str_contains(strtolower($query), 'timeline') || str_contains(strtolower($query), 'events')) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('View Timeline')
                        ->setSubtitle('See all events for this object')
                        ->setIcon('clock')
                        ->setGroup('commands')
                        ->setPriority(2)
                        ->setAction('dispatch_event', [
                            'name' => 'show-timeline',
                            'data' => [],
                            'close' => true,
                        ])
                );
            }

            // Edit Object
            if (blank($query) || str_contains(strtolower($query), 'edit')) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Edit Object')
                        ->setSubtitle('Edit object details')
                        ->setIcon('pencil')
                        ->setGroup('commands')
                        ->setPriority(2)
                        ->setAction('dispatch_event', [
                            'name' => 'open-edit-object-modal',
                            'data' => [],
                            'close' => true,
                        ])
                );
            }

            // Manage Relationships
            if (blank($query) || str_contains(strtolower($query), 'relationship') || str_contains(strtolower($query), 'link') || str_contains(strtolower($query), 'connect')) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Manage Relationships')
                        ->setSubtitle('Connect this object to other items')
                        ->setIcon('fas.right-left')
                        ->setGroup('commands')
                        ->setPriority(3)
                        ->setAction('dispatch_event', [
                            'name' => 'open-manage-relationships-modal',
                            'data' => [],
                            'close' => true,
                        ])
                );
            }
            if (blank($query) || str_contains(strtolower($query), 'relationship') || str_contains(strtolower($query), 'add') || str_contains(strtolower($query), 'link') || str_contains(strtolower($query), 'connect')) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Add Relationship')
                        ->setSubtitle('Connect this object to other items')
                        ->setIcon('fas.right-left')
                        ->setGroup('commands')
                        ->setPriority(3)
                        ->setAction('dispatch_event', [
                            'name' => 'open-add-relationship-modal',
                            'data' => [],
                            'close' => true,
                        ])
                );
            }

            // Delete Object
            if (blank($query) || str_contains(strtolower($query), 'delete')) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Delete Object')
                        ->setSubtitle('Move this object to the bin')
                        ->setIcon('trash')
                        ->setGroup('commands')
                        ->setPriority(4)
                        ->setAction('dispatch_event', [
                            'name' => 'delete-object',
                            'data' => [],
                            'close' => true,
                        ])
                );
            }

            return $results;
        });
    }
}
