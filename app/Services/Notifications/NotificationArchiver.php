<?php

namespace App\Services\Notifications;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * Archives a single notification under a row lock so a concurrent incident
 * coalescing or resolution update to `data` is merged rather than discarded.
 */
class NotificationArchiver
{
    public function archive(DatabaseNotification $notification, string $reason): ?DatabaseNotification
    {
        return DB::transaction(function () use ($notification, $reason) {
            $current = DatabaseNotification::query()->lockForUpdate()->find($notification->getKey());

            if ($current === null) {
                return null;
            }

            $data = is_array($current->data) ? $current->data : [];
            $current->forceFill([
                'archived_at' => $current->archived_at ?? now(),
                'data' => [...$data, 'archive_reason' => $reason],
            ])->save();

            return $current;
        });
    }
}
