<?php

namespace App\Events\Mobile;

use App\Models\Event;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast on new Event creation so the iOS client can refresh feed.
 * Throttled at the dispatch site (Redis 2s key per user) to avoid flooding
 * subscribers when a high-volume integration ingests a burst of events.
 */
class NewEventBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $userId,
        public string $eventId,
        public string $service,
        public string $domain,
        public string $action,
    ) {}

    public static function fromEvent(Event $event, string $userId): self
    {
        return new self(
            userId: $userId,
            eventId: (string) $event->id,
            service: (string) $event->service,
            domain: (string) $event->domain,
            action: (string) $event->action,
        );
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.' . $this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'event.created';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->eventId,
            'service' => $this->service,
            'domain' => $this->domain,
            'action' => $this->action,
        ];
    }
}
