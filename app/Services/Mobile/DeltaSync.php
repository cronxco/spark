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
 * timestamp cursor so the iOS client can catch up from offline. The cursor is
 * an ISO-8601 timestamp (`updated_at`) — simple, monotonic, survives server
 * restarts. When we add broadcast-sequence delivery we'll swap this to use
 * the sequence id.
 */
class DeltaSync
{
    public const DEFAULT_LIMIT = 200;

    public function delta(User $user, ?string $cursor, Request $request): array
    {
        $since = $this->parseCursor($cursor);

        $integrationIds = $user->integrations()->pluck('id')->all();

        $baseQuery = Event::query()
            ->withTrashed()
            ->when(! empty($integrationIds), fn ($q) => $q->whereIn('integration_id', $integrationIds), fn ($q) => $q->whereRaw('1 = 0'))
            ->where('updated_at', '>', $since)
            ->orderBy('updated_at')
            ->limit(self::DEFAULT_LIMIT);

        $events = $baseQuery->get();

        $created = $events->filter(fn (Event $e) => $e->deleted_at === null && $e->created_at >= $since);
        $updated = $events->filter(fn (Event $e) => $e->deleted_at === null && $e->created_at < $since);
        $deleted = $events->filter(fn (Event $e) => $e->deleted_at !== null);

        $nextCursor = $events->isNotEmpty()
            ? optional($events->last()->updated_at)->toIso8601String()
            : ($cursor ?? $since->toIso8601String());

        return [
            'created' => CompactEventResource::collection($created->values())->resolve($request),
            'updated' => CompactEventResource::collection($updated->values())->resolve($request),
            'deleted' => $deleted->pluck('id')->all(),
            'next_cursor' => $nextCursor,
        ];
    }

    protected function parseCursor(?string $cursor): Carbon
    {
        if (! $cursor) {
            return SupportCarbon::now()->subDay();
        }

        try {
            return SupportCarbon::parse($cursor);
        } catch (Throwable) {
            return SupportCarbon::now()->subDay();
        }
    }
}
