<?php

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\LoggingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

if (! function_exists('user_now')) {
    /**
     * Get the current time in the user's timezone.
     * If no user is provided, falls back to UTC.
     */
    function user_now(?User $user = null): Carbon
    {
        $timezone = $user?->getTimezone() ?? 'UTC';

        return Carbon::now($timezone);
    }
}

if (! function_exists('user_today')) {
    /**
     * Get today's date in the user's timezone.
     * If no user is provided, falls back to UTC.
     */
    function user_today(?User $user = null): Carbon
    {
        $timezone = $user?->getTimezone() ?? 'UTC';

        return Carbon::today($timezone);
    }
}

if (! function_exists('to_user_timezone')) {
    /**
     * Convert a datetime to the user's timezone.
     * If no user is provided, returns the datetime as-is.
     */
    function to_user_timezone(Carbon $datetime, ?User $user = null): Carbon
    {
        if (! $user) {
            return $datetime;
        }

        $timezone = $user->getTimezone();

        return $datetime->copy()->setTimezone($timezone);
    }
}

if (! function_exists('format_time_for_user')) {
    /**
     * Format a datetime for display in the user's timezone.
     * If no user is provided, uses UTC.
     */
    function format_time_for_user(Carbon $datetime, ?User $user = null, string $format = 'Y-m-d H:i:s'): string
    {
        $userDatetime = to_user_timezone($datetime, $user);

        return $userDatetime->format($format);
    }
}

if (! function_exists('format_action_title')) {
    /**
     * Format an event action into a human-friendly title while lowercasing
     * minor words (prepositions/conjunctions) except when they are the
     * first or last word. Example: "listened to" -> "Listened to".
     */
    function format_action_title(string $title): string
    {
        $headline = Str::headline($title);

        $minorWords = [
            'and',
            'as',
            'but',
            'for',
            'if',
            'nor',
            'or',
            'so',
            'yet',
            'a',
            'an',
            'the',
            'about',
            'above',
            'across',
            'after',
            'against',
            'along',
            'among',
            'around',
            'at',
            'before',
            'behind',
            'below',
            'beneath',
            'beside',
            'besides',
            'between',
            'beyond',
            'by',
            'concerning',
            'considering',
            'despite',
            'down',
            'during',
            'except',
            'following',
            'for',
            'from',
            'in',
            'inside',
            'into',
            'like',
            'near',
            'of',
            'off',
            'on',
            'onto',
            'opposite',
            'outside',
            'over',
            'past',
            'per',
            'plus',
            'regarding',
            'round',
            'since',
            'than',
            'through',
            'to',
            'toward',
            'under',
            'underneath',
            'unlike',
            'until',
            'up',
            'upon',
            'via',
            'with',
            'within',
            'without',
        ];

        $words = preg_split('/\s+/', trim($headline)) ?: [];
        foreach ($words as $i => $word) {
            $lower = strtolower($word);
            // Keep the very first word capitalized; lowercase minor words elsewhere
            if ($i !== 0 && in_array($lower, $minorWords, true)) {
                $words[$i] = $lower;

                continue;
            }
            // Otherwise keep the word as produced by Str::headline
            $words[$i] = $word;
        }

        return implode(' ', $words);
    }
}

if (! function_exists('should_display_action_with_object')) {
    /**
     * Check if an action should display with its object title based on plugin configuration.
     *
     * @param  string  $action  The action key (e.g., 'had_balance', 'card_payment_to')
     * @param  string  $service  The service identifier (e.g., 'monzo', 'gocardless')
     * @return bool True if the action should display with object title, false otherwise
     */
    function should_display_action_with_object(string $action, string $service): bool
    {
        $pluginClass = PluginRegistry::getPlugin($service);

        if (! $pluginClass) {
            // If plugin not found, default to showing with object (backward compatibility)
            return true;
        }

        $actionTypes = $pluginClass::getActionTypes();

        if (! isset($actionTypes[$action])) {
            // If action not found in plugin config, default to showing with object
            return true;
        }

        return $actionTypes[$action]['display_with_object'] ?? true;
    }
}

if (! function_exists('format_duration')) {
    /**
     * Format a duration in seconds into the most appropriate 2-part representation.
     * Examples: 123min → "2h3m", 90s → "1m30s", 4000min → "2d21h", 45s → "45s"
     *
     * @param  float|int  $seconds  Duration in seconds
     * @return string Formatted duration string
     */
    function format_duration(float|int $seconds): string
    {
        if ($seconds < 0) {
            return '0s';
        }

        $seconds = (int) round($seconds);

        // Less than 1 minute: show only seconds
        if ($seconds < 60) {
            return $seconds . 's';
        }

        // Less than 1 hour: show minutes and seconds
        if ($seconds < 3600) {
            $m = intdiv($seconds, 60);
            $s = $seconds % 60;

            return $s > 0 ? "{$m}m{$s}s" : "{$m}m";
        }

        // Less than 1 day: show hours and minutes
        if ($seconds < 86400) {
            $h = intdiv($seconds, 3600);
            $m = intdiv($seconds % 3600, 60);

            return $m > 0 ? "{$h}h{$m}m" : "{$h}h";
        }

        // 1 day or more: show days and hours
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);

        return $h > 0 ? "{$d}d{$h}h" : "{$d}d";
    }
}

if (! function_exists('format_event_value_display')) {
    /**
     * Format an event's or block's value for display using plugin-defined formatters.
     *
     * @param  mixed  $value  The formatted_value (after division by value_multiplier)
     * @param  string|null  $unit  The value_unit
     * @param  string|null  $service  The service identifier (e.g., 'oura', 'monzo')
     * @param  string|null  $action  The action type (for events) or block_type (for blocks)
     * @param  string|null  $typeKey  Either 'action' or 'block' to know which config to check
     * @return string The formatted value for display
     */
    function format_event_value_display(
        mixed $value,
        ?string $unit,
        ?string $service = null,
        ?string $action = null,
        ?string $typeKey = 'action'
    ): string {
        if ($value === null) {
            return '';
        }

        // Try to get custom formatter from plugin definition
        if ($service && $action) {
            $pluginClass = PluginRegistry::getPlugin($service);
            if ($pluginClass) {
                // Get the appropriate type configuration
                $types = $typeKey === 'block'
                    ? $pluginClass::getBlockTypes()
                    : $pluginClass::getActionTypes();

                if (isset($types[$action]['value_formatter'])) {
                    $formatter = $types[$action]['value_formatter'];

                    // Render the Blade template with available context
                    try {
                        $rendered = Blade::render(
                            $formatter,
                            [
                                'value' => $value,
                                'unit' => $unit ?? '',
                            ]
                        );

                        return trim($rendered);
                    } catch (\Throwable $e) {
                        // Log error and fall through to default formatting
                        Log::warning('Failed to render value_formatter', [
                            'service' => $service,
                            'action' => $action,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }
            }
        }

        // Default fallback: simple concatenation
        return (string) $value . ($unit ? (' ' . $unit) : '');
    }
}

/**
 * Sanitize headers for logging (remove sensitive data)
 */
if (! function_exists('sanitizeHeaders')) {
    function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'x-api-key', 'x-auth-token', 'x-signature', 'x-hub-signature'];
        $sanitized = [];

        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitiveHeaders)) {
                $sanitized[$key] = ['[REDACTED]'];
            } elseif (is_array($value)) {
                $sanitized[$key] = $value; // Headers are already arrays from Laravel
            } else {
                $sanitized[$key] = [$value];
            }
        }

        return $sanitized;
    }
}

/**
 * Sanitize data for logging (remove sensitive data)
 */
if (! function_exists('sanitizeData')) {
    function sanitizeData(array $data): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth', 'signature', 'api_key', 'access_token', 'refresh_token', 'authorization', 'webhook_secret'];
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitiveKeys)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = sanitizeData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}

/**
 * Generate API log filename with new format: api_{service}_{first_block_of_uuid}.log
 */
if (! function_exists('generate_api_log_filename')) {
    function generate_api_log_filename(string $service, string $integrationId = '', bool $perInstance = false): string
    {
        $serviceSlug = str_replace([' ', '-', '_'], '_', $service);

        if ($perInstance && ! empty($integrationId)) {
            // Extract first block of UUID (first 8 characters before first hyphen)
            $uuidBlock = explode('-', $integrationId)[0] ?? $integrationId;

            return "api_{$serviceSlug}_{$uuidBlock}.log";
        }

        return "api_{$serviceSlug}.log";
    }
}

if (! function_exists('get_integration_log_channel')) {
    /**
     * Get or create a dynamic log channel for a specific integration instance
     *
     * @param  string  $service  The service identifier (e.g., 'monzo', 'slack')
     * @param  string  $integrationId  The integration ID
     * @param  bool  $perInstance  Whether to create per-instance channels (true) or per-service (false)
     * @return string The channel name to use
     */
    function get_integration_log_channel(string $service, string $integrationId = '', bool $perInstance = false): string
    {
        $baseConfig = [
            'driver' => 'daily',
            'level' => 'debug',
            'days' => 2,
            'replace_placeholders' => true,
        ];

        $filename = generate_api_log_filename($service, $integrationId, $perInstance);
        $channelName = pathinfo($filename, PATHINFO_FILENAME); // Remove .log extension for channel name

        $baseConfig['path'] = storage_path('logs/' . $filename);

        // Create the channel dynamically if it doesn't exist
        if (! config('logging.channels.' . $channelName)) {
            Log::build($baseConfig);
        }

        return $channelName;
    }
}

if (! function_exists('log_integration_api_request')) {
    /**
     * Log an API request for a specific integration
     * Logs to integration instance channel as debug (if enabled)
     */
    function log_integration_api_request(
        string $service,
        string $method,
        string $endpoint,
        array $headers = [],
        array $data = [],
        string $integrationId = '',
        bool $perInstance = false
    ): void {
        // Try to load the integration (only if it looks like a valid UUID)
        if (! empty($integrationId) && preg_match('/^[a-f0-9\-]{36}$/i', $integrationId)) {
            $integration = Integration::find($integrationId);
            if ($integration) {
                log_to_integration($integration, 'debug', 'API Request', [
                    'service' => $service,
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'headers' => array_map(function ($header) {
                        return is_array($header) ? $header : [$header];
                    }, sanitizeHeaders($headers)),
                    'data' => sanitizeData($data),
                    'timestamp' => now()->toISOString(),
                ]);

                return;
            }
        }

        // Fallback to old behavior if integration not found
        $baseConfig = [
            'driver' => 'daily',
            'level' => 'debug',
            'days' => 2,
            'replace_placeholders' => true,
        ];

        $filename = generate_api_log_filename($service, $integrationId, $perInstance);
        $channelName = pathinfo($filename, PATHINFO_FILENAME);
        $baseConfig['path'] = storage_path('logs/' . $filename);

        $logger = Log::build($baseConfig);

        // If the logger is null (can happen in tests with spies), use the default Log facade
        if (is_null($logger)) {
            Log::debug('API Request', [
                'service' => $service,
                'integration_id' => $integrationId ?: null,
                'method' => $method,
                'endpoint' => $endpoint,
                'headers' => array_map(function ($header) {
                    return is_array($header) ? $header : [$header];
                }, sanitizeHeaders($headers)),
                'data' => sanitizeData($data),
                'timestamp' => now()->toISOString(),
            ]);

            return;
        }

        $logger->debug('API Request', [
            'service' => $service,
            'integration_id' => $integrationId ?: null,
            'method' => $method,
            'endpoint' => $endpoint,
            'headers' => array_map(function ($header) {
                return is_array($header) ? $header : [$header];
            }, sanitizeHeaders($headers)),
            'data' => sanitizeData($data),
            'timestamp' => now()->toISOString(),
        ]);
    }
}

if (! function_exists('log_integration_api_response')) {
    /**
     * Log an API response for a specific integration
     * Logs to integration instance channel as debug (if enabled)
     */
    function log_integration_api_response(
        string $service,
        string $method,
        string $endpoint,
        int $statusCode,
        string $body,
        array $headers = [],
        string $integrationId = '',
        bool $perInstance = false
    ): void {
        // Try to load the integration (only if it looks like a valid UUID)
        if (! empty($integrationId) && preg_match('/^[a-f0-9\-]{36}$/i', $integrationId)) {
            $integration = Integration::find($integrationId);
            if ($integration) {
                log_to_integration($integration, 'debug', 'API Response', [
                    'service' => $service,
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'status_code' => $statusCode,
                    'headers' => array_map(function ($header) {
                        return is_array($header) ? $header : [$header];
                    }, sanitizeHeaders($headers)),
                    'response_body' => strlen($body) > 10000
                        ? substr($body, 0, 10000) . '... [TRUNCATED]'
                        : $body,
                    'timestamp' => now()->toISOString(),
                ]);

                return;
            }
        }

        // Fallback to old behavior if integration not found
        $baseConfig = [
            'driver' => 'daily',
            'level' => 'debug',
            'days' => 2,
            'replace_placeholders' => true,
        ];

        $filename = generate_api_log_filename($service, $integrationId, $perInstance);
        $channelName = pathinfo($filename, PATHINFO_FILENAME);
        $baseConfig['path'] = storage_path('logs/' . $filename);

        $logger = Log::build($baseConfig);

        // If the logger is null (can happen in tests with spies), use the default Log facade
        if (is_null($logger)) {
            Log::debug('API Response', [
                'service' => $service,
                'integration_id' => $integrationId ?: null,
                'method' => $method,
                'endpoint' => $endpoint,
                'status_code' => $statusCode,
                'headers' => array_map(function ($header) {
                    return is_array($header) ? $header : [$header];
                }, sanitizeHeaders($headers)),
                'response_body' => strlen($body) > 10000
                    ? substr($body, 0, 10000) . '... [TRUNCATED]'
                    : $body,
                'timestamp' => now()->toISOString(),
            ]);

            return;
        }

        $logger->debug('API Response', [
            'service' => $service,
            'integration_id' => $integrationId ?: null,
            'method' => $method,
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'headers' => array_map(function ($header) {
                return is_array($header) ? $header : [$header];
            }, sanitizeHeaders($headers)),
            'response_body' => strlen($body) > 10000
                ? substr($body, 0, 10000) . '... [TRUNCATED]'
                : $body,
            'timestamp' => now()->toISOString(),
        ]);
    }
}

if (! function_exists('log_integration_webhook')) {
    /**
     * Log a webhook payload for a specific integration
     */
    function log_integration_webhook(
        string $service,
        string $integrationId,
        array $payload,
        array $headers = [],
        bool $perInstance = false
    ): void {
        $baseConfig = [
            'driver' => 'daily',
            'level' => 'debug',
            'days' => 2,
            'replace_placeholders' => true,
        ];

        $filename = generate_api_log_filename($service, $integrationId, $perInstance);
        $channelName = pathinfo($filename, PATHINFO_FILENAME); // Remove .log extension for channel name
        $baseConfig['path'] = storage_path('logs/' . $filename);

        $logger = Log::build($baseConfig);
        $logger->debug('Webhook Payload', [
            'service' => $service,
            'integration_id' => $integrationId,
            'headers' => array_map(function ($header) {
                return is_array($header) ? $header : [$header];
            }, sanitizeHeaders($headers)),
            'payload' => sanitizeData($payload),
            'timestamp' => now()->toISOString(),
        ]);
    }
}

if (! function_exists('should_log_debug')) {
    /**
     * Check if debug logging should be enabled for a user
     */
    function should_log_debug(User $user): bool
    {
        return $user->hasDebugLoggingEnabled();
    }
}

if (! function_exists('log_to_user')) {
    /**
     * Log a message to user's log channel
     */
    function log_to_user(User $user, string $level, string $message, array $context = []): void
    {
        LoggingService::logToUser($user, $level, $message, $context);
    }
}

if (! function_exists('log_to_group')) {
    /**
     * Log a message to integration group's log channel
     */
    function log_to_group(IntegrationGroup $group, string $level, string $message, array $context = []): void
    {
        LoggingService::logToGroup($group, $level, $message, $context);
    }
}

if (! function_exists('log_to_integration')) {
    /**
     * Log a message to integration instance's log channel
     */
    function log_to_integration(Integration $integration, string $level, string $message, array $context = []): void
    {
        LoggingService::logToIntegration($integration, $level, $message, $context);
    }
}

if (! function_exists('log_hierarchical')) {
    /**
     * Log a message hierarchically: integration → group → user
     * Debug logs only go to integration (if enabled), info/warning/error cascade up
     */
    function log_hierarchical(Integration $integration, string $level, string $message, array $context = []): void
    {
        LoggingService::logHierarchical($integration, $level, $message, $context);
    }
}

if (! function_exists('truncate_to_words')) {
    /**
     * Truncate text to a specified number of words
     */
    function truncate_to_words(string $text, int $wordLimit = 150): string
    {
        $words = str_word_count($text, 2);
        if (count($words) <= $wordLimit) {
            return $text;
        }

        $positions = array_keys($words);
        $truncatePosition = $positions[$wordLimit] ?? strlen($text);

        return substr($text, 0, $truncatePosition) . '...';
    }
}

if (! function_exists('karakeep_add_bookmark')) {
    /**
     * Add a bookmark to Karakeep instance
     * This function posts to Karakeep and returns immediately without triggering a sync.
     * The bookmark will be synced on the next scheduled pull.
     *
     * @param  string  $url  The URL to bookmark
     * @param  string|null  $title  Optional title for the bookmark
     * @param  array  $tags  Optional array of tag names to apply
     * @return array|null Returns bookmark data on success, null on failure
     */
    function karakeep_add_bookmark(string $url, ?string $title = null, array $tags = []): ?array
    {
        $karakeepUrl = config('services.karakeep.url');
        $accessToken = config('services.karakeep.access_token');

        if (! $karakeepUrl || ! $accessToken) {
            Log::error('Karakeep configuration missing', [
                'has_url' => ! empty($karakeepUrl),
                'has_token' => ! empty($accessToken),
            ]);

            return null;
        }

        try {
            $payload = ['url' => $url];

            if ($title) {
                $payload['title'] = $title;
            }

            if (! empty($tags)) {
                $payload['tags'] = $tags;
            }

            $response = Http::withToken($accessToken)
                ->post(rtrim($karakeepUrl, '/') . '/api/v1/bookmarks', $payload);

            if ($response->successful()) {
                Log::info('Successfully added bookmark to Karakeep', [
                    'url' => $url,
                    'title' => $title,
                    'tags' => $tags,
                ]);

                return $response->json();
            }

            Log::error('Failed to add bookmark to Karakeep', [
                'url' => $url,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Exception while adding bookmark to Karakeep', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

if (! function_exists('get_domain_from_url')) {
    /**
     * Extract the base domain from a URL (without subdomain, protocol, port, or path).
     * Examples:
     *   - http://emptycity.substack.com/p/article → substack.com
     *   - https://www.bbc.co.uk/culture/article → bbc.co.uk
     *   - https://www.google.com:8080/search?q=test → google.com
     *   - api.github.com/users → github.com
     *
     * @param  string  $url  The URL to parse
     * @return string|null The base domain or null if invalid
     */
    function get_domain_from_url(string $url): ?string
    {
        // Add protocol if not present to help parse_url work correctly
        if (! preg_match('~^(?:f|ht)tps?://~i', $url)) {
            $url = 'http://' . $url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        // Split the host into parts
        $parts = explode('.', $host);
        $numParts = count($parts);

        // Handle edge cases
        if ($numParts < 2) {
            return $host;
        }

        // Common two-part TLDs that need special handling
        $twoPartTlds = [
            'co.uk', 'gov.uk', 'org.uk', 'ac.uk', 'sch.uk', 'net.uk',
            'co.za', 'gov.za', 'org.za', 'net.za', 'ac.za',
            'co.nz', 'gov.nz', 'org.nz', 'net.nz', 'ac.nz',
            'co.jp', 'gov.jp', 'org.jp', 'ne.jp', 'ac.jp',
            'com.au', 'gov.au', 'org.au', 'net.au', 'edu.au',
            'co.in', 'gov.in', 'org.in', 'net.in', 'ac.in',
            'com.br', 'gov.br', 'org.br', 'net.br', 'edu.br',
            'co.kr', 'gov.kr', 'org.kr', 'ne.kr', 'ac.kr',
        ];

        // Check if we have a two-part TLD (e.g., co.uk)
        if ($numParts >= 3) {
            $possibleTld = $parts[$numParts - 2] . '.' . $parts[$numParts - 1];
            if (in_array($possibleTld, $twoPartTlds, true)) {
                // Return domain.co.uk format (last 3 parts)
                return $parts[$numParts - 3] . '.' . $possibleTld;
            }
        }

        // Default: Get the last two parts (domain.tld)
        $domain = $parts[$numParts - 2] . '.' . $parts[$numParts - 1];

        return $domain;
    }
}

if (! function_exists('normalize_icon_for_spotlight')) {
    /**
     * Normalize icon names to Wire Elements Spotlight format.
     * Strips library prefixes from both Heroicons and FontAwesome icons.
     *
     * @param  string|null  $icon  The icon name (possibly with prefix)
     * @return string|null The normalized icon name for Spotlight
     */
    function normalize_icon_for_spotlight(?string $icon): ?string
    {
        if (! $icon) {
            return null;
        }

        // Keep Font Awesome icons as-is (fas.icon-name or far.icon-name or fab.icon-name)
        if (str_starts_with($icon, 'fas.') || str_starts_with($icon, 'far.') || str_starts_with($icon, 'fab.')) {
            return $icon; // Return as-is, will be handled by FA icon classes
        }

        if (preg_match('/^fa[srb]-/', $icon)) {
            // Convert fas-icon-name to fas.icon-name format
            return str_replace(['fas-', 'far-', 'fab-'], ['fas.', 'far.', 'fab.'], $icon);
        }

        // Heroicons: o-icon-name or s-icon-name -> icon-name
        return preg_replace('/^[os]-/', '', $icon);
    }
}

if (! function_exists('heroicon_to_fontawesome')) {
    /**
     * Convert a Heroicon name to its FontAwesome equivalent.
     *
     * @param  string  $heroiconName  The Heroicon name (e.g., 'fas-heart')
     * @return string The FontAwesome icon name (e.g., 'fas-heart')
     */
    function heroicon_to_fontawesome(string $heroiconName): string
    {
        static $mappings = null;

        if ($mappings === null) {
            $mappings = config('icons.heroicon_to_fontawesome_map', []);
        }

        // Check explicit mapping first
        if (isset($mappings[$heroiconName])) {
            return $mappings[$heroiconName];
        }

        // Auto-convert: o-icon-name -> fas.icon-name
        $baseName = preg_replace('/^[os]-/', '', $heroiconName);

        return 'fas.' . $baseName;
    }
}

if (! function_exists('icon_name')) {
    /**
     * Get the appropriate icon name based on current library preference.
     * Converts between Heroicon and FontAwesome formats as needed.
     *
     * @param  string  $name  The icon name (any format)
     * @return string The icon name in the preferred format
     */
    function icon_name(string $name): string
    {
        $library = config('icons.default_library', 'fontawesome');

        // Already in correct format (dot notation for FontAwesome: fas.icon-name)
        if ($library === 'fontawesome' && preg_match('/^fa[srb]\./', $name)) {
            return $name;
        }
        if ($library === 'heroicons' && preg_match('/^[os]-/', $name)) {
            return $name;
        }

        // Convert Heroicon to FontAwesome if needed
        if ($library === 'fontawesome' && preg_match('/^[os]-/', $name)) {
            return heroicon_to_fontawesome($name);
        }

        return $name;
    }
}

if (! function_exists('get_card_brand_icon')) {
    /**
     * Get the FontAwesome brand icon for a payment card scheme.
     *
     * @param  string|null  $cardScheme  The card scheme (e.g., 'mastercard', 'visa')
     * @return string The FontAwesome icon name
     */
    function get_card_brand_icon(?string $cardScheme): string
    {
        $brands = config('icons.card_brand_icons', []);
        $scheme = strtolower($cardScheme ?? '');

        return $brands[$scheme] ?? $brands['default'] ?? 'fas-credit-card';
    }
}

if (! function_exists('get_financial_icon')) {
    /**
     * Get a financial-specific FontAwesome icon.
     *
     * @param  string  $type  The financial icon type (e.g., 'piggy_bank', 'wallet')
     * @return string The FontAwesome icon name
     */
    function get_financial_icon(string $type): string
    {
        $icons = config('icons.financial_icons', []);

        return $icons[$type] ?? 'fas-money-bill';
    }
}

if (! function_exists('get_integration_brand_icon')) {
    /**
     * Get the brand icon for a third-party integration.
     *
     * @param  string  $service  The service identifier (e.g., 'github', 'spotify')
     * @return string|null The FontAwesome brand icon or null if not available
     */
    function get_integration_brand_icon(string $service): ?string
    {
        $brands = config('icons.integration_brand_icons', []);
        $service = strtolower($service);

        return $brands[$service] ?? null;
    }
}

if (! function_exists('get_media_url')) {
    /**
     * Get the media URL for a model, falling back to media_url field if no Media Library attachment exists.
     *
     * @param  \Illuminate\Database\Eloquent\Model&\Spatie\MediaLibrary\HasMedia  $model
     * @param  string  $collection  The media collection to check
     * @param  string  $conversion  The conversion name (thumbnail, medium, webp, or empty for original)
     * @return string|null The media URL or null if no media found
     */
    function get_media_url(
        $model,
        string $collection = 'downloaded_images',
        string $conversion = ''
    ): ?string {
        // Check for Media Library attachment first
        if (method_exists($model, 'getFirstMedia')) {
            $media = $model->getFirstMedia($collection);

            if ($media) {
                if ($conversion) {
                    return $media->getUrl($conversion);
                }

                return $media->getUrl();
            }
        }

        // Fallback to media_url field (backward compatibility during migration)
        if (isset($model->media_url) && ! empty($model->media_url)) {
            return $model->media_url;
        }

        return null;
    }
}

if (! function_exists('get_media_temporary_url')) {
    /**
     * Get a temporary signed URL for media (for private S3 buckets).
     *
     * @param  \Illuminate\Database\Eloquent\Model&\Spatie\MediaLibrary\HasMedia  $model
     * @param  string  $collection  The media collection to check
     * @param  string  $conversion  The conversion name (thumbnail, medium, webp, or empty for original)
     * @param  int  $expirationMinutes  How long the URL should be valid (default: 60 minutes)
     * @return string|null The temporary URL or null if no media found
     */
    function get_media_temporary_url(
        $model,
        string $collection = 'downloaded_images',
        string $conversion = '',
        int $expirationMinutes = 60
    ): ?string {
        if (! method_exists($model, 'getFirstMedia')) {
            return null;
        }

        $media = $model->getFirstMedia($collection);

        if (! $media) {
            // Fallback to regular media_url if no Media Library attachment
            if (isset($model->media_url) && ! empty($model->media_url)) {
                return $model->media_url;
            }

            return null;
        }

        // For local/public disks, just return the regular URL
        if (config('media-library.disk_name') !== 's3') {
            if ($conversion) {
                return $media->getUrl($conversion);
            }

            return $media->getUrl();
        }

        // For S3, generate temporary URL
        if ($conversion) {
            return $media->getTemporaryUrl(
                now()->addMinutes($expirationMinutes),
                $conversion
            );
        }

        return $media->getTemporaryUrl(
            now()->addMinutes($expirationMinutes)
        );
    }
}
