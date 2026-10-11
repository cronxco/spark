<?php

namespace App\Services\Knowledge;

use App\Models\Block;
use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OutlineTaskReconciler
{
    public function reconcile(Event $event, array $incoming, string $documentId): void
    {
        DB::transaction(function () use ($event, $incoming, $documentId) {
            Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            $existing = Block::withTrashed()->whereIn('block_type', ['day_task', 'doc_task'])
                ->where('metadata->outline_document_id', $documentId)
                ->whereHas('event.integration', fn ($q) => $q->where('user_id', $event->integration->user_id))->lockForUpdate()->get();
            $seen = [];
            // Without markers, a changed group of identical titles cannot safely
            // identify which occurrence moved or was removed. Preserve local IDs.
            $markedIds = collect($incoming)->pluck('metadata.outline_task_id')->filter()->all();
            foreach ($existing->reject(fn ($b) => in_array((string) $b->id, $markedIds, true))->groupBy(fn ($b) => data_get($b->metadata, 'outline_source_title') ?? $b->title) as $title => $group) {
                $unmarked = collect($incoming)->filter(fn ($data) => $data['title'] === $title && empty($data['metadata']['outline_task_id']));
                if ($group->count() > 1 && $unmarked->isNotEmpty()
                    && ($group->count() !== $unmarked->count()
                        || $group->pluck('metadata.outline_source_line')->sort()->values()->all() !== $unmarked->pluck('metadata.outline_source_line')->sort()->values()->all())) {
                    foreach ($group as $block) {
                        $seen[(string) $block->id] = true;
                        $this->conflict($block, 'ambiguous_source_identity');
                    }
                }
            }
            foreach ($incoming as $data) {
                $m = $data['metadata'];
                if (empty($m['outline_task_id']) && $existing->contains(fn ($b) => isset($seen[(string) $b->id]) && data_get($b->metadata, 'task.sync.reason') === 'ambiguous_source_identity' && (data_get($b->metadata, 'outline_source_title') ?? $b->title) === $data['title'])) {
                    continue;
                }
                $candidates = $existing->filter(fn ($b) => ! isset($seen[(string) $b->id]) && (
                    (! empty($m['outline_task_id']) && (string) $b->id === $m['outline_task_id'])
                    || (! empty($m['outline_task_key']) && data_get($b->metadata, 'outline_task_key') === $m['outline_task_key'])
                    || data_get($b->metadata, 'hash') === $m['hash']));
                if ($candidates->isEmpty() && empty($m['outline_task_id'])) {
                    $byTitle = $existing->filter(fn ($b) => ! isset($seen[(string) $b->id]) && (data_get($b->metadata, 'outline_source_title') ?? $b->title) === $data['title']);
                    if ($byTitle->count() === 1 && collect($incoming)->where('title', $data['title'])->count() === 1) {
                        $candidates = $byTitle;
                    }
                }
                if ($candidates->count() > 1) {
                    foreach ($candidates as $b) {
                        $seen[(string) $b->id] = true;
                        $this->conflict($b, 'ambiguous_source_identity');
                    }

                    continue;
                }
                $block = $candidates->first();
                if (! $block) {
                    // Independent carriers avoid the parent event/title uniqueness constraint.
                    $carrier = $event;
                    if ($event->blocks()->where('block_type', $data['block_type'])->where('title', $data['title'])->exists()) {
                        $carrier = Event::create(['integration_id' => $event->integration_id, 'source_id' => 'outline_task:' . Str::uuid(),
                            'actor_id' => $event->actor_id, 'target_id' => $event->target_id, 'service' => 'outline', 'domain' => 'knowledge',
                            'action' => 'had_task', 'time' => $event->time, 'event_metadata' => ['internal' => true, 'outline_parent_event_id' => (string) $event->id]]);
                    }
                    $block = Block::create($data + ['event_id' => $carrier->id]);
                    $existing->push($block);
                } else {
                    $pending = in_array(data_get($block->metadata, 'task.sync.status'), ['pending', 'blocked', 'conflict', 'error'], true);
                    if ($pending) {
                        $seen[(string) $block->id] = true;
                        if (data_get($block->metadata, 'outline_source_line') !== $m['outline_source_line']) {
                            $this->conflict($block, 'source_changed');
                        }

                        continue;
                    }
                    if ($block->trashed() && ! data_get($block->metadata, 'removed')) {
                        $seen[(string) $block->id] = true;

                        continue;
                    }
                    if ($block->trashed()) {
                        $block->restore();
                    }
                    $metadata = array_merge($block->metadata ?? [], $m, ['removed' => false, 'removed_at' => null]);
                    if (isset($metadata['task'])) {
                        $task = $metadata['task'];
                        $task['content'] = $m['content'] ?? null;
                        if (($m['checked'] ?? false) !== (data_get($block->metadata, 'checked') ?? false)) {
                            $task['status'] = $m['checked'] ? 'completed' : 'open';
                            $task['completed_at'] = $m['checked'] ? now()->toIso8601String() : null;
                        }
                        $metadata['task'] = $task;
                    }
                    app(TaskService::class)->isolateTitleCollision($block, $data['title'], $data['block_type']);
                    $block->update(['title' => $data['title'], 'block_type' => $data['block_type'], 'time' => $data['time'], 'metadata' => $metadata, 'url' => $data['url']]);
                }
                $seen[(string) $block->id] = true;
            }
            foreach ($existing as $block) {
                if (isset($seen[(string) $block->id]) || $block->trashed()) {
                    continue;
                }
                if (in_array(data_get($block->metadata, 'task.sync.status'), ['pending', 'blocked', 'conflict', 'error'], true)) {
                    $this->conflict($block, 'source_removed');

                    continue;
                }
                $m = $block->metadata;
                $m['removed'] = true;
                $m['removed_at'] = now()->toIso8601String();
                $block->update(['metadata' => $m]);
                $block->delete();
            }
        });
    }

    private function conflict(Block $block, string $reason): void
    {
        $m = $block->metadata;
        $m['task'] ??= app(TaskService::class)->state($block);
        $m['task']['sync'] = array_merge($m['task']['sync'] ?? [], ['status' => 'conflict', 'reason' => $reason]);
        $block->update(['metadata' => $m]);
    }
}
