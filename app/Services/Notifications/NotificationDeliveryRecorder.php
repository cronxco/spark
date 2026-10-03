<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Notifications\Channels\ApnsChannel;
use App\Notifications\SparkNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use NotificationChannels\WebPush\WebPushChannel;
use Pushok\Response;

/**
 * Records how each channel's delivery went on the in-app notification, under
 * `data.delivery.{channel}` (decision N-2: metadata only, no new table).
 */
class NotificationDeliveryRecorder
{
    public const CHANNEL_KEYS = [
        'mail' => 'mail',
        ApnsChannel::class => 'apns',
        WebPushChannel::class => 'web_push',
    ];

    public function sent(User $notifiable, SparkNotification $notification, string $channel, mixed $response): void
    {
        $outcome = $channel === ApnsChannel::class
            ? $this->apnsOutcome($response)
            : ['status' => 'sent'];

        $this->record($notifiable, $notification, $channel, $outcome);
    }

    public function failed(User $notifiable, SparkNotification $notification, string $channel, string $error): void
    {
        $this->record($notifiable, $notification, $channel, ['status' => 'failed', 'error' => $error]);
    }

    /**
     * The id of the in-app record a delivery belongs to, so a push names the
     * notification the app can report receipts for (decision N-8). Falls back
     * to the delivery's own id while the in-app record is still to be written.
     */
    public function storedNotificationId(User $notifiable, SparkNotification $notification): ?string
    {
        $stored = $this->storedNotification($notifiable, $notification, lock: false);

        return $stored !== null ? (string) $stored->getKey() : $notification->id;
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function record(User $notifiable, SparkNotification $notification, string $channel, array $outcome): void
    {
        $key = self::CHANNEL_KEYS[$channel] ?? null;
        if ($key === null) {
            return;
        }

        DB::transaction(function () use ($notifiable, $notification, $key, $outcome): void {
            $stored = $this->storedNotification($notifiable, $notification);
            if ($stored === null) {
                return;
            }

            $data = is_array($stored->data) ? $stored->data : [];
            $data['delivery'][$key] = [...$outcome, 'at' => now()->toJSON()];
            $stored->timestamps = false;
            $stored->forceFill(['data' => $data])->save();
        });
    }

    /**
     * The in-app record this delivery belongs to. A repeat may have been
     * folded into an earlier open notification with the same group key, so
     * the delivery's own id is not always the stored one.
     */
    private function storedNotification(User $notifiable, SparkNotification $notification, bool $lock = true): ?DatabaseNotification
    {
        $stored = $notification->id
            ? $notifiable->notifications()->whereKey($notification->id)->when($lock, fn ($query) => $query->lockForUpdate())->first()
            : null;

        $groupKey = $notification->getGroupKey();
        if ($stored === null && $groupKey !== null) {
            $stored = $notifiable->notifications()
                ->whereNull('archived_at')
                ->where('group_key', $groupKey)
                ->latest()
                ->when($lock, fn ($query) => $query->lockForUpdate())
                ->first();
        }

        return $stored;
    }

    /**
     * @return array{status: string, devices: int, failed: int, error?: string}
     */
    private function apnsOutcome(mixed $responses): array
    {
        if (! is_array($responses) || $responses === []) {
            return ['status' => 'skipped', 'devices' => 0, 'failed' => 0];
        }

        $failures = array_values(array_filter(
            $responses,
            fn (mixed $response): bool => $response instanceof Response && $response->getStatusCode() !== Response::APNS_SUCCESS,
        ));
        $outcome = [
            'status' => count($failures) < count($responses) ? 'sent' : 'failed',
            'devices' => count($responses),
            'failed' => count($failures),
        ];

        if ($failures !== []) {
            $outcome['error'] = (string) $failures[0]->getErrorReason();
        }

        return $outcome;
    }
}
