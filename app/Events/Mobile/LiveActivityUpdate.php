<?php

namespace App\Events\Mobile;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushes a state delta for a Live Activity (sleep, run, etc.) so the iOS
 * client can patch its local model without round-tripping the API. The
 * companion APN HTTP/2 push to the activity push token is dispatched
 * separately by ApnsLiveActivityService.
 */
class LiveActivityUpdate implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $state
     */
    public function __construct(
        public string $userId,
        public string $activityId,
        public string $activityType,
        public string $event,
        public array $state = [],
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
        return 'live_activity.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'activity_id' => $this->activityId,
            'activity_type' => $this->activityType,
            'event' => $this->event,
            'state' => $this->state,
        ];
    }
}
