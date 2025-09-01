<?php

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
