<?php

namespace App\Integrations\Untappd;

use App\Integrations\Base\ManualPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;

class UntappdPlugin extends ManualPlugin
{
    public static function getServiceType(): string
    {
        return 'apikey';
    }

    /**
     * API key integrations use polling, not staleness checking
     */
    public static function getTimeUntilStaleMinutes(): ?int
    {
        return null;
    }

    public static function getIdentifier(): string
    {
        return 'untappd';
    }

    public static function getDisplayName(): string
    {
        return 'Untappd';
    }

    public static function getDescription(): string
    {
        return 'Track your beer check-ins from Untappd RSS feed.';
    }

    public static function getGroupConfigurationSchema(): array
    {
        return [
            'rss_url' => [
                'type' => 'string',
                'label' => 'RSS Feed URL',
                'required' => true,
                'description' => 'Your Untappd RSS feed URL (found in your profile settings)',
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
                    'min' => 15,
                    'max' => 1440,
                    'default' => 30,
                    'description' => 'How often to check for new check-ins (15-1440 minutes)',
                ],
            ]
        );
    }

    public static function getInstanceTypes(): array
    {
        return [
            'rss_feed' => [
                'label' => 'RSS Feed',
                'schema' => self::getConfigurationSchema('rss_feed'),
                'description' => 'Syncs beer check-ins from your Untappd RSS feed',
            ],
        ];
    }

    public static function getIcon(): string
    {
        return 'fas.beer-mug-empty';
    }

    public static function getAccentColor(): string
    {
        return 'warning';
    }

    public static function getDomain(): string
    {
        return 'health';
    }

    public static function getActionTypes(): array
    {
        return [
            'drank_beer' => [
                'icon' => 'fas.beer-mug-empty',
                'display_name' => 'Drank Beer',
                'description' => 'Drank a beer',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'beer_comment' => [
                'icon' => 'fas.comment',
                'display_name' => 'Beer Comment',
                'description' => 'User comment about the beer',
                'display_with_object' => false,
                'value_unit' => null,
                'hidden' => false,
            ],
            'beer_brewery' => [
                'icon' => 'fas.industry',
                'display_name' => 'Brewery',
                'description' => 'Brewery information',
                'display_with_object' => false,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'untappd_beer' => [
                'icon' => 'fas.beer-mug-empty',
                'display_name' => 'Beer',
                'description' => 'A beer on Untappd',
                'hidden' => false,
            ],
            'untappd_user' => [
                'icon' => 'fas.user',
                'display_name' => 'Untappd User',
                'description' => 'The Untappd user account',
                'hidden' => true,
            ],
        ];
    }

    public function initializeGroup(User $user): IntegrationGroup
    {
        return IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => static::getIdentifier(),
            'account_id' => null,
            'access_token' => null,
            'refresh_token' => null,
            'expiry' => null,
            'refresh_expiry' => null,
            'auth_metadata' => [],
        ]);
    }

    public function createInstance(IntegrationGroup $group, string $instanceType, array $initialConfig = [], bool $withMigration = false): Integration
    {
        // Extract group-level configuration from initialConfig
        $groupConfig = [];
        $instanceConfig = $initialConfig;

        if (isset($initialConfig['rss_url'])) {
            $groupConfig['rss_url'] = $initialConfig['rss_url'];
            unset($instanceConfig['rss_url']);
        }

        // Update group auth_metadata if we have group-level config
        if (! empty($groupConfig)) {
            $currentMetadata = $group->auth_metadata ?? [];
            $group->update(['auth_metadata' => array_merge($currentMetadata, $groupConfig)]);
        }

        // Derive default name from instance type
        $defaultName = static::getInstanceTypes()[$instanceType]['label'] ?? ucfirst($instanceType);

        return Integration::create([
            'user_id' => $group->user_id,
            'integration_group_id' => $group->id,
            'service' => static::getIdentifier(),
            'name' => $defaultName,
            'instance_type' => $instanceType,
            'configuration' => $instanceConfig,
        ]);
    }
}
