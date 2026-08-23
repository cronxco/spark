<?php

namespace App\Integrations\Vivino;

use App\Integrations\Base\ManualPlugin;

class VivinoPlugin extends ManualPlugin
{
    /**
     * Not a manual-entry service: Vivino is polled via a scheduled Pull job
     * (VivinoActivityPull), same as Goodreads. CheckIntegrationUpdates only
     * schedules services classified as 'oauth' or 'apikey'
     * (PluginRegistry::getOAuthPlugins()/getApiKeyPlugins()), so this must
     * override the ManualPlugin default of 'manual' or it would never be
     * polled - see PluginRegistry::getApiKeyPlugins().
     */
    public static function getServiceType(): string
    {
        return 'apikey';
    }

    public static function getTimeUntilStaleMinutes(): ?int
    {
        return null;
    }

    public static function getIdentifier(): string
    {
        return 'vivino';
    }

    public static function getDisplayName(): string
    {
        return 'Vivino';
    }

    public static function getDescription(): string
    {
        return 'Track wine you rate on Vivino, via your own logged-in profile activity.';
    }

    /**
     * Auth is a Playwright-replayed browser session, not an API key -
     * cookies for vivino.com are added via the existing "Manage Fetch
     * Cookies" mechanism (FetchPlugin's Spotlight command). This schema
     * only needs the profile URL to poll.
     */
    public static function getGroupConfigurationSchema(): array
    {
        return [
            'vivino_profile_url' => [
                'type' => 'string',
                'label' => 'Vivino Profile URL',
                'required' => true,
                'description' => 'Your Vivino profile activity page, e.g. https://www.vivino.com/users/yourname',
            ],
        ];
    }

    public static function getConfigurationSchema($instanceType = null): array
    {
        return array_merge(
            static::getGroupConfigurationSchema(),
            [
                'update_frequency_minutes' => [
                    'type' => 'integer',
                    'label' => 'Update Frequency (minutes)',
                    'required' => true,
                    'min' => 60,
                    'max' => 1440,
                    'default' => 180,
                    'description' => 'How often to check for new ratings (60-1440 minutes)',
                ],
            ]
        );
    }

    public static function getInstanceTypes(): array
    {
        return [
            'activity' => [
                'label' => 'Wine Activity',
                'schema' => self::getConfigurationSchema('activity'),
                'description' => 'Track wines you rate on Vivino',
            ],
        ];
    }

    public static function getIcon(): string
    {
        return 'fas.wine-glass';
    }

    public static function getAccentColor(): string
    {
        return 'error';
    }

    public static function getDomain(): string
    {
        return 'health';
    }

    public static function getActionTypes(): array
    {
        return [
            'drank_wine' => [
                'icon' => 'fas.wine-glass',
                'display_name' => 'Rated Wine',
                'description' => 'Rated a wine on Vivino',
                'display_with_object' => true,
                'value_unit' => '/5',
                'value_formatter' => '@if($value){{ $value }}<span class="text-[0.875em]">/5</span>@endif',
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'wine_details' => [
                'icon' => 'fas.wine-glass',
                'display_name' => 'Wine Details',
                'description' => 'Winery, region, and vintage information',
                'display_with_object' => false,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'vivino_wine' => [
                'icon' => 'fas.wine-glass',
                'display_name' => 'Wine',
                'description' => 'A wine rated on Vivino',
                'hidden' => false,
            ],
            'vivino_user' => [
                'icon' => 'fas.user',
                'display_name' => 'Vivino User',
                'description' => 'The Vivino user account',
                'hidden' => true,
            ],
        ];
    }
}
