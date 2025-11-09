<?php

namespace App\Integrations\Fetch;

use Carbon\Carbon;
use Exception;

class CookieParser
{
    /**
     * Parse cookies from various JSON formats
     *
     * Supported formats:
     * 1. Standard: [{"name": "session", "value": "xyz", "expires": 1733155200}]
     * 2. Simple: {"cookie_name": "value"}
     * 3. Browser HAR: [{"name": "session", "value": "xyz", "expirationDate": 1733155200}]
     *
     * @return array ['success' => bool, 'cookies' => [...], 'expires_at' => string|null, 'error' => string|null]
     */
    public static function parse(string $jsonString): array
    {
        $cookies = [];
        $earliestExpiry = null;

        // Try to decode JSON
        try {
            $data = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
        } catch (Exception $e) {
            return [
                'success' => false,
                'cookies' => [],
                'expires_at' => null,
                'error' => 'Invalid JSON: ' . $e->getMessage(),
            ];
        }

        // Handle empty JSON
        if (empty($data)) {
            return [
                'success' => false,
                'cookies' => [],
                'expires_at' => null,
                'error' => 'No cookies found in JSON',
            ];
        }

        // Handle simple key-value format: {"cookie_name": "value"}
        // Require at least 2 cookies for simple format to avoid false positives
        if (self::isSimpleFormat($data)) {
            if (count($data) < 2) {
                return [
                    'success' => false,
                    'cookies' => [],
                    'expires_at' => null,
                    'error' => 'Unsupported cookie format: simple format requires at least 2 cookies',
                ];
            }

            foreach ($data as $name => $value) {
                $cookies[$name] = $value;
            }

            return [
                'success' => true,
                'cookies' => $cookies,
                'expires_at' => null,
                'error' => null,
            ];
        }

        // Handle array format (standard or HAR)
        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            foreach ($data as $cookie) {
                if (! isset($cookie['name']) || ! isset($cookie['value'])) {
                    continue;
                }

                $name = $cookie['name'];
                $value = $cookie['value'];
                $cookies[$name] = $value;

                // Try to extract expiry
                $expiry = self::extractExpiry($cookie);
                if ($expiry) {
                    // Track earliest expiry date
                    if ($earliestExpiry === null || $expiry->lt($earliestExpiry)) {
                        $earliestExpiry = $expiry;
                    }
                }
            }

            if (empty($cookies)) {
                return [
                    'success' => false,
                    'cookies' => [],
                    'expires_at' => null,
                    'error' => 'No valid cookies found in array',
                ];
            }

            return [
                'success' => true,
                'cookies' => $cookies,
                'expires_at' => $earliestExpiry?->toIso8601String(),
                'error' => null,
            ];
        }

        // Unsupported format
        return [
            'success' => false,
            'cookies' => [],
            'expires_at' => null,
            'error' => 'Unsupported cookie format',
        ];
    }

    /**
     * Format parsed cookies for storage in auth_metadata
     *
     * @param  array  $parsed  Result from parse() method
     * @param  string  $domain  The domain these cookies are for
     */
    public static function formatForStorage(array $parsed, string $domain): array
    {
        return [
            'cookies' => $parsed['cookies'],
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ],
            'added_at' => now()->toIso8601String(),
            'expires_at' => $parsed['expires_at'],
            'last_used_at' => null,
        ];
    }

    /**
     * Get expiry status for UI display
     *
     * @return string 'green'|'yellow'|'red'|'gray'
     */
    public static function getExpiryStatus(?string $expiresAt): string
    {
        if (! $expiresAt) {
            return 'gray';
        }

        try {
            $expiry = Carbon::parse($expiresAt);
            $now = now();

            if ($expiry->isPast()) {
                return 'red';
            }

            $daysUntilExpiry = $now->diffInDays($expiry);

            if ($daysUntilExpiry < 3) {
                return 'red';
            } elseif ($daysUntilExpiry <= 7) {
                return 'yellow';
            } else {
                return 'green';
            }
        } catch (Exception) {
            return 'gray';
        }
    }

    /**
     * Get detailed expiry information for UI display
     *
     * @return array ['status' => 'green|yellow|red|gray', 'message' => string, 'days_until_expiry' => int|null]
     */
    public static function getExpiryDetails(?string $expiresAt): array
    {
        if (! $expiresAt) {
            return [
                'status' => 'gray',
                'message' => 'No expiry set',
                'days_until_expiry' => null,
            ];
        }

        try {
            $expiry = Carbon::parse($expiresAt);
            $now = now();

            if ($expiry->isPast()) {
                return [
                    'status' => 'red',
                    'message' => 'Expired ' . $expiry->diffForHumans(),
                    'days_until_expiry' => 0,
                ];
            }

            $daysUntilExpiry = $now->diffInDays($expiry);

            if ($daysUntilExpiry < 1) {
                return [
                    'status' => 'red',
                    'message' => 'Expires in less than 1 day!',
                    'days_until_expiry' => $daysUntilExpiry,
                ];
            } elseif ($daysUntilExpiry <= 3) {
                return [
                    'status' => 'red',
                    'message' => "Expires in {$daysUntilExpiry} days",
                    'days_until_expiry' => $daysUntilExpiry,
                ];
            } elseif ($daysUntilExpiry <= 7) {
                return [
                    'status' => 'yellow',
                    'message' => "Expires in {$daysUntilExpiry} days",
                    'days_until_expiry' => $daysUntilExpiry,
                ];
            } else {
                return [
                    'status' => 'green',
                    'message' => 'Expires ' . $expiry->format('M j, Y'),
                    'days_until_expiry' => $daysUntilExpiry,
                ];
            }
        } catch (Exception) {
            return [
                'status' => 'gray',
                'message' => 'Invalid expiry date',
                'days_until_expiry' => null,
            ];
        }
    }

    /**
     * Check if data is in simple key-value format
     */
    private static function isSimpleFormat($data): bool
    {
        if (! is_array($data)) {
            return false;
        }

        // If it's an indexed array, it's not simple format
        if (isset($data[0])) {
            return false;
        }

        // Check if all values are scalars (strings/numbers)
        foreach ($data as $value) {
            if (is_array($value) || is_object($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract expiry date from cookie array
     *
     * Supports multiple fields:
     * - expires (unix timestamp)
     * - expirationDate (unix timestamp, HAR format)
     * - expiry (unix timestamp)
     * - expires_at (ISO 8601 string or unix timestamp)
     */
    private static function extractExpiry(array $cookie): ?Carbon
    {
        $expiryFields = ['expires', 'expirationDate', 'expiry', 'expires_at'];

        foreach ($expiryFields as $field) {
            if (! isset($cookie[$field])) {
                continue;
            }

            $value = $cookie[$field];

            // Handle unix timestamp
            if (is_numeric($value)) {
                try {
                    return Carbon::createFromTimestamp($value);
                } catch (Exception $e) {
                    continue;
                }
            }

            // Handle ISO 8601 string
            if (is_string($value)) {
                try {
                    return Carbon::parse($value);
                } catch (Exception $e) {
                    continue;
                }
            }
        }

        return null;
    }
}
