<?php

namespace App\Services\Notifications;

use App\Models\User;

class NotificationIncidentResolver
{
    public function __construct(private NotificationArchiver $archiver) {}

    /** @param array<int, string> $groupKeys */
    public function resolve(User $user, array $groupKeys): int
    {
        $notifications = $user->notifications()
            ->whereNull('archived_at')
            ->whereIn('group_key', $groupKeys)
            ->get();

        foreach ($notifications as $notification) {
            $this->archiver->archive($notification, 'resolved');
        }

        return $notifications->count();
    }
}
