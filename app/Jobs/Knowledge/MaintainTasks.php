<?php

namespace App\Jobs\Knowledge;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Services\Knowledge\KnowledgeFeature;
use App\Services\Knowledge\TaskLifecycle;
use App\Services\Knowledge\TaskRecurrenceService;
use App\Services\Knowledge\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class MaintainTasks implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public int $timeout = 300;

    public function handle(TaskService $tasks, TaskLifecycle $lifecycle, TaskRecurrenceService $recurrence): void
    {
        EventObject::where('concept', 'knowledge')->where('type', 'task_series')->where('metadata->status', 'active')->chunkById(100, function ($rows) use ($recurrence) {
            foreach ($rows as $series) {
                $recurrence->generate($series);
            }
        });
        Block::whereIn('block_type', TaskService::TYPES)->whereNotNull('metadata->task')
            ->where(fn ($q) => $q->where('metadata->task->status', 'open')->orWhere('metadata->task->sync->status', 'pending'))
            ->with('event.integration.user')->chunkById(100, function ($rows) use ($tasks, $lifecycle) {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, $tasks, $lifecycle) {
                        $block = Block::whereKey($row->id)->lockForUpdate()->first();
                        if (! $block) {
                            return;
                        }
                        if ($block->event_id === $row->event_id) {
                            $block->setRelation('event', $row->event);
                        }
                        $user = $block->event?->integration?->user;
                        if (! $user) {
                            return;
                        }
                        $task = $tasks->state($block);
                        $date = CarbonImmutable::now($task['schedule_timezone'])->toDateString();
                        if (($task['status'] ?? '') === 'open' && $lifecycle->status($task, $date) === 'expired') {
                            $before = $task;
                            $task['status'] = 'expired';
                            $task['expired_at'] = now()->toIso8601String();
                            $tasks->save($block, $task, $block->title);
                            $tasks->record($block, $user, 'expired', $before, $task);
                        }
                        if (data_get($task, 'sync.status') === 'pending') {
                            DB::afterCommit(fn () => SyncOutlineTask::dispatch((string) $block->id)->onQueue('pull'));
                        }
                        if (! KnowledgeFeature::enabled($user, 'reminders') || ($task['status'] ?? '') !== 'open' || empty($task['remind_at']) || CarbonImmutable::parse($task['remind_at'])->isFuture()) {
                            return;
                        }
                        $source = 'task_reminder:' . $block->id . ':' . hash('sha256', $task['remind_at']);
                        $receipt = Event::firstOrCreate(['integration_id' => $block->event->integration_id, 'source_id' => $source],
                            ['actor_id' => $block->event->actor_id, 'target_id' => $block->event->target_id, 'service' => 'flint', 'domain' => 'knowledge',
                                'action' => 'task_reminder', 'time' => now(), 'event_metadata' => ['internal' => true, 'task_id' => (string) $block->id, 'remind_at' => $task['remind_at'], 'status' => 'pending']]);
                        if (data_get($receipt->event_metadata, 'status') === 'pending') {
                            DB::afterCommit(fn () => SendTaskReminder::dispatch((string) $receipt->id)->onQueue('notifications'));
                        }
                    });
                }
            });
    }
}
