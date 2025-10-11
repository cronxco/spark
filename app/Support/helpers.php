<?php

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\LoggingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
            'and', 'as', 'but', 'for', 'if', 'nor', 'or', 'so', 'yet',
            'a', 'an', 'the',
            'about', 'above', 'across', 'after', 'against', 'along', 'among', 'around', 'at',
            'before', 'behind', 'below', 'beneath', 'beside', 'besides', 'between', 'beyond', 'by',
            'concerning', 'considering', 'despite', 'down', 'during', 'except', 'following', 'for', 'from',
            'in', 'inside', 'into', 'like', 'near', 'of', 'off', 'on', 'onto', 'opposite', 'outside', 'over',
            'past', 'per', 'plus', 'regarding', 'round', 'since', 'than', 'through', 'to', 'toward', 'under',
            'underneath', 'unlike', 'until', 'up', 'upon', 'via', 'with', 'within', 'without',
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

if (! function_exists('format_event_value_display')) {
    /**
     * Format an event's value for display, handling mapped values correctly.
     *
     * @param  mixed  $value  The event's formatted_value or value
     * @param  string|null  $unit  The event's value_unit
     * @return string The formatted value for display
     */
    function format_event_value_display($value, ?string $unit): string
    {
        if ($value === null) {
            return '';
        }

        // Handle mapped values (stress_level, resilience_level, etc.)
        // These should display just the mapped text without the unit
        if (in_array($unit, ['stress_level', 'resilience_level'])) {
            return (string) $value;
        }

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
