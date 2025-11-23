<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
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

        // Priority notifications always send via all channels
        if ($this->isPriority()) {
            $channels[] = 'mail';

            // Add push if user has any subscriptions
            if ($notifiable->pushSubscriptions()->exists()) {
                $channels[] = WebPushChannel::class;
            }

            return $channels;
        }

        // Check user preferences for email
        if ($notifiable->hasEmailNotificationsEnabled($this->getNotificationType())) {
            // Handle delayed sending based on work hours
            if (! $this->shouldDelayEmail($notifiable)) {
                $channels[] = 'mail';
            }
        }

        // Check user preferences for push notifications
        if ($notifiable->hasPushNotificationsEnabledForType($this->getNotificationType())) {
            if ($notifiable->pushSubscriptions()->exists()) {
                $channels[] = WebPushChannel::class;
            }
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
