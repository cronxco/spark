<?php

namespace App\Spotlight\Queries\Scoped;

use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class AccountActionsQuery
{
    /**
     * Create Spotlight query for context-aware actions on financial account pages.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('account', function (string $query) {
            $results = collect();

            // Add Balance Update
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
                            'data' => [],
                            'close' => true,
                        ])
                );
            }

            // Archive Account
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
                            'data' => [],
                            'close' => true,
                        ])
                );
            }

            // Edit Account
            if (blank($query) || str_contains(strtolower($query), 'edit')) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Edit Account')
                        ->setSubtitle('Edit account name and settings')
                        ->setIcon('pencil')
                        ->setGroup('commands')
                        ->setPriority(3)
                        ->setAction('dispatch_event', [
                            'name' => 'open-edit-modal',
                            'data' => [],
                            'close' => true,
                        ])
                );
            }

            return $results;
        });
    }
}
