<?php

namespace App\Services\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * Records when the iOS app showed, opened or tapped a notification, under
 * `data.receipts.{event}` (decision N-8: operational telemetry on the
 * notification's existing data, no new table). Receipts never carry content.
 */
class NotificationReceiptRecorder
{
    public const EVENTS = ['shown', 'opened', 'tapped'];

    /**
     * Store the receipt unless this event was already recorded: the first
     * timestamp wins and repeats are no-ops.
     *
     * @return bool Whether the receipt was newly stored.
     */
    public function record(DatabaseNotification $notification, string $event, CarbonInterface $occurredAt, ?string $action = null): bool
    {
        return DB::transaction(function () use ($notification, $event, $occurredAt, $action): bool {
            $stored = DatabaseNotification::query()->whereKey($notification->getKey())->lockForUpdate()->first();
            if ($stored === null) {
                return false;
            }

            $data = is_array($stored->data) ? $stored->data : [];
            if (isset($data['receipts'][$event])) {
                return false;
            }

            $data['receipts'][$event] = array_filter([
                'at' => $occurredAt->toJSON(),
                'action' => $action,
                'recorded_at' => now()->toJSON(),
            ], fn (?string $value): bool => $value !== null);
            $stored->timestamps = false;
            $stored->forceFill(['data' => $data])->save();

            return true;
        });
    }
}
