<?php

namespace App\Console\Commands\TaskPipeline;

use App\Jobs\TaskPipeline\Concerns\InteractsWithTaskMetadata;
use App\Jobs\TaskPipeline\ProcessIncompleteBatchJob;
use App\Models\ActionProgress;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\User;
use App\Services\TaskPipeline\TaskRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

class ProcessIncompleteTasksCommand extends Command
{
    use InteractsWithTaskMetadata;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'task-pipeline:process-incomplete
                            {--model= : Filter by model type (event|block|object)}
                            {--service= : Filter by service}
                            {--domain= : Filter by domain}
                            {--action= : Filter by action}
                            {--limit= : Maximum total models to process}
                            {--batch-size=50 : Number of models per batch}
                            {--dry-run : Show what would be processed without executing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process incomplete task pipelines in batches with automatic chaining';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('dry-run')) {
            return $this->performDryRun();
        }

        return $this->executeProcessing();
    }

    /**
     * Perform a dry-run to preview what would be processed
     */
    protected function performDryRun(): int
    {
        $this->info('Scanning for incomplete task pipelines...');

        $filters = $this->getFilters();
        if (! empty($filters)) {
            $filterStrings = [];
            foreach ($filters as $key => $value) {
                $filterStrings[] = "{$key}={$value}";
            }
            $this->comment('Filters: '.implode(', ', $filterStrings));
        }

        $this->newLine();

        $modelTypes = $this->getModelTypes();
        $results = [];
        $totalIncomplete = 0;
        $totalPrefillable = 0;

        foreach ($modelTypes as $modelClass => $modelName) {
            $this->comment("Scanning {$modelName} records...");

            $result = $this->scanModelType($modelClass);

            $results[] = [
                'Model Type' => $modelName,
                'Total' => number_format($result['total']),
                'Examined' => number_format($result['examined']),
                'Incomplete' => number_format($result['incomplete']),
            ];

            $totalIncomplete += $result['incomplete'];
            $totalPrefillable += $result['prefillable'];
        }

        $this->newLine();

        // Display results table
        $this->table(
            ['Model Type', 'Total', 'Examined', 'Incomplete'],
            $results
        );

        // Add totals row
        $batchSize = (int) $this->option('batch-size');
        $batchCount = (int) ceil($totalIncomplete / $batchSize);

        $this->newLine();
        $this->info("Would process {$totalIncomplete} models in {$batchCount} batches of {$batchSize}");
        $this->info("Estimated embedding pre-fills: {$totalPrefillable}");
        $this->newLine();
        $this->comment('Run without --dry-run to execute.');

        return Command::SUCCESS;
    }

    /**
     * Execute the batch processing
     */
    protected function executeProcessing(): int
    {
        $modelTypes = $this->getModelTypes();
        $batchSize = (int) $this->option('batch-size');
        $filters = $this->getFilters();

        $this->info('Starting batch processing for '.count($modelTypes).' model type(s)...');
        $this->newLine();

        // Create progress record (use first user as owner for system commands)
        $userId = User::first()?->id;
        if (! $userId) {
            $this->error('No users found in system. Cannot create progress record.');

            return Command::FAILURE;
        }

        $progressRecord = ActionProgress::createProgress(
            $userId,
            'task_pipeline_batch',
            'batch_'.now()->timestamp,
            'starting',
            'Starting batch task pipeline processing...',
            0
        );

        $this->info('Dispatching initial batch jobs:');

        // Dispatch first batch for each model type
        foreach ($modelTypes as $modelClass => $modelName) {
            ProcessIncompleteBatchJob::dispatch(
                $modelClass,
                0, // Start at offset 0
                $batchSize,
                $filters,
                $progressRecord->id,
                ['processed' => 0, 'examined' => 0, 'prefilled' => 0, 'failed' => 0]
            )->onQueue('tasks');

            $this->line("  ✓ {$modelName} batch job dispatched (offset: 0)");
        }

        $this->newLine();
        $this->info("Batches dispatched to 'tasks' queue. Monitor progress:");
        $this->line('  • View progress: /admin/action-progress');
        $this->line('  • Queue monitoring: sail artisan horizon:list');
        $this->line('  • Logs: storage/logs/laravel.log');
        $this->newLine();
        $this->comment('Processing will continue automatically in the background.');

        return Command::SUCCESS;
    }

    /**
     * Scan a model type for incomplete tasks
     */
    protected function scanModelType(string $modelClass): array
    {
        $query = $modelClass::query();

        // Apply filters
        if ($service = $this->option('service')) {
            $query->where('service', $service);
        }

        if ($domain = $this->option('domain') && $modelClass === Event::class) {
            $query->where('domain', $domain);
        }

        if ($action = $this->option('action') && $modelClass === Event::class) {
            $query->where('action', $action);
        }

        $total = $query->count();
        $examined = 0;
        $incomplete = 0;
        $prefillable = 0;
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        if ($total === 0) {
            return [
                'total' => 0,
                'examined' => 0,
                'incomplete' => 0,
                'prefillable' => 0,
            ];
        }

        $query->orderBy('created_at', 'desc')->chunk(100, function ($models) use (&$examined, &$incomplete, &$prefillable, $limit) {
            foreach ($models as $model) {
                $examined++;

                if ($this->hasIncompleteTasks($model)) {
                    $incomplete++;

                    if ($this->canPrefillEmbedding($model)) {
                        $prefillable++;
                    }

                    if ($limit && $incomplete >= $limit) {
                        return false; // Stop chunking
                    }
                }
            }
        });

        return [
            'total' => $total,
            'examined' => $examined,
            'incomplete' => $incomplete,
            'prefillable' => $prefillable,
        ];
    }

    /**
     * Check if model has incomplete tasks
     */
    protected function hasIncompleteTasks(Model $model): bool
    {
        $applicableTasks = TaskRegistry::getTasksForModel($model, 'scheduled');

        if ($applicableTasks->isEmpty()) {
            return false;
        }

        $executions = $this->getTaskExecutions($model);

        foreach ($applicableTasks as $task) {
            // Special case: embedding with metadata
            if ($task->key === 'generate_embedding') {
                $metadataField = $this->getMetadataField($model);
                $metadata = $model->$metadataField ?? [];

                if (isset($metadata['embedding_generated_at']) && ! empty($model->embeddings)) {
                    continue; // Completed
                }
            }

            // Check execution status
            $lastAttempt = $executions[$task->key]['last_attempt'] ?? null;
            if (! $lastAttempt || $lastAttempt['status'] !== 'success') {
                return true; // Found incomplete task
            }
        }

        return false;
    }

    /**
     * Check if model can pre-fill embedding
     */
    protected function canPrefillEmbedding(Model $model): bool
    {
        $metadataField = $this->getMetadataField($model);
        $metadata = $model->$metadataField ?? [];

        if (! isset($metadata['embedding_generated_at']) || empty($model->embeddings)) {
            return false;
        }

        $executions = $this->getTaskExecutions($model);

        return ! isset($executions['generate_embedding']['last_success']);
    }

    /**
     * Get model types to process
     */
    protected function getModelTypes(): array
    {
        $modelOption = $this->option('model');

        $all = [
            Event::class => 'Event',
            Block::class => 'Block',
            EventObject::class => 'EventObject',
        ];

        if ($modelOption) {
            $filtered = [];
            foreach ($all as $class => $name) {
                if (strtolower($name) === strtolower($modelOption) || $modelOption === 'object' && $name === 'EventObject') {
                    $filtered[$class] = $name;
                }
            }

            return $filtered;
        }

        return $all;
    }

    /**
     * Get filters array
     */
    protected function getFilters(): array
    {
        $filters = [];

        if ($service = $this->option('service')) {
            $filters['service'] = $service;
        }

        if ($domain = $this->option('domain')) {
            $filters['domain'] = $domain;
        }

        if ($action = $this->option('action')) {
            $filters['action'] = $action;
        }

        return $filters;
    }
}
