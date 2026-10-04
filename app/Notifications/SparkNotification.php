<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\ApnsChannel;
use App\Services\Notifications\NotificationDeliveryRecorder;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\Apn\ApnMessage;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use Throwable;

abstract class SparkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** The local window in which an incident alert's push and email wait for morning (decision N-1). */
    public const OVERNIGHT_START = '22:00';

    public const OVERNIGHT_END = '07:00';

    /** Set on a channel's copy when it was held overnight, so it is re-checked before sending. */
    public bool $heldOvernight = false;

    /** The channel and user a queued copy delivers to, so a failed job can be recorded (decision N-2). */
    public ?string $deliveryChannel = null;

    public int|string|null $deliveryUserId = null;

    private ?string $occurredAt = null;

    /**
     * Get the notification type identifier for preferences
     */
    abstract public function getNotificationType(): string;

    /**
     * Per-channel queue names, keyed by the exact channel identifiers `via()`
     * returns below. Routes delivery to a monitored Horizon supervisor (see
     * config/horizon.php) instead of falling onto an unmonitored default
     * queue. Can't use the Queueable trait's own `$queue` property for this:
     * declaring it here too, with a different default, is a fatal PHP
     * trait/class property composition conflict.
     */
    public function viaQueues(): array
    {
        return [
            'database' => 'notifications',
            'mail' => 'notifications',
            ApnsChannel::class => 'notifications',
            WebPushChannel::class => 'notifications',
        ];
    }

    /**
     * Get the notification priority
     * Priority notifications always send immediately via all channels
     */
    public function isPriority(): bool
    {
        return NotificationCatalogue::forcesDelivery($this->getNotificationType());
    }

    /**
     * Get the notification's delivery channels
     */
    public function via(User $notifiable): array
    {
        $this->occurredAt ??= now()->toJSON();
        $this->afterCommit();
        $channels = ['database'];

        if ($this->isPriority()) {
            $channels[] = 'mail';

            return array_merge($channels, $this->pushChannelsFor($notifiable));
        }

        // A daily-digest user's email goes out in SendNotificationDigests;
        // a work-hours user's is queued with a delay (see withDelay()).
        if ($notifiable->hasEmailNotificationsEnabled($this->getNotificationType())
            && $notifiable->getDelayedSendingMode() !== 'daily_digest') {
            $channels[] = 'mail';
        }

        if ($notifiable->hasPushNotificationsEnabledForType($this->getNotificationType())) {
            $channels = array_merge($channels, $this->pushChannelsFor($notifiable));
        }

        // A repeat of an incident that is still open only adds to the in-app
        // record's occurrence count: no second push or email.
        if ($this->isIncidentAlert() && $this->incidentIsOpen($notifiable)) {
            return ['database'];
        }

        return $channels;
    }

    /**
     * Failure alerts whose push and email go out once per incident and never
     * overnight (decision N-1). An incident is the open, unarchived
     * notification sharing this group key; it closes when the failure
     * resolves.
     */
    public function isIncidentAlert(): bool
    {
        return false;
    }

    /**
     * An incident alert held overnight is dropped if the incident resolved
     * before morning.
     */
    public function shouldSend(User $notifiable, string $channel): bool
    {
        return ! $this->heldOvernight || $this->incidentIsOpen($notifiable);
    }

    /**
     * Get the web push representation of the notification
     */
    public function toWebPush(User $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->getTitle())
            ->icon('/icons/Spark-iOS-Default-60x60@3x.png')
            ->body($this->getMessage())
            ->badge('/favicon.ico')
            ->tag($this->getNotificationType())
            ->data([
                'url' => $this->getActionUrl() ?? url('/'),
                'type' => $this->getNotificationType(),
                'notification_id' => $notification->id ?? null,
            ])
            ->options([
                'TTL' => 86400, // 24 hours
                'urgency' => $this->isPriority() ? 'high' : 'normal',
            ]);
    }

    /**
     * Get the APNs representation of the notification
     */
    public function toApn(User $notifiable): ApnMessage
    {
        return ApnMessage::create()
            ->title($this->getTitle())
            ->body($this->getMessage())
            ->sound('default');
    }

    /**
     * Get notification icon for UI display
     */
    public function getIcon(): string
    {
        return 'fas.bell';
    }

    /**
     * Get notification color for UI display
     */
    public function getColor(): string
    {
        return 'primary';
    }

    /**
     * Get the notification title for UI display
     */
    abstract public function getTitle(): string;

    /**
     * Get the notification message for UI display
     */
    abstract public function getMessage(): string;

    /**
     * Get the notification action URL (optional)
     */
    public function getActionUrl(): ?string
    {
        return null;
    }

    public function databaseType(User $notifiable): string
    {
        return $this->getNotificationType();
    }

    public function getEntityType(): ?string
    {
        return null;
    }

    public function getEntityId(): ?string
    {
        return null;
    }

    public function getDeepLink(): ?string
    {
        $type = $this->getEntityType();
        $id = $this->getEntityId();

        return $type !== null && $id !== null ? "{$type}:{$id}" : null;
    }

    public function getGroupKey(): ?string
    {
        return null;
    }

    public function getTechnicalDetail(): ?string
    {
        return null;
    }

    /**
     * Get the array representation of the notification for database storage
     */
    public function toArray(User $notifiable): array
    {
        return [
            'contract_version' => 1,
            'type' => $this->getNotificationType(),
            'stream' => NotificationCatalogue::streamFor($this->getNotificationType()),
            'severity' => NotificationCatalogue::severityFor($this->getNotificationType()),
            'title' => $this->getTitle(),
            'body' => $this->getMessage(),
            'message' => $this->getMessage(),
            'icon' => $this->getIcon(),
            'color' => $this->getColor(),
            'action_url' => $this->getActionUrl(),
            'deep_link' => $this->getDeepLink(),
            'entity_type' => $this->getEntityType(),
            'entity_id' => $this->getEntityId(),
            'group_key' => $this->getGroupKey(),
            'technical_detail' => $this->getTechnicalDetail(),
            'occurrence_count' => 1,
            'last_occurred_at' => $this->occurredAt ??= now()->toJSON(),
            'priority' => $this->isPriority(),
        ];
    }

    /**
     * Per-channel queue delay.
     *
     * Outside a work-hours user's window, non-priority email waits for the
     * window to open. It used to be dropped from the channel list instead, so
     * "Delay non-urgent notifications until your work hours" sent nothing.
     */
    public function withDelay(User $notifiable, string $channel): ?Carbon
    {
        $this->deliveryChannel = $channel;
        $this->deliveryUserId = $notifiable->getKey();

        if ($channel === 'database' || $this->isPriority()) {
            return null;
        }

        $overnightEnd = $this->isIncidentAlert() ? $this->overnightEnd($notifiable) : null;
        $this->heldOvernight = $overnightEnd !== null;
        $workHoursStart = $channel === 'mail' ? $this->nextWorkHoursStart($notifiable) : null;

        return match (true) {
            $overnightEnd === null => $workHoursStart,
            $workHoursStart === null => $overnightEnd,
            default => $overnightEnd->max($workHoursStart),
        };
    }

    /**
     * Record a queued delivery that gave up on the in-app notification.
     */
    public function failed(Throwable $exception): void
    {
        $notifiable = $this->deliveryUserId !== null ? User::find($this->deliveryUserId) : null;
        if ($notifiable === null || $this->deliveryChannel === null) {
            return;
        }

        app(NotificationDeliveryRecorder::class)->failed(
            $notifiable,
            $this,
            $this->deliveryChannel,
            Str::limit($this->sanitiseTechnicalDetail(class_basename($exception) . ': ' . $exception->getMessage()), 300),
        );
    }

    /** When the user's night ends, or null if it is daytime for them now. */
    protected function overnightEnd(User $notifiable): ?Carbon
    {
        $now = now()->timezone($notifiable->getTimezone());
        $time = $now->format('H:i');
        if ($time >= self::OVERNIGHT_END && $time < self::OVERNIGHT_START) {
            return null;
        }

        $end = $now->copy()->setTimeFromTimeString(self::OVERNIGHT_END);
        if ($end->lte($now)) {
            $end->addDay();
        }

        return $end->utc();
    }

    protected function sanitiseTechnicalDetail(string $detail): string
    {
        $redacted = redact_sensitive_urls(strip_tags($detail));
        $redacted = preg_replace(
            '/(?i)"?(token|access_token|refresh_token|api_key|key|password|secret)"?\s*[:=]\s*"?([^&\s"\'<>]+)"?/',
            '$1=[REDACTED]',
            $redacted,
        ) ?? $redacted;
        $redacted = preg_replace('/(?i)bearer\s+[a-z0-9._~+\/-]+=*/', 'Bearer [REDACTED]', $redacted) ?? $redacted;

        return Str::limit($redacted, 2_000);
    }

    /**
     * Inspect the user's push subscriptions and return the set of push
     * channels they have a device registered for. De-duplicated so each
     * channel is only listed once, regardless of subscription count.
     *
     * @return array<int, class-string>
     */
    protected function pushChannelsFor(User $notifiable): array
    {
        $channels = [];
        if ($notifiable->pushSubscriptions()->apns()->exists()) {
            $channels[] = ApnsChannel::class;
        }

        if ($notifiable->pushSubscriptions()->validWebPush()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * When the user's next work-hours window opens, or null if email should
     * go now (immediate mode, work hours disabled, or already in the window).
     */
    protected function nextWorkHoursStart(User $notifiable): ?Carbon
    {
        if ($notifiable->getDelayedSendingMode() !== 'work_hours' || $notifiable->isInWorkHours()) {
            return null;
        }

        $workHours = $notifiable->getNotificationPreferences()['work_hours'];
        $now = now()->timezone($workHours['timezone'] ?? 'UTC');
        $start = $now->copy()->setTimeFromTimeString($workHours['start'] ?? '09:00');

        if ($start->lte($now)) {
            $start->addDay();
        }

        return $start->utc();
    }

    private function incidentIsOpen(User $notifiable): bool
    {
        $groupKey = $this->getGroupKey();

        return $groupKey !== null && $notifiable->notifications()
            ->whereNull('archived_at')
            ->where('group_key', $groupKey)
            ->exists();
    }
}
