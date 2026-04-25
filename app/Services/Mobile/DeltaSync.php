<?php

namespace App\Services\Mobile;

use App\Http\Resources\Compact\CompactEventResource;
use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon as SupportCarbon;
use Throwable;

/**
 * Surfaces the events a user has created/updated/deleted since a given
 * (updated_at, id) cursor so the iOS client can catch up from offline.
 * The cursor format is "{iso8601_updated_at}|{uuid}" — deterministic even
 * when multiple events share the same updated_at timestamp.
 */
class DeltaSync
{
    public const DEFAULT_LIMIT = 200;

    public function delta(User $user, ?string $cursor, Request $request): array
    {
        [$since, $sinceId] = $this->parseCursor($cursor);

        $integrationIds = $user->integrations()->pluck('id')->all();

        $baseQuery = Event::query()
            ->withTrashed()
            ->when(! empty($integrationIds), fn ($q) => $q->whereIn('integration_id', $integrationIds), fn ($q) => $q->whereRaw('1 = 0'))
            ->where(function ($q) use ($since, $sinceId): void {
                $q->where('updated_at', '>', $since);

                if ($sinceId !== null) {
                    $q->orWhere(function ($q) use ($since, $sinceId): void {
                        $q->where('updated_at', $since)
                            ->where('id', '>', $sinceId);
                    });
                }
            })
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(self::DEFAULT_LIMIT);

        $events = $baseQuery->get();

        $created = $events->filter(fn (Event $e) => $e->deleted_at === null && $e->created_at >= $since);
        $updated = $events->filter(fn (Event $e) => $e->deleted_at === null && $e->created_at < $since);
        $deleted = $events->filter(fn (Event $e) => $e->deleted_at !== null);

        $last = $events->last();
        $nextCursor = $last
            ? $last->updated_at->toIso8601String() . '|' . $last->id
            : ($sinceId !== null ? $since->toIso8601String() . '|' . $sinceId : $since->toIso8601String());

        return [
            'created' => CompactEventResource::collection($created->values())->resolve($request),
            'updated' => CompactEventResource::collection($updated->values())->resolve($request),
            'deleted' => $deleted->pluck('id')->all(),
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * Parse a cursor string into a (Carbon timestamp, string|null uuid) pair.
     *
     * Accepts both the legacy ISO-8601-only format and the canonical
     * "{iso8601}|{uuid}" format so existing clients are not broken.
     * Returns null for the id when no UUID tie-breaker is present.
     *
     * @return array{Carbon, string|null}
     */
    protected function parseCursor(?string $cursor): array
    {
        if (! $cursor) {
            return [SupportCarbon::now()->subDay(), null];
        }

        try {
            if (str_contains($cursor, '|')) {
                [$timestamp, $id] = explode('|', $cursor, 2);

                return [SupportCarbon::parse($timestamp), $id !== '' ? $id : null];
            }

            return [SupportCarbon::parse($cursor), null];
        } catch (Throwable) {
            return [SupportCarbon::now()->subDay(), null];
        }
    }
}
