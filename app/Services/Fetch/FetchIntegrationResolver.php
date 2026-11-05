<?php

namespace App\Services\Fetch;

use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;

/**
 * Resolves (creating if necessary) the Fetch integration for a user.
 *
 * Mirrors the auto-create behaviour previously only present in the
 * bookmarks Livewire component so the UI, the API and tests all share
 * a single source of truth.
 */
class FetchIntegrationResolver
{
    /**
     * Return an active Fetch integration for the user, creating the
     * integration group and fetcher instance if they don't exist yet.
     */
    public function resolve(User $user): Integration
    {
        $group = IntegrationGroup::firstOrCreate(
            [
                'user_id' => $user->id,
                'service' => 'fetch',
            ],
            [
                'auth_metadata' => [
                    'domains' => [],
                ],
            ]
        );

        $integration = Integration::firstOrCreate(
            [
                'user_id' => $user->id,
                'service' => 'fetch',
                'instance_type' => 'fetcher',
            ],
            [
                'name' => 'Fetch',
                'integration_group_id' => $group->id,
                'configuration' => [
                    'update_frequency_minutes' => 180,
                    'use_schedule' => true,
                    'schedule_times' => ['00:00', '03:00', '06:00', '09:00', '12:00', '15:00', '18:00', '21:00'],
                    'schedule_timezone' => 'UTC',
                    'monitor_integrations' => [],
                ],
            ]
        );

        return $integration;
    }
}
