<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

class NotificationIncidentResolver
{
    /** @param array<int, string> $groupKeys */
    public function resolve(User $user, array $groupKeys): int
    {
        $notifications = $user->notifications()
            ->whereNull('archived_at')
            ->whereIn('group_key', $groupKeys)
            ->get();

        if ($notifications->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($notifications) {
            $notifications->each(function (DatabaseNotification $notification) {
                $data = is_array($notification->data) ? $notification->data : [];
                $notification->forceFill([
                    'archived_at' => now(),
                    'data' => [...$data, 'archive_reason' => 'resolved'],
                ])->save();
            });
        });

        return $notifications->count();
    }
}
