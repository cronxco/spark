<?php

namespace App\Jobs\Knowledge;

use App\Models\Event;
use App\Notifications\TaskReminder;
use App\Services\Knowledge\TaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SendTaskReminder implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $receiptId) {}

    public function handle(TaskService $tasks): void
    {
        DB::transaction(function () use ($tasks) {
            $receipt = Event::whereKey($this->receiptId)->lockForUpdate()->first();
            if (! $receipt || data_get($receipt->event_metadata, 'status') !== 'pending') {
                return;
            }
            $user = $receipt->integration?->user;
            $block = $user ? $tasks->query($user)->find(data_get($receipt->event_metadata, 'task_id')) : null;
            $m = $receipt->event_metadata;
            if ($block) {
                $notification = new TaskReminder((string) $block->id, $m['remind_at'], $block->title);
                $notification->id = (string) $receipt->id;
                if ($notification->shouldSend($user, 'database')) {
                    $user->notify($notification);
                    $m['status'] = 'queued';
                } else {
                    $m['status'] = 'cancelled';
                }
            } else {
                $m['status'] = 'cancelled';
            }
            $receipt->update(['event_metadata' => $m]);
        });
    }
}
