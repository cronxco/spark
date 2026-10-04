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

    /**
     * Whether one routine is switched on. Each routine has its own switch and
     * an unset switch is off (decision D-F1: the old all-routines
     * `digests_enabled` key is no longer read).
     *
     * @param  array<string, mixed>  $settings
     */
    public static function enabled(array $settings, string $key): bool
    {
        return (bool) ($settings[$key] ?? false);
    }
}
