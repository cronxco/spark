<?php

namespace Tests\Feature\Mobile\Stubs;

use App\Models\User;
use App\Notifications\SparkNotification;
use NotificationChannels\Apn\ApnMessage;

class Phase0SmokeNotification extends SparkNotification
{
    public function getNotificationType(): string
    {
        return 'phase0_smoke';
    }

    public function isPriority(): bool
    {
        return true;
    }

    public function getTitle(): string
    {
        return 'Smoke';
    }

    public function getMessage(): string
    {
        return 'Smoke test';
    }

    public function toApn(User $notifiable): ApnMessage
    {
        return ApnMessage::create()->title('Smoke')->body('Smoke');
    }
}
