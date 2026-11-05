<?php

namespace App\Console\Commands\TaskPipeline;

use App\Jobs\TaskPipeline\MigrateTaskExecutionsBatchJob;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Services\TaskPipeline\TaskExecutionStore;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

class MigrateTaskExecutionsCommand extends Command
{
    protected $signature = 'task-pipeline:migrate-executions
                            {--model= : Model type (event, block, object, integration)}
                            {--batch-size=500 : Number of records per batch}
                            {--limit= : Maximum records to scan per model type}
                            {--dry-run : Count records without dispatching jobs}
                            {--force : Overwrite existing task_executions rows}';

    protected $description = 'Backfill legacy task execution metadata into the task_executions table';

    public function handle(TaskExecutionStore $store): int
    {
        $modelTypes = $this->modelTypes();
        $batchSize = max(1, (int) $this->option('batch-size'));
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        if ($modelTypes === []) {
            $this->error('Invalid --model value. Use event, block, object, or integration.');

            return Command::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($modelTypes, $store, $limit);
        }

        foreach ($modelTypes as $modelType => $modelClass) {
            MigrateTaskExecutionsBatchJob::dispatch(
                modelType: $modelType,
                cursor: null,
                batchSize: $batchSize,
                limit: $limit,
                force: (bool) $this->option('force'),
            )->onConnection('redis')->onQueue('migration');

            $this->line("Dispatched {$modelType} task execution migration to redis/migration.");
        }

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, class-string<Model>>  $modelTypes
     */
    protected function dryRun(array $modelTypes, TaskExecutionStore $store, ?int $limit): int
    {
        $rows = [];

        foreach ($modelTypes as $modelType => $modelClass) {
            $scanned = 0;
            $withMetadata = 0;
            $missingOwner = 0;
            $invalidMetadata = 0;

            $query = $modelClass::query()->orderBy('id');
            if ($limit) {
                $query->limit($limit);
            }

            $query->get()->each(function (Model $model) use ($store, &$scanned, &$withMetadata, &$missingOwner, &$invalidMetadata): void {
                $scanned++;
                $legacy = $store->getLegacyTaskExecutions($model);

                if ($legacy === []) {
                    return;
                }

                if (! is_array($legacy)) {
                    $invalidMetadata++;

                    return;
                }

                $withMetadata++;

                if (! $store->resolveUserId($model)) {
                    $missingOwner++;
                }
            });

            $rows[] = [
                'Model' => $modelType,
                'Scanned' => $scanned,
                'With metadata' => $withMetadata,
                'Missing owner' => $missingOwner,
                'Invalid metadata' => $invalidMetadata,
                'Batches' => (int) ceil(max(1, $scanned) / max(1, (int) $this->option('batch-size'))),
            ];
        }

        $this->table(['Model', 'Scanned', 'With metadata', 'Missing owner', 'Invalid metadata', 'Batches'], $rows);

        return Command::SUCCESS;
    }

    /**
     * @return array<string, class-string<Model>>
     */
    protected function modelTypes(): array
    {
        $all = [
            'event' => Event::class,
            'block' => Block::class,
            'object' => EventObject::class,
            'integration' => Integration::class,
        ];

        $model = $this->option('model');

        if (! $model) {
            return $all;
        }

        return isset($all[$model]) ? [$model => $all[$model]] : [];
    }
}
