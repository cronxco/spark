<?php

namespace App\Integrations\Goodreads;

use App\Integrations\Base\ManualPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;

class GoodreadsPlugin extends ManualPlugin
{
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
        return 'goodreads';
    }

    public static function getDisplayName(): string
    {
        return 'Goodreads';
    }

    public static function getDescription(): string
    {
        return 'Track your reading activity from Goodreads RSS feeds.';
    }

    public static function getGroupConfigurationSchema(): array
    {
        return [
            'user_id' => [
                'type' => 'string',
                'label' => 'Goodreads User ID',
                'required' => true,
                'description' => 'Your Goodreads user ID (found in your profile URL)',
            ],
            'api_key' => [
                'type' => 'string',
                'label' => 'RSS API Key',
                'required' => true,
                'description' => 'Your Goodreads RSS feed API key',
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
                    'default' => 60,
                    'description' => 'How often to check for new reading activity (15-1440 minutes)',
                ],
            ]
        );
    }

    public static function getInstanceTypes(): array
    {
        return [
            'shelf_currently_reading' => [
                'label' => 'Currently Reading Shelf',
                'schema' => self::getConfigurationSchema('shelf_currently_reading'),
                'description' => 'Track books you are currently reading',
            ],
            'shelf_read' => [
                'label' => 'Read Shelf',
                'schema' => self::getConfigurationSchema('shelf_read'),
                'description' => 'Track books you have finished reading',
            ],
            'shelf_to_read' => [
                'label' => 'To-Read Shelf',
                'schema' => self::getConfigurationSchema('shelf_to_read'),
                'description' => 'Track books you want to read',
            ],
            'updates_progress' => [
                'label' => 'Reading Progress Updates',
                'schema' => self::getConfigurationSchema('updates_progress'),
                'description' => 'Track your reading progress percentage updates',
            ],
        ];
    }

    public static function getIcon(): string
    {
        return 'fas.book';
    }

    public static function getAccentColor(): string
    {
        return 'warning';
    }

    public static function getDomain(): string
    {
        return 'media';
    }

    public static function getActionTypes(): array
    {
        return [
            'is_reading' => [
                'icon' => 'fas.book-open',
                'display_name' => 'Reading',
                'description' => 'Currently reading a book',
                'display_with_object' => true,
                'value_unit' => '%',
                'value_formatter' => '{{ $value }}%',
                'hidden' => false,
            ],
            'wants_to_read' => [
                'icon' => 'fas.bookmark',
                'display_name' => 'Wants to Read',
                'description' => 'Added book to want-to-read list',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'finished_reading' => [
                'icon' => 'fas.book-bookmark',
                'display_name' => 'Finished Reading',
                'description' => 'Completed reading a book',
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
            'book' => [
                'icon' => 'fas.book',
                'display_name' => 'Book',
                'description' => 'Book information with cover, year, pages, and ISBN',
                'display_with_object' => false,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'goodreads_book' => [
                'icon' => 'fas.book',
                'display_name' => 'Book',
                'description' => 'A book on Goodreads',
                'hidden' => false,
            ],
            'goodreads_series' => [
                'icon' => 'fas.layer-group',
                'display_name' => 'Book Series',
                'description' => 'A series of books',
                'hidden' => false,
            ],
            'goodreads_user' => [
                'icon' => 'fas.user',
                'display_name' => 'Goodreads User',
                'description' => 'The Goodreads user account',
                'hidden' => true,
            ],
        ];
    }

    /**
     * Build RSS URL for shelf feeds
     */
    public static function buildShelfUrl(string $userId, string $apiKey, string $shelf): string
    {
        return "https://www.goodreads.com/review/list_rss/{$userId}?key={$apiKey}&shelf={$shelf}";
    }

    /**
     * Build RSS URL for user updates feed
     */
    public static function buildUpdatesUrl(string $userId, string $apiKey): string
    {
        return "https://www.goodreads.com/user/updates_rss/{$userId}?key={$apiKey}";
    }

    /**
     * Get shelf name from instance type
     */
    public static function getShelfFromInstanceType(string $instanceType): ?string
    {
        return match ($instanceType) {
            'shelf_currently_reading' => 'currently-reading',
            'shelf_read' => 'read',
            'shelf_to_read' => 'to-read',
            default => null,
        };
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

        if (isset($initialConfig['user_id'])) {
            $groupConfig['user_id'] = $initialConfig['user_id'];
            unset($instanceConfig['user_id']);
        }

        if (isset($initialConfig['api_key'])) {
            $groupConfig['api_key'] = $initialConfig['api_key'];
            unset($instanceConfig['api_key']);
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
