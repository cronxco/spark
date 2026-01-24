<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class AdminPushNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $title,
        protected string $message,
        protected ?string $url = null,
    ) {}

    public function via(User $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(User $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->icon('/icons/Spark-iOS-Default-60x60@3x.png')
            ->body($this->message)
            ->badge('/favicon.ico')
            ->tag('admin-notification-'.time())
            ->data([
                'url' => $this->url ?? url('/'),
                'type' => 'admin_broadcast',
            ])
            ->options([
                'TTL' => 86400, // 24 hours
                'urgency' => 'high',
            ]);
    }
}
