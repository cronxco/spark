<?php

namespace App\Console\Commands\Knowledge;

use App\Models\Block;
use App\Models\Event;
use App\Services\Knowledge\PayloadHash;
use App\Services\Knowledge\TaskService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillTasks extends Command
{
    protected $signature = 'knowledge:tasks-backfill {--user= : Limit to one owner} {--apply : Apply value-only changes; otherwise dry-run} {--rollback= : Migration batch UUID to roll back without overwriting later edits}';

    protected $description = 'Dry-run, apply or safely roll back the task metadata backfill. Does not change schema.';

    public function handle(TaskService $tasks): int
    {
        if ($batch = $this->option('rollback')) {
            return $this->rollback($batch);
        }
        $batch = (string) Str::uuid();
        $count = 0;
        $query = Block::whereIn('block_type', ['day_task', 'doc_task', 'flint_topic_task'])->whereNull('metadata->task')
            ->when($this->option('user'), fn ($q, $id) => $q->whereHas('event.integration', fn ($q) => $q->where('user_id', $id)));
        $query->chunkById(100, function ($rows) use ($tasks, $batch, &$count) {
            foreach ($rows as $row) {
                $count++;
                if (! $this->option('apply')) {
                    continue;
                }
                DB::transaction(function () use ($row, $tasks, $batch) {
                    $block = Block::whereKey($row->id)->lockForUpdate()->firstOrFail();
                    if (isset($block->metadata['task'])) {
                        return;
                    }
                    $before = $block->metadata;
                    $tasks->save($block, $tasks->state($block), $block->title);
                    Event::create(['integration_id' => $block->event->integration_id, 'actor_id' => $block->event->actor_id, 'target_id' => $block->event->target_id,
                        'source_id' => 'task_backfill:' . $batch . ':' . $block->id, 'service' => 'flint', 'domain' => 'knowledge', 'action' => 'task_backfilled', 'time' => now(),
                        'event_metadata' => ['internal' => true, 'migration_id' => $batch, 'task_id' => (string) $block->id, 'before_metadata' => $before,
                            'after_hash' => PayloadHash::of($block->metadata), 'after' => $tasks->state($block), 'title' => $block->title]]);
                });
            }
        });
        $this->info(($this->option('apply') ? 'Applied' : 'Dry run') . ": {$count} tasks; batch {$batch}");

        return self::SUCCESS;
    }

    private function rollback(string $batch): int
    {
        if (! Str::isUuid($batch)) {
            $this->error('Provide a batch UUID.');

            return self::FAILURE;
        }
        $conflicts = 0;
        $count = 0;
        Event::where('action', 'task_backfilled')->where('event_metadata->migration_id', $batch)
            ->when($this->option('user'), fn ($q, $id) => $q->whereHas('integration', fn ($q) => $q->where('user_id', $id)))
            ->chunkById(100, function ($rows) use (&$conflicts, &$count) {
                foreach ($rows as $event) {
                    DB::transaction(function () use ($event, &$conflicts, &$count) {
                        $block = Block::withTrashed()->whereKey(data_get($event->event_metadata, 'task_id'))->lockForUpdate()->first();
                        if (! $block) {
                            return;
                        }
                        $before = data_get($event->event_metadata, 'before_metadata');
                        if (PayloadHash::of($block->metadata) === PayloadHash::of($before)) {
                            return;
                        }
                        if (PayloadHash::of($block->metadata) !== data_get($event->event_metadata, 'after_hash')) {
                            $conflicts++;

                            return;
                        }
                        $block->update(['metadata' => $before]);
                        $count++;
                    });
                }
            });
        $this->info("Rolled back {$count}; skipped {$conflicts} subsequently changed tasks.");

        return $conflicts ? self::FAILURE : self::SUCCESS;
    }
}
