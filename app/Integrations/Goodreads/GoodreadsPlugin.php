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

    /**
     * API key integrations use polling, not staleness checking
     */
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
        return 'Track your reading activity from Goodreads RSS feed.';
    }

    public static function getGroupConfigurationSchema(): array
    {
        return [
            'rss_url' => [
                'type' => 'string',
                'label' => 'RSS Feed URL',
                'required' => true,
                'description' => 'Your Goodreads RSS feed URL (found in Profile → Updates → RSS)',
            ],
        ];
    }

    public static function getConfigurationSchema($instanceType = null): array
    {
        return [
            'update_frequency_minutes' => [
                'type' => 'integer',
                'label' => 'Update Frequency (minutes)',
                'required' => true,
                'min' => 15,
                'max' => 1440,
                'default' => 60,
                'description' => 'How often to check for new reading activity (15-1440 minutes)',
            ],
        ];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'rss_feed' => [
                'label' => 'RSS Feed',
                'schema' => self::getConfigurationSchema('rss_feed'),
                'description' => 'Syncs reading activity from your Goodreads RSS feed',
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
                'description' => 'Reading a book',
                'display_with_object' => true,
                'value_unit' => null,
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
                'value_unit' => null,
                'hidden' => false,
            ],
            'reviewed' => [
                'icon' => 'fas.star',
                'display_name' => 'Reviewed Book',
                'description' => 'Reviewed and rated a book',
                'display_with_object' => true,
                'value_unit' => 'stars',
                'value_formatter' => '{{ $value }}<span class="text-[0.875em]"> stars</span>',
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'book_cover' => [
                'icon' => 'fas.image',
                'display_name' => 'Book Cover',
                'description' => 'Book cover image',
                'display_with_object' => false,
                'value_unit' => null,
                'hidden' => false,
            ],
            'book_author' => [
                'icon' => 'fas.pen-nib',
                'display_name' => 'Author',
                'description' => 'Book author information',
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
            'goodreads_user' => [
                'icon' => 'fas.user',
                'display_name' => 'Goodreads User',
                'description' => 'The Goodreads user account',
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
