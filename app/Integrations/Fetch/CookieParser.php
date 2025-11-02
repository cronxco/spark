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
     * @return array ['cookies' => [...], 'expires_at' => Carbon|null, 'errors' => [...]]
     */
    public static function parse(string $jsonString): array
    {
        $errors = [];
        $cookies = [];
        $earliestExpiry = null;

        // Try to decode JSON
        try {
            $data = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
        } catch (Exception $e) {
            return [
                'cookies' => [],
                'expires_at' => null,
                'errors' => ['Invalid JSON: ' . $e->getMessage()],
            ];
        }

        // Handle simple key-value format: {"cookie_name": "value"}
        if (self::isSimpleFormat($data)) {
            foreach ($data as $name => $value) {
                $cookies[$name] = $value;
            }

            $errors[] = 'Simple format detected: no expiry information available';

            return [
                'cookies' => $cookies,
                'expires_at' => null,
                'errors' => $errors,
            ];
        }

        // Handle array format (standard or HAR)
        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            foreach ($data as $index => $cookie) {
                if (! isset($cookie['name']) || ! isset($cookie['value'])) {
                    $errors[] = "Cookie at index {$index} missing 'name' or 'value'";

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
        } else {
            $errors[] = 'Unrecognized format';
        }

        if (empty($cookies)) {
            $errors[] = 'No cookies were parsed';
        }

        return [
            'cookies' => $cookies,
            'expires_at' => $earliestExpiry,
            'errors' => $errors,
        ];
    }

    /**
     * Format parsed cookies for storage in auth_metadata
     */
    public static function formatForStorage(array $cookies, ?Carbon $expiresAt): array
    {
        return [
            'cookies' => $cookies,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ],
            'added_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
            'last_used_at' => null,
        ];
    }

    /**
     * Get expiry status for UI display
     *
     * @return array ['status' => 'green|yellow|red|gray', 'message' => string, 'days_until_expiry' => int|null]
     */
    public static function getExpiryStatus(?string $expiresAt): array
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
                    'message' => 'Expired ' .

$expiry->diffForHumans(),
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
        } catch (Exception $e) {
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
        foreach ($data as $key => $value) {
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
