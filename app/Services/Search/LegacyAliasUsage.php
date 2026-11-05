<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;

/**
 * Daily counts of the pre-v1 search request spellings still in use (`q`,
 * plural modes, string booleans), so they can be removed once no shipped
 * build sends them. Counts only: never the query text, the user or the build.
 */
class LegacyAliasUsage
{
    public const ALIASES = ['q', 'plural_mode', 'string_boolean'];

    private const TTL_DAYS = 45;

    public static function record(string $alias): void
    {
        $key = self::key($alias, now()->format('Y-m-d'));

        Cache::add($key, 0, now()->addDays(self::TTL_DAYS));
        Cache::increment($key);
    }

    /**
     * @return array<string, int>
     */
    public static function counts(string $day): array
    {
        $counts = [];

        foreach (self::ALIASES as $alias) {
            $counts[$alias] = (int) Cache::get(self::key($alias, $day), 0);
        }

        return $counts;
    }

    private static function key(string $alias, string $day): string
    {
        return "search:legacy-alias:{$alias}:{$day}";
    }
}
