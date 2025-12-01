<?php

namespace App\Jobs\TaskPipeline;

use App\Jobs\TaskPipeline\Concerns\InteractsWithTaskMetadata;
use App\Models\ActionProgress;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Services\TaskPipeline\TaskRegistry;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessIncompleteBatchJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, InteractsWithTaskMetadata, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    /**
     * Create a new job instance
     */
    public function __construct(
        public string $modelType,
        public int $offset,
        public int $batchSize,
        public array $filters,
        public ?string $progressId = null,
        public array $stats = []
    ) {
        $this->onQueue('tasks');

        // Initialize stats if empty
        if (empty($this->stats)) {
            $this->stats = [
                'processed' => 0,
                'examined' => 0,
                'prefilled' => 0,
                'failed' => 0,
            ];
        }
    }

    /**
     * Execute the job
     */
    public function handle(): void
    {
        Log::info('ProcessIncompleteBatchJob starting', [
            'model_type' => $this->modelType,
            'offset' => $this->offset,
            'batch_size' => $this->batchSize,
            'stats' => $this->stats,
        ]);

        // Find next batch of incomplete models
        $result = $this->findIncompleteModels($this->modelType, $this->offset, $this->batchSize);

        $incompleteModels = $result['models'];
        $examined = $result['examined'];
        $nextOffset = $result['next_offset'];

        Log::info('Found incomplete models', [
            'model_type' => $this->modelType,
            'count' => $incompleteModels->count(),
            'examined' => $examined,
            'next_offset' => $nextOffset,
        ]);

        // If no incomplete models found, move to next model type or finalize
        if ($incompleteModels->isEmpty()) {
            $this->stats['examined'] += $examined;
            $this->updateProgress();

            // Determine next action
            $modelTypes = [
                Event::class,
                Block::class,
                EventObject::class,
            ];

            $currentIndex = array_search($this->modelType, $modelTypes);

            if ($currentIndex === false || $currentIndex >= count($modelTypes) - 1) {
                // Done with all model types
                $this->finalizeProcessing();
            } else {
                // Move to next model type
                $nextModelType = $modelTypes[$currentIndex + 1];

                Log::info('No incomplete models found, moving to next model type', [
                    'from' => $this->modelType,
                    'to' => $nextModelType,
                ]);

                static::dispatch(
                    $nextModelType,
                    0, // Reset offset
                    $this->batchSize,
                    $this->filters,
                    $this->progressId,
                    $this->stats
                )->onQueue('tasks');
            }

            return;
        }

        // Pre-fill embedding tasks where applicable
        $prefilled = 0;
        foreach ($incompleteModels as $model) {
            if ($this->preFillEmbeddingTask($model)) {
                $prefilled++;
            }
        }

        // Create batch of ProcessTaskPipelineJob for each model
        $jobs = [];
        foreach ($incompleteModels as $model) {
            $jobs[] = new ProcessTaskPipelineJob(
                model: $model,
                trigger: 'scheduled',
                taskFilter: null,
                force: false
            );
        }

        // Update stats
        $this->stats['processed'] += $incompleteModels->count();
        $this->stats['examined'] += $examined;
        $this->stats['prefilled'] += $prefilled;

        // Update progress
        $this->updateProgress();

        // Determine what happens after this batch completes
        $hasMoreInThisType = $incompleteModels->count() >= $this->batchSize;

        // Capture instance properties for the closure (can't use $this in finally callback)
        $modelType = $this->modelType;
        $batchSize = $this->batchSize;
        $filters = $this->filters;
        $progressId = $this->progressId;
        $stats = $this->stats;

        // Dispatch batch with continuation job
        $batch = Bus::batch($jobs)
            ->name("task_pipeline_batch_{$this->modelType}_{$this->offset}")
            ->onQueue('tasks')
            ->finally(function () use ($hasMoreInThisType, $nextOffset, $modelType, $batchSize, $filters, $progressId, $stats) {
                if ($hasMoreInThisType) {
                    // More models to process in this model type - chain to next batch
                    Log::info('Batch complete, chaining to next batch', [
                        'model_type' => $modelType,
                        'next_offset' => $nextOffset,
                    ]);

                    ProcessIncompleteBatchJob::dispatch(
                        $modelType,
                        $nextOffset,
                        $batchSize,
                        $filters,
                        $progressId,
                        $stats
                    )->onQueue('tasks');
                } else {
                    // Done with this model type - dispatch next model type or finalize
                    Log::info('Batch complete, determining next model type', [
                        'model_type' => $modelType,
                    ]);

                    $modelTypes = [
                        Event::class,
                        Block::class,
                        EventObject::class,
                    ];

                    $currentIndex = array_search($modelType, $modelTypes);

                    if ($currentIndex === false || $currentIndex >= count($modelTypes) - 1) {
                        // Done with all model types - finalize
                        Log::info('Batch processing complete', [
                            'stats' => $stats,
                        ]);

                        if ($progressId) {
                            $progress = ActionProgress::find($progressId);
                            if ($progress) {
                                $progress->markCompleted([
                                    'total_processed' => $stats['processed'],
                                    'total_examined' => $stats['examined'],
                                    'total_prefilled' => $stats['prefilled'],
                                    'total_failed' => $stats['failed'],
                                ]);
                            }
                        }
                    } else {
                        // Move to next model type
                        $nextModelType = $modelTypes[$currentIndex + 1];

                        Log::info('Moving to next model type', [
                            'from' => $modelType,
                            'to' => $nextModelType,
                        ]);

                        ProcessIncompleteBatchJob::dispatch(
                            $nextModelType,
                            0, // Reset offset
                            $batchSize,
                            $filters,
                            $progressId,
                            $stats
                        )->onQueue('tasks');
                    }
                }
            })
            ->dispatch();

        Log::info('Dispatched batch', [
            'batch_id' => $batch->id,
            'job_count' => count($jobs),
            'model_type' => $this->modelType,
        ]);
    }

    /**
     * Handle job failure
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ProcessIncompleteBatchJob failed', [
            'model_type' => $this->modelType,
            'offset' => $this->offset,
            'stats' => $this->stats,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        if (! $this->progressId) {
            return;
        }

        $progress = ActionProgress::find($this->progressId);
        if (! $progress) {
            return;
        }

        $progress->markFailed($exception->getMessage(), [
            'model_type' => class_basename($this->modelType),
            'offset' => $this->offset,
            'stats' => $this->stats,
        ]);
    }

    /**
     * Find incomplete models for the given type
     */
    protected function findIncompleteModels(string $modelClass, int $offset, int $batchSize): array
    {
        $incomplete = collect();
        $examined = 0;
        $shouldStop = false;

        $query = $modelClass::query()->orderBy('created_at', 'desc');

        // Apply filters
        if (! empty($this->filters['service'])) {
            $query->where('service', $this->filters['service']);
        }

        if (! empty($this->filters['domain']) && $modelClass === Event::class) {
            $query->where('domain', $this->filters['domain']);
        }

        if (! empty($this->filters['action']) && $modelClass === Event::class) {
            $query->where('action', $this->filters['action']);
        }

        // Chunk through models
        $query->skip($offset)->chunk(100, function ($models) use (&$incomplete, &$examined, $batchSize, &$shouldStop) {
            foreach ($models as $model) {
                $examined++;

                if ($this->hasIncompleteTasks($model)) {
                    $incomplete->push($model);

                    if ($incomplete->count() >= $batchSize) {
                        $shouldStop = true;

                        return false; // Stop chunking
                    }
                }
            }
        });

        return [
            'models' => $incomplete,
            'examined' => $examined,
            'next_offset' => $offset + $examined,
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
                if ($this->hasCompletedEmbedding($model)) {
                    continue;
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
     * Check if model has completed embedding
     */
    protected function hasCompletedEmbedding(Model $model): bool
    {
        $metadataField = $this->getMetadataField($model);
        $metadata = $model->$metadataField ?? [];

        return isset($metadata['embedding_generated_at']) && ! empty($model->embeddings);
    }

    /**
     * Pre-fill embedding task execution record if completed
     */
    protected function preFillEmbeddingTask(Model $model): bool
    {
        // Check if embedding is complete but not tracked
        $metadataField = $this->getMetadataField($model);
        $metadata = $model->$metadataField ?? [];

        if (! isset($metadata['embedding_generated_at']) || empty($model->embeddings)) {
            return false; // Not complete
        }

        // Check if already tracked
        $executions = $this->getTaskExecutions($model);
        if (isset($executions['generate_embedding']['last_success'])) {
            return false; // Already tracked
        }

        // Pre-fill execution record
        $executions['generate_embedding'] = [
            'last_attempt' => [
                'started_at' => $metadata['embedding_generated_at'],
                'completed_at' => $metadata['embedding_generated_at'],
                'status' => 'success',
                'attempts' => 1,
                'triggered_by' => 'backfill',
            ],
            'last_success' => [
                'started_at' => $metadata['embedding_generated_at'],
                'completed_at' => $metadata['embedding_generated_at'],
                'status' => 'success',
                'attempts' => 1,
                'triggered_by' => 'backfill',
            ],
        ];

        $this->setTaskExecutions($model, $executions);

        Log::info('Pre-filled embedding task execution', [
            'model_type' => get_class($model),
            'model_id' => $model->id,
        ]);

        return true; // Pre-filled
    }

    /**
     * Update progress record
     */
    protected function updateProgress(): void
    {
        if (! $this->progressId) {
            return;
        }

        $progress = ActionProgress::find($this->progressId);
        if (! $progress) {
            return;
        }

        $modelName = class_basename($this->modelType);
        $percentComplete = 0; // Can't calculate accurately without total count

        $progress->updateProgress(
            "processing_{$modelName}",
            "Processing {$modelName}: {$this->stats['processed']} processed, {$this->stats['examined']} examined",
            $percentComplete,
            [
                'model_type' => $modelName,
                'processed' => $this->stats['processed'],
                'examined' => $this->stats['examined'],
                'prefilled' => $this->stats['prefilled'],
                'failed' => $this->stats['failed'],
            ]
        );
    }

    /**
     * Finalize processing
     */
    protected function finalizeProcessing(): void
    {
        Log::info('Batch processing complete', [
            'stats' => $this->stats,
        ]);

        if (! $this->progressId) {
            return;
        }

        $progress = ActionProgress::find($this->progressId);
        if (! $progress) {
            return;
        }

        $progress->markCompleted([
            'total_processed' => $this->stats['processed'],
            'total_examined' => $this->stats['examined'],
            'total_prefilled' => $this->stats['prefilled'],
            'total_failed' => $this->stats['failed'],
        ]);
    }
}
