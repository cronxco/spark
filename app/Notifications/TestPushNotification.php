<?php

namespace App\Notifications;

use App\Models\User;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class TestPushNotification extends SparkNotification
{
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
     * Only send via push (not database or email)
     */
    public function via(User $notifiable): array
    {
        return [WebPushChannel::class];
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
                'TTL' => 300, // 5 minutes
                'urgency' => 'normal',
            ]);
    }
}
