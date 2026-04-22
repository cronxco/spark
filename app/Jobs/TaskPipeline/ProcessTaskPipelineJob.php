<?php

namespace App\Jobs\TaskPipeline;

use App\Jobs\Base\BaseEffectJob;
use App\Jobs\TaskPipeline\Concerns\InteractsWithTaskMetadata;
use App\Models\Integration;
use App\Services\TaskPipeline\TaskDefinition;
use App\Services\TaskPipeline\TaskRegistry;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessTaskPipelineJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, InteractsWithTaskMetadata, Queueable, SerializesModels;

    public $timeout = 300;

    public $tries = 1; // Don't retry the dispatcher itself

    public function __construct(
        public Model $model,
        public string $trigger = 'created',
        public ?array $taskFilter = null,  // Only run specific tasks
        public bool $force = false,        // Re-run even if already executed
    ) {}

    public function handle(): void
    {
        $tasks = TaskRegistry::getTasksForModel($this->model, $this->trigger);

        // Apply task filter if provided
        if ($this->taskFilter) {
            $tasks = $tasks->whereIn('key', $this->taskFilter);
        }

        // Filter out already-executed tasks (unless force)
        if (! $this->force) {
            $tasks = $tasks->reject(function ($task) {
                return $this->wasSuccessfullyExecuted($task);
            });
        }

        // Resolve execution order
        $orderedTasks = TaskRegistry::resolveExecutionOrder($tasks);

        // Dispatch each task
        foreach ($orderedTasks as $task) {
            $this->dispatchTask($task);
        }
    }

    /**
     * Check if a task was successfully executed
     */
    protected function wasSuccessfullyExecuted(TaskDefinition $task): bool
    {
        $executions = $this->getTaskExecutions($this->model);
        $lastAttempt = $executions[$task->key]['last_attempt'] ?? null;

        return $lastAttempt && $lastAttempt['status'] === 'success';
    }

    /**
     * Dispatch a single task
     */
    protected function dispatchTask(TaskDefinition $task): void
    {
        // Check if applicable
        if (! $task->isApplicableTo($this->model)) {
            $this->markNotApplicable($task);

            return;
        }

        // Mark as pending
        $this->updateTaskStatus($task, 'pending', [
            'started_at' => now()->toIso8601String(),
            'triggered_by' => $this->trigger,
        ]);

        // Dispatch to appropriate queue
        $jobClass = $task->jobClass;

        // Check if job extends BaseEffectJob - they expect (Integration, array) instead of (Model, TaskDefinition)
        if (is_subclass_of($jobClass, BaseEffectJob::class)) {
            // Extract integration from the model
            $integration = $this->model instanceof Integration
                ? $this->model
                : $this->model->integration;

            if (! $integration) {
                $this->updateTaskStatus($task, 'failed', [
                    'error' => 'No integration found for effect job',
                    'completed_at' => now()->toIso8601String(),
                ]);

                return;
            }

            // Prepare parameters from task metadata
            $parameters = [
                'task_key' => $task->key,
                'triggered_by' => $this->trigger,
                'model_type' => class_basename($this->model),
                'model_id' => $this->model->id,
            ];

            dispatch(new $jobClass($integration, $parameters))->onQueue($task->queue);
        } else {
            // Standard task jobs expect (Model, TaskDefinition)
            dispatch(new $jobClass($this->model, $task))->onQueue($task->queue);
        }
    }

    /**
     * Update task status in metadata
     */
    protected function updateTaskStatus(TaskDefinition $task, string $status, array $data): void
    {
        $executions = $this->getTaskExecutions($this->model);

        $executions[$task->key]['last_attempt'] = array_merge($data, [
            'status' => $status,
        ]);

        $this->setTaskExecutions($this->model, $executions);
    }

    /**
     * Mark task as not applicable
     */
    protected function markNotApplicable(TaskDefinition $task): void
    {
        $this->updateTaskStatus($task, 'not_applicable', [
            'started_at' => now()->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
            'triggered_by' => $this->trigger,
        ]);
    }
}
