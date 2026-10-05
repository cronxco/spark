<?php

namespace App\Services\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;

class NotificationOccurrence
{
    public static function last(DatabaseNotification $notification): CarbonImmutable
    {
        // updated_at also changes on read/archive/backfill. It is not evidence
        // of a new failure, and using it resurrects expired legacy digests.
        return CarbonImmutable::parse($notification->data['last_occurred_at'] ?? $notification->created_at);
    }
}
