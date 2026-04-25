<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Opaque cursor pagination for mobile feeds.
 *
 * The cursor encodes the `{last_id}|{last_time_iso}` pair so the client only
 * ever sees an opaque token. Ordering is `(time desc, id desc)` tie-broken so
 * rows created in the same second remain stable under concurrent inserts.
 */
class CursorPaginator
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 200;

    /**
     * Paginate an Eloquent/Query builder using an opaque cursor.
     *
     * Returns `[data, next_cursor, has_more]`. `next_cursor` is null when the
     * result set is exhausted. `data` is already limited to `$limit` rows.
     *
     * @param  Builder|QueryBuilder  $query
     * @return array{0: Collection, 1: ?string, 2: bool}
     */
    public static function paginate(
        $query,
        ?string $cursor = null,
        int $limit = self::DEFAULT_LIMIT,
        string $timeColumn = 'created_at',
        string $idColumn = 'id',
    ): array {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        if ($cursor !== null) {
            $decoded = self::decode($cursor);
            if ($decoded !== null) {
                [$lastId, $lastTime] = $decoded;
                $query->where(function ($q) use ($timeColumn, $idColumn, $lastId, $lastTime) {
                    $q->where($timeColumn, '<', $lastTime)
                        ->orWhere(function ($q) use ($timeColumn, $idColumn, $lastId, $lastTime) {
                            $q->where($timeColumn, '=', $lastTime)
                                ->where($idColumn, '<', $lastId);
                        });
                });
            }
        }

        $rows = $query->orderBy($timeColumn, 'desc')
            ->orderBy($idColumn, 'desc')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $data = $hasMore ? $rows->slice(0, $limit)->values() : $rows;

        $nextCursor = null;
        if ($hasMore && $data->isNotEmpty()) {
            $last = $data->last();
            $nextCursor = self::encode(
                (string) $last->{$idColumn},
                self::formatTime($last->{$timeColumn}),
            );
        }

        return [$data, $nextCursor, $hasMore];
    }

    public static function encode(string $id, string $timeIso): string
    {
        return rtrim(strtr(base64_encode($id . '|' . $timeIso), '+/', '-_'), '=');
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function decode(string $cursor): ?array
    {
        $padded = str_pad(strtr($cursor, '-_', '+/'), (int) (strlen($cursor) % 4 === 0 ? strlen($cursor) : strlen($cursor) + (4 - strlen($cursor) % 4)), '=');
        $decoded = base64_decode($padded, true);
        if ($decoded === false || ! str_contains($decoded, '|')) {
            return null;
        }

        [$id, $time] = explode('|', $decoded, 2);
        if ($id === '' || $time === '') {
            return null;
        }

        return [$id, $time];
    }

    private static function formatTime(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.u');
        }

        return (string) $value;
    }
}
