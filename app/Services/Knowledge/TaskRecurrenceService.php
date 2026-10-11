<?php

namespace App\Services\Knowledge;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class TaskRecurrenceService
{
    public function configure(User $user, Block $block, array $task): array
    {
        $series = ! empty($task['recurrence_series_id']) ? EventObject::where('user_id', $user->id)->where('type', 'task_series')->find($task['recurrence_series_id']) : null;
        if (empty($task['recurrence'])) {
            if ($series && data_get($series->metadata, 'root_task_id') === (string) $block->id) {
                $series->update(['metadata' => array_merge($series->metadata ?? [], ['status' => 'stopped'])]);
            }

            return $task;
        }
        if (empty($task['availability_on'])) {
            throw ValidationException::withMessages(['availability_on' => 'Repeating tasks need a first date.']);
        }
        $series ??= new EventObject(['user_id' => $user->id, 'concept' => 'knowledge', 'type' => 'task_series', 'title' => (string) $block->id, 'time' => now()]);
        $meta = $series->metadata ?? [];
        $series->metadata = array_merge($meta, ['internal' => true, 'status' => 'active', 'root_task_id' => (string) $block->id, 'frequency' => $task['recurrence'],
            'anchor_on' => $meta['anchor_on'] ?? $task['availability_on'], 'timezone' => $task['schedule_timezone']]);
        $series->save();
        $task['recurrence_series_id'] = (string) $series->id;

        return $task;
    }

    // Return an anchor-relative occurrence, retaining month-end intent.
    public function occurrence(string $anchor, string $frequency, int $index): string
    {
        $date = CarbonImmutable::parse($anchor);

        return match ($frequency) {
            'weekly' => $date->addWeeks($index)->toDateString(),
            'monthly' => $date->addMonthsNoOverflow($index)->toDateString(),
            default => $date->addDays($index)->toDateString(),
        };
    }

    public function generate(EventObject $series): void
    {
        $owner = User::find($series->user_id);
        if (! $owner || ! KnowledgeFeature::enabled($owner, 'recurrence') || ! KnowledgeFeature::enabled($owner, 'writes')) {
            return;
        }
        DB::transaction(function () use ($series) {
            User::whereKey($series->user_id)->lockForUpdate()->firstOrFail();
            $series = EventObject::whereKey($series->id)->lockForUpdate()->firstOrFail();
            $m = $series->metadata;
            if (($m['status'] ?? '') !== 'active') {
                return;
            }
            $tasks = app(TaskService::class);
            $user = User::findOrFail($series->user_id);
            $root = $tasks->query($user)->find($m['root_task_id']);
            if (! $root) {
                return;
            }
            $template = $tasks->payload($root);
            $today = CarbonImmutable::now($m['timezone'])->toDateString();
            $anchor = CarbonImmutable::parse($m['anchor_on']);
            $days = max(0, (int) $anchor->diffInDays(CarbonImmutable::parse($today)));
            $index = match ($m['frequency']) {
                'weekly' => intdiv($days, 7),
                'monthly' => max(0, (CarbonImmutable::parse($today)->year - $anchor->year) * 12 + CarbonImmutable::parse($today)->month - $anchor->month),
                default => $days,
            };
            if ($index > 0 && $this->occurrence($m['anchor_on'], $m['frequency'], $index) > $today) {
                $index--;
            }
            // Only current and next occurrence after downtime; never flood missed days.
            foreach (array_unique([max(1, $index), max(1, $index + 1)]) as $n) {
                $date = $this->occurrence($m['anchor_on'], $m['frequency'], $n);
                if ($date > CarbonImmutable::parse($today)->addDay()->toDateString()) {
                    continue;
                }
                $mutation = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'spark:series:'.$series->id.':'.$date);
                $existing = Event::whereHas('integration', fn ($q) => $q->where('user_id', $user->id))->where('source_id', 'knowledge_task:'.$mutation)->exists();
                if ($existing) {
                    continue;
                }
                $payload = $tasks->create($user, ['client_mutation_id' => $mutation, 'title' => $template['title'], 'content' => $template['content'],
                    'lifecycle' => $template['lifecycle'], 'availability_on' => $date, 'priority' => $template['priority'],
                    'project_id' => $template['project_id'], 'thread_ids' => $template['thread_ids'], 'context' => $template['context']]);
                $block = $tasks->find($user, $payload['id']);
                $task = $tasks->state($block);
                $task['origin'] = 'recurrence';
                $task['recurrence_series_id'] = (string) $series->id;
                $task['occurrence_on'] = $date;
                $tasks->save($block, $task, $block->title);
            }
        });
    }
}
