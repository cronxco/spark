<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * MR-14: cursor pagination over an already-materialised, stably-ordered
 * collection, for the handful of mobile list endpoints (Flint topics, money
 * accounts) whose source isn't a query builder — {@see CursorPaginator} is
 * for those. The lists these serve are small, but "small today" is exactly
 * the case the requirement calls out: every collection endpoint should carry
 * the same envelope and accept `cursor`/`limit`, so growth never needs a
 * breaking response-shape change later.
 *
 * The cursor is an opaque, base64-encoded offset — safe here specifically
 * because the caller's ordering is stable for the lifetime of one paging
 * pass (no concurrent inserts reorder a topic or account list mid-scroll the
 * way an event feed can).
 */
class CollectionCursorPage
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 100;

    /**
     * @param  Collection<int, mixed>  $items
     * @return array{0: Collection<int, mixed>, 1: ?string, 2: bool}
     */
    public static function paginate(Collection $items, ?string $cursor, int $limit): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $offset = self::decode($cursor);

        $page = $items->slice($offset, $limit)->values();
        $hasMore = ($offset + $limit) < $items->count();

        return [$page, $hasMore ? self::encode($offset + $limit) : null, $hasMore];
    }

    private static function decode(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }

        $decoded = base64_decode($cursor, true);

        return is_numeric($decoded) ? max(0, (int) $decoded) : 0;
    }

    private static function encode(int $offset): string
    {
        return base64_encode((string) $offset);
    }
}
