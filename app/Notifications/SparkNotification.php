<?php

namespace App\Notifications;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\ApnsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnMessage;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

abstract class SparkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Get the notification type identifier for preferences
     */
    abstract public function getNotificationType(): string;

    /**
     * Get the notification priority
     * Priority notifications always send immediately via all channels
     */
    public function isPriority(): bool
    {
        return false;
    }

    /**
     * Get the notification's delivery channels
     */
    public function via(User $notifiable): array
    {
        $channels = ['database'];

        if ($this->isPriority()) {
            $channels[] = 'mail';

            return array_merge($channels, $this->pushChannelsFor($notifiable));
        }

        if ($notifiable->hasEmailNotificationsEnabled($this->getNotificationType())) {
            if (! $this->shouldDelayEmail($notifiable)) {
                $channels[] = 'mail';
            }
        }

        if ($notifiable->hasPushNotificationsEnabledForType($this->getNotificationType())) {
            $channels = array_merge($channels, $this->pushChannelsFor($notifiable));
        }

        return $channels;
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

    /**
     * Get the array representation of the notification for database storage
     */
    public function toArray(User $notifiable): array
    {
        return [
            'type' => $this->getNotificationType(),
            'title' => $this->getTitle(),
            'message' => $this->getMessage(),
            'icon' => $this->getIcon(),
            'color' => $this->getColor(),
            'action_url' => $this->getActionUrl(),
            'priority' => $this->isPriority(),
        ];
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
        $deviceTypes = $notifiable->pushSubscriptions()
            ->pluck('device_type')
            ->map(fn ($type) => $type ?: PushSubscription::DEVICE_TYPE_WEB)
            ->unique();

        $channels = [];
        foreach ($deviceTypes as $type) {
            if ($type === PushSubscription::DEVICE_TYPE_IOS) {
                $channels[] = ApnsChannel::class;
            } else {
                $channels[] = WebPushChannel::class;
            }
        }

        return array_values(array_unique($channels));
    }

    /**
     * Determine if email should be delayed based on user preferences
     */
    protected function shouldDelayEmail(User $notifiable): bool
    {
        $mode = $notifiable->getDelayedSendingMode();

        // Always send immediately
        if ($mode === 'immediate') {
            return false;
        }

        // Send in daily digest
        if ($mode === 'daily_digest') {
            return true;
        }

        // Send only during work hours
        if ($mode === 'work_hours') {
            return ! $notifiable->isInWorkHours();
        }

        return false;
    }
}
