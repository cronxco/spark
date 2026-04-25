<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\ApnsChannel;
use NotificationChannels\Apn\ApnMessage;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class TestPushNotification extends SparkNotification
{
    public function __construct(public string $platform = 'all') {}

    public function getNotificationType(): string
    {
        return 'test_push';
    }

    public function getTitle(): string
    {
        return 'Test Notification';
    }

    public function getMessage(): string
    {
        return 'Push notifications are working correctly!';
    }

    public function getIcon(): string
    {
        return 'fas.bell';
    }

    public function getColor(): string
    {
        return 'success';
    }

    public function getActionUrl(): ?string
    {
        return route('settings.notifications');
    }

    /**
     * Route via the requested platform(s); never touches database/email.
     */
    public function via(User $notifiable): array
    {
        $channels = [];

        if (in_array($this->platform, ['web', 'all'], true)
            && $notifiable->pushSubscriptions()->where('device_type', 'web')->exists()) {
            $channels[] = WebPushChannel::class;
        }

        if (in_array($this->platform, ['ios', 'all'], true)
            && $notifiable->pushSubscriptions()->apns()->exists()) {
            $channels[] = ApnsChannel::class;
        }

        return $channels;
    }

    public function toWebPush(User $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->getTitle())
            ->icon('/icons/Spark-iOS-Default-60x60@3x.png')
            ->body($this->getMessage())
            ->badge('/favicon.ico')
            ->tag('test-notification')
            ->data([
                'url' => $this->getActionUrl(),
                'type' => $this->getNotificationType(),
            ])
            ->options([
                'TTL' => 300,
                'urgency' => 'normal',
            ]);
    }

    public function toApn(User $notifiable): ApnMessage
    {
        return ApnMessage::create()
            ->title($this->getTitle())
            ->body($this->getMessage())
            ->sound('default')
            ->badge(1);
    }
}
