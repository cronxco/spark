<?php

namespace App\Support;

use App\Models\Block;
use App\Models\EventObject;
use Illuminate\Database\Eloquent\Builder;

/**
 * Media restricted to one user.
 *
 * Media hangs off EventObject (user_id) and Block (through its event's
 * integration); anything else is excluded. Deduplicated files share an MD5
 * across tenants, so any lookup by hash must go through this too.
 */
final class OwnedMediaQuery
{
    public static function scope(Builder $query, int|string|null $userId): Builder
    {
        if ($userId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHasMorph(
            'model',
            [EventObject::class, Block::class],
            function (Builder $q, string $type) use ($userId) {
                if ($type === EventObject::class) {
                    $q->where('user_id', $userId);
                } elseif ($type === Block::class) {
                    $q->whereHas('event.integration', fn (Builder $iq) => $iq->where('user_id', $userId));
                }
            },
        );
    }
}
