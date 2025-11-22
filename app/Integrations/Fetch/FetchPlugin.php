<?php

namespace App\Integrations\Fetch;

use App\Integrations\Base\ManualPlugin;
use App\Integrations\Contracts\SupportsSpotlightCommands;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Throwable;

class FetchPlugin extends ManualPlugin implements SupportsSpotlightCommands
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
        return 'fetch';
    }

    public static function getDisplayName(): string
    {
        return 'Fetch';
    }

    public static function getDescription(): string
    {
        return 'Fetch and archive web content from subscribed URLs with AI-powered summaries.';
    }

    public static function getGroupConfigurationSchema(): array
    {
        return [
            // Cookie management is handled in auth_metadata per-domain
            // No group-level configuration fields needed in UI
        ];
    }

    public static function getConfigurationSchema($instanceType = null): array
    {
        return [
            'update_frequency_minutes' => [
                'type' => 'integer',
                'label' => 'Update Frequency (minutes)',
                'required' => false,
                'min' => 60,
                'max' => 1440,
                'default' => 180,
                'description' => 'How often to fetch URLs when not using schedule (60-1440 minutes)',
            ],
            'use_schedule' => [
                'type' => 'boolean',
                'label' => 'Use Schedule',
                'required' => false,
                'default' => true,
                'description' => 'Use specific fetch times instead of frequency',
            ],
            'schedule_times' => [
                'type' => 'array',
                'label' => 'Fetch Times (HH:mm)',
                'required' => false,
                'default' => ['00:00', '03:00', '06:00', '09:00', '12:00', '15:00', '18:00', '21:00'],
                'description' => 'Times to fetch URLs (24-hour format)',
            ],
            'schedule_timezone' => [
                'type' => 'string',
                'label' => 'Timezone',
                'required' => false,
                'default' => 'UTC',
                'description' => 'Timezone for scheduled fetches',
            ],
            'monitor_integrations' => [
                'type' => 'array',
                'label' => 'Monitor Integrations',
                'required' => false,
                'default' => [],
                'description' => 'Integration IDs to monitor for URLs',
            ],
        ];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'fetcher' => [
                'label' => 'Fetch',
                'schema' => self::getConfigurationSchema('fetcher'),
                'description' => 'Fetch and archive web content from URLs',
            ],
        ];
    }

    public static function getIcon(): string
    {
        return 'fas-globe';
    }

    public static function getAccentColor(): string
    {
        return 'info';
    }

    public static function getDomain(): string
    {
        return 'knowledge';
    }

    public static function supportsMigration(): bool
    {
        return false;
    }

    public static function getActionTypes(): array
    {
        return [
            'fetched' => [
                'icon' => 'fas-download',
                'display_name' => 'Fetched',
                'description' => 'URL content was fetched',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => true,
            ],
            'bookmarked' => [
                'icon' => 'fas-bookmark',
                'display_name' => 'Fetched',
                'description' => 'URL was bookmarked',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'updated' => [
                'icon' => 'fas-rotate',
                'display_name' => 'Updated',
                'description' => 'URL content was updated',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => true,
            ],
            // NOTE: had_link_to has been migrated to the Relationship model.
            // See app/Models/Relationship.php and relationship type 'linked_to'
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'fetch_summary_tweet' => [
                'icon' => 'fas-comment',
                'display_name' => 'Tweet Summary',
                'description' => 'Ultra-concise 280 character summary',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'fetch_summary_short' => [
                'icon' => 'fas-file-lines',
                'display_name' => 'Short Summary',
                'description' => '40 word summary',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'fetch_summary_paragraph' => [
                'icon' => 'fas-file',
                'display_name' => 'Paragraph Summary',
                'description' => '150 word detailed summary',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'fetch_key_takeaways' => [
                'icon' => 'fas-list',
                'display_name' => 'Key Takeaways',
                'description' => '3-5 actionable bullet points',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'fetch_tldr' => [
                'icon' => 'fas-bolt',
                'display_name' => 'TL;DR',
                'description' => 'One sentence summary',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'fetch_webpage' => [
                'icon' => 'fas-globe',
                'display_name' => 'Webpage',
                'description' => 'A fetched webpage',
                'hidden' => false,
            ],
            'fetch_user' => [
                'icon' => 'fas-user-circle',
                'display_name' => 'Fetch User',
                'description' => 'Fetch system user',
                'hidden' => true,
            ],
        ];
    }

    /**
     * Get Spotlight commands for Fetch integration
     */
    public static function getSpotlightCommands(): array
    {
        return [
            'fetch-add-url' => [
                'title' => 'Add URL to Fetch',
                'subtitle' => 'Subscribe to a new URL to monitor and archive',
                'icon' => 'plus-circle',
                'action' => 'jump_to',
                'actionParams' => [
                    'path' => '/bookmarks/fetch?tab=urls',
                ],
                'priority' => 7,
            ],
            'fetch-manage-cookies' => [
                'title' => 'Manage Fetch Cookies',
                'subtitle' => 'Add or update authentication cookies for paywalled sites',
                'icon' => 'key',
                'action' => 'jump_to',
                'actionParams' => [
                    'path' => '/bookmarks/fetch?tab=cookies',
                ],
                'priority' => 5,
            ],
            'fetch-discovery-settings' => [
                'title' => 'Configure URL Discovery',
                'subtitle' => 'Set up automatic URL discovery from other integrations',
                'icon' => 'magnifying-glass',
                'action' => 'jump_to',
                'actionParams' => [
                    'path' => '/bookmarks/fetch?tab=discovery',
                ],
                'priority' => 4,
            ],
            'fetch-view-stats' => [
                'title' => 'View Fetch Statistics',
                'subtitle' => 'See your archived content and fetch history',
                'icon' => 'chart-bar',
                'action' => 'jump_to',
                'actionParams' => [
                    'path' => '/bookmarks/fetch?tab=stats',
                ],
                'priority' => 3,
            ],
            'fetch-view-bookmarks' => [
                'title' => 'View All Bookmarks',
                'subtitle' => 'Browse your fetched content and summaries',
                'icon' => 'bookmark',
                'action' => 'jump_to',
                'actionParams' => [
                    'path' => '/bookmarks',
                ],
                'priority' => 6,
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
                'domains' => [],
            ],
        ]);
    }

    public function createInstance(IntegrationGroup $group, string $instanceType, array $initialConfig = [], bool $withMigration = false): Integration
    {
        // No group-level config extraction needed for Fetch
        $instanceConfig = $initialConfig;

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
     * Sanitize headers for logging (remove sensitive data including cookies)
     */
    protected function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'x-api-key', 'x-auth-token', 'cookie', 'set-cookie'];
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
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth', 'access_token', 'cookie'];
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
