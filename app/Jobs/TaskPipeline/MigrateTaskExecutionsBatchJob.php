<?php

namespace App\Jobs\TaskPipeline;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\TaskExecution;
use App\Services\TaskPipeline\TaskExecutionStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class MigrateTaskExecutionsBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(
        public string $modelType,
        public ?string $cursor,
        public int $batchSize = 500,
        public ?int $limit = null,
        public bool $force = false,
        public array $stats = ['scanned' => 0, 'migrated' => 0, 'skipped' => 0, 'missing_owner' => 0, 'invalid_metadata' => 0],
    ) {
        $this->onConnection('redis');
        $this->onQueue('migration');
    }

    public function handle(TaskExecutionStore $store): void
    {
        $modelClass = $this->modelClass();
        $remaining = $this->limit ? max(0, $this->limit - $this->stats['scanned']) : $this->batchSize;

        if ($remaining === 0) {
            $this->logProgress(done: true);

            return;
        }

        $take = min($this->batchSize, $remaining);
        $query = $modelClass::query()->orderBy('id')->limit($take);

        if ($this->cursor) {
            $query->where('id', '>', $this->cursor);
        }

        $models = $query->get();

        foreach ($models as $model) {
            $this->stats['scanned']++;
            $this->processModel($store, $model);
        }

        $this->cursor = $models->last()?->getKey();
        $this->logProgress(done: $models->count() < $take);

        if ($models->count() === $take && (! $this->limit || $this->stats['scanned'] < $this->limit)) {
            static::dispatch(
                modelType: $this->modelType,
                cursor: $this->cursor,
                batchSize: $this->batchSize,
                limit: $this->limit,
                force: $this->force,
                stats: $this->stats,
            )->onConnection('redis')->onQueue('migration');
        }
    }

    protected function processModel(TaskExecutionStore $store, Model $model): void
    {
        $legacy = $store->getLegacyTaskExecutions($model);

        if ($legacy === []) {
            $this->stats['skipped']++;

            return;
        }

        if (! is_array($legacy)) {
            $this->stats['invalid_metadata']++;

            return;
        }

        if (! $store->resolveUserId($model)) {
            $this->stats['missing_owner']++;

            return;
        }

        foreach ($legacy as $taskKey => $execution) {
            if (! is_string($taskKey) || ! is_array($execution)) {
                $this->stats['invalid_metadata']++;

                continue;
            }

            if (! $this->force && TaskExecution::query()
                ->forEntity($this->modelType, (string) $model->getKey())
                ->where('task_key', $taskKey)
                ->exists()) {
                $this->stats['skipped']++;

                continue;
            }

            if ($store->upsertFromLegacy($model, $taskKey, $execution, $this->force) !== null) {
                $this->stats['migrated']++;
            }
        }
    }

    protected function logProgress(bool $done): void
    {
        Log::info('Task execution migration batch ' . ($done ? 'completed' : 'progress'), [
            'model_type' => $this->modelType,
            'cursor' => $this->cursor,
            'batch_size' => $this->batchSize,
            'limit' => $this->limit,
            'force' => $this->force,
            'stats' => $this->stats,
        ]);
    }

    /**
     * @return class-string<Model>
     */
    protected function modelClass(): string
    {
        return match ($this->modelType) {
            'event' => Event::class,
            'block' => Block::class,
            'object' => EventObject::class,
            'integration' => Integration::class,
            default => throw new InvalidArgumentException("Unsupported model type [{$this->modelType}]"),
        };
    }
}
