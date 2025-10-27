<?php

namespace App\Spotlight\Queries\Actions;

use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class ContextualActionsQuery
{
    /**
     * Create Spotlight query for context-aware actions.
     * Shows different actions based on the current route.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::asDefault(function (string $query) {
            $route = request()->route();
            if (! $route) {
                return collect();
            }

            $routeName = $route->getName();
            $results = collect();

            // Context: Metrics Overview Page
            if ($routeName === 'metrics.index') {
                if (blank($query) || str_contains(strtolower($query), 'calculate')) {
                    $results->push(
                        SpotlightResult::make()
                            ->setTitle('Calculate All Statistics')
                            ->setSubtitle('Recalculate statistics for all metrics')
                            ->setIcon('calculator')
                            ->setGroup('commands')
                            ->setPriority(1)
                            ->setAction('dispatch_event', [
                                'name' => 'calculate-statistics',
                                'close' => true,
                            ])
                    );
                }

                if (blank($query) || str_contains(strtolower($query), 'detect') || str_contains(strtolower($query), 'trends')) {
                    $results->push(
                        SpotlightResult::make()
                            ->setTitle('Detect All Trends')
                            ->setSubtitle('Run trend detection on all metrics')
                            ->setIcon('chart-bar')
                            ->setGroup('commands')
                            ->setPriority(1)
                            ->setAction('dispatch_event', [
                                'name' => 'detect-trends',
                                'close' => true,
                            ])
                    );
                }
            }

            // Context: Metric Detail Page
            if ($routeName === 'metrics.show') {
                if (blank($query) || str_contains(strtolower($query), 'acknowledge')) {
                    $results->push(
                        SpotlightResult::make()
                            ->setTitle('Acknowledge All Trends')
                            ->setSubtitle('Mark all trends for this metric as acknowledged')
                            ->setIcon('check-circle')
                            ->setGroup('commands')
                            ->setPriority(1)
                            ->setAction('dispatch_event', [
                                'name' => 'acknowledge-all-trends',
                                'close' => true,
                            ])
                    );
                }

                if (blank($query) || str_contains(strtolower($query), 'calculate')) {
                    $results->push(
                        SpotlightResult::make()
                            ->setTitle('Calculate Statistics for This Metric')
                            ->setSubtitle('Recalculate statistics for this metric only')
                            ->setIcon('calculator')
                            ->setGroup('commands')
                            ->setPriority(1)
                            ->setAction('dispatch_event', [
                                'name' => 'calculate-metric-statistics',
                                'close' => true,
                            ])
                    );
                }
            }

            // Context: Financial Account Detail Page
            if ($routeName === 'money.show') {
                if (blank($query) || str_contains(strtolower($query), 'balance') || str_contains(strtolower($query), 'add')) {
                    $results->push(
                        SpotlightResult::make()
                            ->setTitle('Add Balance Update')
                            ->setSubtitle('Manually add a balance update for this account')
                            ->setIcon('plus-circle')
                            ->setGroup('commands')
                            ->setPriority(1)
                            ->setAction('dispatch_event', [
                                'name' => 'open-balance-modal',
                                'close' => true,
                            ])
                    );
                }

                if (blank($query) || str_contains(strtolower($query), 'archive')) {
                    $results->push(
                        SpotlightResult::make()
                            ->setTitle('Archive Account')
                            ->setSubtitle('Archive this financial account')
                            ->setIcon('archive-box')
                            ->setGroup('commands')
                            ->setPriority(2)
                            ->setAction('dispatch_event', [
                                'name' => 'open-archive-modal',
                                'close' => true,
                            ])
                    );
                }

                if (blank($query) || str_contains(strtolower($query), 'edit')) {
                    $results->push(
                        SpotlightResult::make()
                            ->setTitle('Edit Account')
                            ->setSubtitle('Edit account name and settings')
                            ->setIcon('pencil')
                            ->setGroup('commands')
                            ->setPriority(2)
                            ->setAction('dispatch_event', [
                                'name' => 'open-edit-modal',
                                'close' => true,
                            ])
                    );
                }
            }

            // Context: Integration Detail Page
            if ($routeName === 'integrations.details') {
                if (blank($query) || str_contains(strtolower($query), 'trigger') || str_contains(strtolower($query), 'update')) {
                    $results->push(
                        SpotlightResult::make()
                            ->setTitle('Trigger Update Now')
                            ->setSubtitle('Fetch latest data from this integration')
                            ->setIcon('arrow-path')
                            ->setGroup('commands')
                            ->setPriority(1)
                            ->setAction('dispatch_event', [
                                'name' => 'trigger-integration-update',
                                'close' => true,
                            ])
                    );
                }

                if (blank($query) || str_contains(strtolower($query), 'pause') || str_contains(strtolower($query), 'resume')) {
                    $results->push(
                        SpotlightResult::make()
                            ->setTitle('Pause/Resume Integration')
                            ->setSubtitle('Toggle whether this integration updates automatically')
                            ->setIcon('pause-circle')
                            ->setGroup('commands')
                            ->setPriority(2)
                            ->setAction('dispatch_event', [
                                'name' => 'toggle-integration-pause',
                                'close' => true,
                            ])
                    );
                }

                if (blank($query) || str_contains(strtolower($query), 'configure')) {
                    $results->push(
                        SpotlightResult::make()
                            ->setTitle('Configure Integration')
                            ->setSubtitle('Change integration settings')
                            ->setIcon('cog')
                            ->setGroup('commands')
                            ->setPriority(2)
                            ->setAction('dispatch_event', [
                                'name' => 'open-configure-modal',
                                'close' => true,
                            ])
                    );
                }
            }

            // Context: Admin Bin Page
            if ($routeName === 'admin.bin.index') {
                if (blank($query) || str_contains(strtolower($query), 'clear') || str_contains(strtolower($query), 'delete')) {
                    $results->push(
                        SpotlightResult::make()
                            ->setTitle('Clear Bin (Destructive)')
                            ->setSubtitle('Permanently delete all items in the bin')
                            ->setIcon('trash')
                            ->setGroup('commands')
                            ->setPriority(1)
                            ->setAction('clear_bin', [])
                    );
                }
            }

            // Context: Event Detail Page
            if ($routeName === 'events.show') {
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
                                'close' => true,
                            ])
                    );
                }

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
                                'close' => true,
                            ])
                    );
                }

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
                                'close' => true,
                            ])
                    );
                }
            }

            // Context: Object Detail Page
            if ($routeName === 'objects.show') {
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
                                'close' => true,
                            ])
                    );
                }

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
                                'close' => true,
                            ])
                    );
                }

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
                                'close' => true,
                            ])
                    );
                }

                if (blank($query) || str_contains(strtolower($query), 'delete')) {
                    $results->push(
                        SpotlightResult::make()
                            ->setTitle('Delete Object')
                            ->setSubtitle('Move this object to the bin')
                            ->setIcon('trash')
                            ->setGroup('commands')
                            ->setPriority(3)
                            ->setAction('dispatch_event', [
                                'name' => 'delete-object',
                                'close' => true,
                            ])
                    );
                }
            }

            // Context: Block Detail Page
            if ($routeName === 'blocks.show') {
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
                                'close' => true,
                            ])
                    );
                }

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
                                'close' => true,
                            ])
                    );
                }

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
                                'close' => true,
                            ])
                    );
                }
            }

            return $results;
        });
    }
}
