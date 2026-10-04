<?php

namespace App\Support;

use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Selects Flint digests by the local day they were written for (decision D-F2).
 *
 * A digest's `time` is its local midnight stored as a wall-clock value with a
 * UTC label, so a UTC range built from the user's local day bounds misses
 * digests for anyone west of UTC. Readers filter on the recorded
 * `event_metadata.local_date` instead. Digests written before that key existed
 * fall back to the calendar date of `time`, which is the same wall-clock day.
 */
final class FlintDigestDay
{
    /** @param Builder<Event> $query */
    public static function on(Builder $query, string $localDate): Builder
    {
        return self::between($query, $localDate, $localDate);
    }

    /** @param Builder<Event> $query */
    public static function between(Builder $query, string $fromDate, string $toDate): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query
                ->where('event_metadata->local_date', '>=', $fromDate)
                ->where('event_metadata->local_date', '<=', $toDate))
            ->orWhere(fn (Builder $query) => $query
                ->whereNull('event_metadata->local_date')
                ->where('time', '>=', Carbon::parse($fromDate, 'UTC')->startOfDay())
                ->where('time', '<', Carbon::parse($toDate, 'UTC')->addDay()->startOfDay())));
    }
}
