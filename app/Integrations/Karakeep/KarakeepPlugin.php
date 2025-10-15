<?php

namespace App\Integrations\Karakeep;

use App\Integrations\Base\ManualPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Throwable;

class KarakeepPlugin extends ManualPlugin
{
    public static function getServiceType(): string
    {
        return 'apikey';
    }

    public static function getIdentifier(): string
    {
        return 'karakeep';
    }

    public static function getDisplayName(): string
    {
        return 'Karakeep';
    }

    public static function getDescription(): string
    {
        return 'Sync bookmarks from your Karakeep instance with AI summaries, tags, and highlights.';
    }

    public static function getGroupConfigurationSchema(): array
    {
        return [
            'api_url' => [
                'type' => 'string',
                'label' => 'API URL',
                'required' => true,
                'default' => config('services.karakeep.url'),
                'description' => 'The base URL of your Karakeep instance (e.g., https://karakeep.example.com)',
            ],
            'access_token' => [
                'type' => 'string',
                'label' => 'Access Token',
                'required' => true,
                'default' => config('services.karakeep.access_token'),
                'description' => 'Your Karakeep API access token (JWT)',
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
                'default' => 30,
                'description' => 'How often to sync bookmarks (15-1440 minutes)',
            ],
            'fetch_limit' => [
                'type' => 'integer',
                'label' => 'Fetch Limit',
                'required' => false,
                'min' => 10,
                'max' => 100,
                'default' => 50,
                'description' => 'Number of bookmarks to fetch per sync (10-100)',
            ],
            'sync_highlights' => [
                'type' => 'boolean',
                'label' => 'Sync Highlights',
                'required' => false,
                'default' => true,
                'description' => 'Include bookmark highlights as blocks',
            ],
            'paused' => [
                'type' => 'boolean',
                'label' => 'Paused',
                'required' => false,
                'default' => false,
            ],
        ];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'bookmarks' => [
                'label' => 'Bookmarks',
                'schema' => self::getConfigurationSchema('bookmarks'),
                'description' => 'Sync all bookmarks from your Karakeep instance',
            ],
        ];
    }

    public static function getIcon(): string
    {
        return 'o-bookmark';
    }

    public static function getAccentColor(): string
    {
        return 'warning';
    }

    public static function getDomain(): string
    {
        return 'knowledge';
    }

    public static function supportsMigration(): bool
    {
        return true;
    }

    public static function getActionTypes(): array
    {
        return [
            'saved_bookmark' => [
                'icon' => 'o-bookmark',
                'display_name' => 'Saved Bookmark',
                'description' => 'A bookmark was saved',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'added_to_list' => [
                'icon' => 'o-plus-circle',
                'display_name' => 'Added to List',
                'description' => 'A bookmark was added to a list',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'bookmark_summary' => [
                'icon' => 'o-sparkles',
                'display_name' => 'AI Summary',
                'description' => 'AI-generated summary of the bookmark',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'bookmark_metadata' => [
                'icon' => 'o-photo',
                'display_name' => 'Preview Card',
                'description' => 'Rich preview metadata from the bookmark',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'bookmark_highlight' => [
                'icon' => 'o-pencil',
                'display_name' => 'Highlight',
                'description' => 'Text highlight from the bookmark',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'karakeep_bookmark' => [
                'icon' => 'o-bookmark',
                'display_name' => 'Karakeep Bookmark',
                'description' => 'A bookmark from Karakeep',
                'hidden' => false,
            ],
            'karakeep_list' => [
                'icon' => 'o-rectangle-stack',
                'display_name' => 'Karakeep List',
                'description' => 'A list/collection in Karakeep',
                'hidden' => false,
            ],
            'karakeep_user' => [
                'icon' => 'o-user-circle',
                'display_name' => 'Karakeep User',
                'description' => 'A Karakeep user',
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
            'auth_metadata' => [
                'api_url' => config('services.karakeep.url'),
            ],
        ]);
    }

    public function createInstance(IntegrationGroup $group, string $instanceType, array $initialConfig = [], bool $withMigration = false): Integration
    {
        // Extract group-level configuration from initialConfig
        $groupConfig = [];
        $instanceConfig = $initialConfig;

        if (isset($initialConfig['api_url'])) {
            $groupConfig['api_url'] = $initialConfig['api_url'];
            unset($instanceConfig['api_url']);
        }

        if (isset($initialConfig['access_token'])) {
            $group->update(['access_token' => $initialConfig['access_token']]);
            unset($instanceConfig['access_token']);
        }

        // Extract migration-related flags from initialConfig (prefer parameter)
        $withMigration = $withMigration || ($initialConfig['with_migration'] ?? false);
        if (isset($instanceConfig['with_migration'])) {
            unset($instanceConfig['with_migration']);
        }

        // Update group auth_metadata if we have group-level config
        if (! empty($groupConfig)) {
            $currentMetadata = $group->auth_metadata ?? [];
            $group->update(['auth_metadata' => array_merge($currentMetadata, $groupConfig)]);
        }

        // If this plugin supports migration and we're creating for migration, start paused
        if (static::supportsMigration() && $withMigration) {
            $instanceConfig['paused'] = true;
        }

        // Derive a sensible default name from plugin instance types if available
        $defaultName = static::getDisplayName();
        if (method_exists(static::class, 'getInstanceTypes')) {
            try {
                $types = static::getInstanceTypes();
                $defaultName = $types[$instanceType]['label'] ?? ucfirst($instanceType);
            } catch (Throwable $e) {
                $defaultName = ucfirst($instanceType);
            }
        } else {
            $defaultName = ucfirst($instanceType);
        }

        return Integration::create([
            'user_id' => $group->user_id,
            'integration_group_id' => $group->id,
            'service' => static::getIdentifier(),
            'name' => $defaultName,
            'instance_type' => $instanceType,
            'configuration' => $instanceConfig,
        ]);
    }

    /**
     * Log API request details for debugging
     */
    public function logApiRequest(string $method, string $endpoint, array $headers = [], array $data = [], ?string $integrationId = null): void
    {
        log_integration_api_request(
            static::getIdentifier(),
            $method,
            $endpoint,
            $this->sanitizeHeaders($headers),
            $this->sanitizeData($data),
            $integrationId ?: '',
            true // Use per-instance logging
        );
    }

    /**
     * Log API response details for debugging
     */
    public function logApiResponse(string $method, string $endpoint, int $statusCode, string $body, array $headers = [], ?string $integrationId = null): void
    {
        log_integration_api_response(
            static::getIdentifier(),
            $method,
            $endpoint,
            $statusCode,
            $this->sanitizeResponseBody($body),
            $this->sanitizeHeaders($headers),
            $integrationId ?: '',
            true // Use per-instance logging
        );
    }

    /**
     * Sanitize headers for logging (remove sensitive data)
     */
    protected function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'x-api-key', 'x-auth-token'];
        $sanitized = [];

        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitiveHeaders)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize data for logging (remove sensitive data)
     */
    protected function sanitizeData(array $data): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth', 'access_token'];
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            $isSensitive = false;
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (str_contains($lowerKey, $sensitiveKey)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize response body for logging (limit size and remove sensitive data)
     */
    protected function sanitizeResponseBody(string $body): string
    {
        // Limit response body size to prevent huge logs
        $maxLength = 10000;
        if (strlen($body) > $maxLength) {
            return substr($body, 0, $maxLength) . '... [TRUNCATED]';
        }

        // Try to parse as JSON and sanitize sensitive fields
        $parsed = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            $sanitized = $this->sanitizeData($parsed);

            return json_encode($sanitized, JSON_PRETTY_PRINT);
        }

        return $body;
    }
}
