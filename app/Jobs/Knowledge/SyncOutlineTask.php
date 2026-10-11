<?php

namespace App\Jobs\Knowledge;

use App\Integrations\Outline\OutlineApi;
use App\Integrations\Outline\OutlineRevisionConflict;
use App\Models\Block;
use App\Services\Knowledge\KnowledgeFeature;
use App\Services\Knowledge\TaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SyncOutlineTask implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $blockId) {}

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(TaskService $tasks): void
    {
        $initial = Block::withTrashed()->find($this->blockId);
        if (! $initial || data_get($initial->metadata, 'task.sync.status') !== 'pending') {
            return;
        }
        $docId = data_get($initial->metadata, 'outline_document_id');
        Cache::lock('outline-task-document:'.$initial->event->integration_id.':'.$docId, 120)->block(5, function () use ($tasks, $docId) {
            DB::transaction(function () use ($tasks, $docId) {
                $block = Block::withTrashed()->whereKey($this->blockId)->lockForUpdate()->firstOrFail();
                $task = $tasks->state($block);
                $m = $block->metadata;
                if (data_get($task, 'sync.status') !== 'pending') {
                    return;
                }
                if (! config('knowledge.outline_revision_writes_verified') || ! KnowledgeFeature::enabled($block->event->integration->user, 'outline_sync')) {
                    $this->stop($block, $tasks, $task, 'blocked', 'outline_conditional_patch_unverified');

                    return;
                }
                $api = new OutlineApi($block->event->integration);
                $result = $api->getDocument($docId);
                $doc = $result['data'] ?? $result;
                if (! isset($doc['revision'], $doc['text'])) {
                    $this->stop($block, $tasks, $task, 'conflict', 'source_unversioned');

                    return;
                }
                $lines = preg_split('/\r\n|\r|\n/', $doc['text']);
                $marker = '[Open in Spark]('.route('knowledge.task', $block->id).')';
                $expected = $m['outline_source_line'] ?? null;
                preg_match('/^(\s*)-/', $expected ?? '', $indent);
                $replacement = ($indent[1] ?? '').'- ['.($task['sync']['desired_checked'] ? 'x' : ' ').'] '.$task['sync']['desired_title'].' '.$marker;
                $prefix = ($indent[1] ?? '').'  > ';
                $details = empty($task['sync']['desired_content']) ? [] : array_map(fn ($line) => $prefix.$line, preg_split('/\r\n|\r|\n/', $task['sync']['desired_content']));
                $desired = implode("\n", array_merge([$replacement], $details));
                $deleting = $task['sync']['desired_deleted'];
                // A prior attempt may have committed remotely before its response was lost.
                if (! $deleting && substr_count($doc['text'], $desired) === 1) {
                    $this->acknowledge($block, $task, $replacement, $details);

                    return;
                }
                $oldDetails = $m['outline_source_details'] ?? [];
                $fragment = $expected ? implode("\n", array_merge([$expected], $oldDetails)) : null;
                $deleted = $m['outline_source_deleted'] ?? false;
                try {
                    if ($deleted && ! $deleting) {
                        $neighbors = $m['outline_deleted_neighbors'] ?? [];
                        $previous = $neighbors['previous'] ?? null;
                        $next = $neighbors['next'] ?? null;
                        $anchor = implode("\n", array_filter([$previous, $next], fn ($line) => $line !== null));
                        if ($anchor === '' && $doc['text'] === '') {
                            $api->updateDocumentAtRevision($docId, $desired, (int) $doc['revision']);
                        } elseif ($anchor !== '' && substr_count($doc['text'], $anchor) === 1) {
                            $restored = implode("\n", array_filter([$previous, $desired, $next], fn ($line) => $line !== null));
                            $api->patchDocumentAtRevision($docId, $anchor, $restored, (int) $doc['revision']);
                        } else {
                            $this->stop($block, $tasks, $task, 'conflict', 'restore_anchor_changed');

                            return;
                        }
                    } else {
                        if (! $fragment || substr_count($doc['text'], $fragment) !== 1) {
                            $this->stop($block, $tasks, $task, 'conflict', 'source_missing_changed_or_ambiguous');

                            return;
                        }
                        // Save the neighbor anchor before deletion, so Undo never guesses a location.
                        $lineIndex = array_search($expected, $lines, true);
                        if ($deleting) {
                            $m['outline_deleted_neighbors'] = ['previous' => $lineIndex > 0 ? $lines[$lineIndex - 1] : null, 'next' => $lines[$lineIndex + 1 + count($oldDetails)] ?? null];
                            $block->update(['metadata' => $m]);
                        }
                        $api->patchDocumentAtRevision($docId, $fragment, $deleting ? '' : $desired, (int) $doc['revision']);
                    }
                } catch (OutlineRevisionConflict $e) {
                    $this->stop($block, $tasks, $task, 'conflict', 'remote_revision_changed');

                    return;
                }
                $this->acknowledge($block, $task, $replacement, $details, $deleting);
            });
        });
    }

    private function stop(Block $block, TaskService $tasks, array $task, string $status, string $reason): void
    {
        $before = $tasks->state($block);
        $task['sync']['status'] = $status;
        $task['sync']['reason'] = $reason;
        $tasks->save($block, $task, $block->title);
        $tasks->record($block, $block->event->integration->user, 'sync_'.$status, $before, $task);
    }

    private function acknowledge(Block $block, array $task, string $line, array $details, bool $deleted = false): void
    {
        $m = $block->metadata;
        $tasks = app(TaskService::class);
        $before = $tasks->state($block);
        $task['sync']['status'] = 'synced';
        $task['sync']['reason'] = null;
        $m['outline_source_line'] = $line;
        $m['outline_source_details'] = $details;
        $m['outline_source_title'] = $task['sync']['desired_title'];
        $m['outline_source_deleted'] = $deleted;
        $m['task'] = $task;
        $block->update(['metadata' => $m]);
        $tasks->save($block, $task, $block->title);
        $tasks->record($block, $block->event->integration->user, 'source_synced', $before, $task);
    }

    public function failed(\Throwable $exception): void
    {
        $block = Block::withTrashed()->find($this->blockId);
        if ($block && data_get($block->metadata, 'task.sync.status') === 'pending') {
            $m = $block->metadata;
            $m['task']['sync']['status'] = 'error';
            $m['task']['sync']['reason'] = 'write_failed';
            $block->update(['metadata' => $m]);
        }
    }
}
