<?php

namespace App\Services\Flint;

final class FlintScheduleSettings
{
    public const ENABLED_KEYS = [
        'morning_digest_enabled',
        'evening_digest_enabled',
        'topics_enabled',
        'reading_list_enabled',
        'news_roundup_enabled',
    ];

    /** @param array<string, mixed> $settings */
    public static function enabled(array $settings, string $key, bool $default = false): bool
    {
        return (bool) ($settings[$key] ?? $settings['digests_enabled'] ?? $default);
    }
}
