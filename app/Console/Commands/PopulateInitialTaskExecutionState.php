<?php

namespace App\Console\Commands;

use App\Jobs\TaskPipeline\Concerns\InteractsWithTaskMetadata;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Services\TaskPipeline\TaskRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

class PopulateInitialTaskExecutionState extends Command
{
    use InteractsWithTaskMetadata;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'task-pipeline:populate-initial-state
                            {--model= : Model type (event, block, object, integration)}
                            {--limit= : Limit number of records to process}
                            {--dry-run : Preview without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Populate initial task execution state in metadata for existing records';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Populating initial task execution states...');
        $this->newLine();

        $models = $this->getModelsToProcess();

        foreach ($models as $modelClass => $modelName) {
            $this->processModel($modelClass, $modelName);
        }

        $this->newLine();
        $this->info('Done!');
    }

    /**
     * Process a specific model type
     */
    protected function processModel(string $modelClass, string $modelName): void
    {
        $this->info("Processing {$modelName} records...");

        $query = $modelClass::query();

        if ($limit = $this->option('limit')) {
            $query->limit($limit);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->comment("No {$modelName} records found.");
            $this->newLine();

            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;
        $skipped = 0;

        $query->chunk(100, function ($items) use ($bar, $modelName, &$processed, &$skipped) {
            foreach ($items as $item) {
                $result = $this->populateTaskExecutions($item, $modelName);

                if ($result) {
                    $processed++;
                } else {
                    $skipped++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->line("  Processed: {$processed}");
        $this->line("  Skipped: {$skipped} (already have task executions)");
        $this->newLine();
    }

    /**
     * Populate task executions for a single model
     */
    protected function populateTaskExecutions(Model $model, string $modelType): bool
    {
        // Check if already has task executions
        $existingExecutions = $this->getTaskExecutions($model);

        if (! empty($existingExecutions)) {
            return false; // Skip - already populated
        }

        // Get all tasks that could apply to this model
        $tasks = TaskRegistry::getTasksForModel($model, 'created');

        if ($tasks->isEmpty()) {
            return false; // No applicable tasks
        }

        $executions = [];

        foreach ($tasks as $task) {
            // Infer execution state from model state
            $executionState = $this->inferExecutionState($model, $task);

            if ($executionState) {
                $executions[$task->key] = $executionState;
            }
        }

        if (empty($executions)) {
            return false; // Nothing to populate
        }

        if ($this->option('dry-run')) {
            $this->line("  Would update {$modelType} #{$model->id} with ".count($executions).' task executions');

            return true;
        }

        // Update the model with task executions
        $this->setTaskExecutions($model, $executions);

        return true;
    }

    /**
     * Infer execution state from model current state
     */
    protected function inferExecutionState(Model $model, $task): ?array
    {
        // For embedding generation - check if embeddings exist
        if ($task->key === 'generate_embedding') {
            if ($model->embeddings) {
                return [
                    'last_attempt' => [
                        'started_at' => $model->created_at->toIso8601String(),
                        'completed_at' => $model->created_at->toIso8601String(),
                        'status' => 'success',
                        'attempts' => 1,
                        'triggered_by' => 'migration',
                    ],
                    'last_success' => [
                        'started_at' => $model->created_at->toIso8601String(),
                        'completed_at' => $model->created_at->toIso8601String(),
                        'status' => 'success',
                        'attempts' => 1,
                        'triggered_by' => 'migration',
                    ],
                ];
            }
        }

        // For other tasks, we can't reliably infer state
        // Mark as not run (return null)
        // This allows them to be run on next trigger
        return null;
    }

    /**
     * Get models to process based on options
     */
    protected function getModelsToProcess(): array
    {
        $modelOption = $this->option('model');

        $all = [
            Event::class => 'event',
            Block::class => 'block',
            EventObject::class => 'object',
            Integration::class => 'integration',
        ];

        if ($modelOption) {
            return array_filter($all, fn ($name) => $name === $modelOption);
        }

        return $all;
    }
}
