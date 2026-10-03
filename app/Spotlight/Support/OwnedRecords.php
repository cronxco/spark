<?php

namespace App\Spotlight\Support;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\MetricStatistic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Starting queries for Spotlight lookups, each limited to the signed-in user.
 *
 * Scoped queries resolve their subject from a Spotlight token, and token
 * parameters arrive from the client, so an id in one is untrusted. Resolving
 * it through these queries means a forged or stale id finds nothing instead
 * of another user's record.
 */
final class OwnedRecords
{
    public static function events(): Builder
    {
        return Event::forUser(Auth::id());
    }

    public static function blocks(): Builder
    {
        return Block::query()->whereHas('event.integration', fn ($q) => $q->where('user_id', Auth::id()));
    }

    public static function objects(): Builder
    {
        return EventObject::query()->where('user_id', Auth::id());
    }

    public static function integrations(): Builder
    {
        return Integration::query()->where('user_id', Auth::id());
    }

    public static function metrics(): Builder
    {
        return MetricStatistic::query()->where('user_id', Auth::id());
    }
}
