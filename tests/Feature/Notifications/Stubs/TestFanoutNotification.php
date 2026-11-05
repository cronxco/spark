<?php

namespace Tests\Feature\Notifications\Stubs;

use App\Models\User;
use App\Notifications\SparkNotification;
use NotificationChannels\Apn\ApnMessage;

class TestFanoutNotification extends SparkNotification
{
    public function getNotificationType(): string
    {
        return 'test_fanout';
    }

    public function getTitle(): string
    {
        return 'Test';
    }

    public function getMessage(): string
    {
        return 'Test message';
    }

    public function isPriority(): bool
    {
        return true;
    }

    public function toApn(User $notifiable): ApnMessage
    {
        return ApnMessage::create()->title('Test')->body('Test');
    }
}
