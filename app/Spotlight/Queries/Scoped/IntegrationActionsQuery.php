<?php

namespace App\Spotlight\Queries\Scoped;

use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class IntegrationActionsQuery
{
    /**
     * Create Spotlight query for context-aware actions on integration detail pages.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('integration', function (string $query) {
            $results = collect();

            // Trigger Update Now
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
                            'data' => [],
                            'close' => true,
                        ])
                );
            }

            // Pause/Resume Integration
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
                            'data' => [],
                            'close' => true,
                        ])
                );
            }

            // Configure Integration
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
                            'data' => [],
                            'close' => true,
                        ])
                );
            }

            return $results;
        });
    }
}
