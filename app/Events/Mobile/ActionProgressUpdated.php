<?php

namespace App\Events\Mobile;

use App\Models\ActionProgress;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever an ActionProgress row is created or updated, so the iOS
 * client can render a live progress bar for long-running operations.
 */
class ActionProgressUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $userId,
        public string $actionProgressId,
        public string $actionType,
        public string $actionId,
        public string $step,
        public ?string $message,
        public int $progress,
        public int $total,
        public bool $completed,
        public bool $failed,
    ) {}

    public static function fromModel(ActionProgress $progress): self
    {
        return new self(
            userId: (string) $progress->user_id,
            actionProgressId: (string) $progress->id,
            actionType: (string) $progress->action_type,
            actionId: (string) $progress->action_id,
            step: (string) $progress->step,
            message: $progress->message,
            progress: (int) $progress->progress,
            total: (int) $progress->total,
            completed: ! is_null($progress->completed_at),
            failed: ! is_null($progress->failed_at),
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
        return 'action_progress.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->actionProgressId,
            'action_type' => $this->actionType,
            'action_id' => $this->actionId,
            'step' => $this->step,
            'message' => $this->message,
            'progress' => $this->progress,
            'total' => $this->total,
            'completed' => $this->completed,
            'failed' => $this->failed,
        ];
    }
}
