<?php

namespace App\Events\Mobile;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Mirror of a freshly-stored database notification, broadcast to the iOS
 * client so the in-app inbox can update without a poll.
 */
class NotificationReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $userId,
        public string $notificationId,
        public string $type,
        public ?string $title = null,
        public ?string $body = null,
        public ?string $deepLink = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.' . $this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.received';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notificationId,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'deep_link' => $this->deepLink,
        ];
    }
}
