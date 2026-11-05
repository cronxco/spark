<?php

namespace App\Jobs\TaskPipeline;

use App\Jobs\TaskPipeline\Concerns\InteractsWithTaskMetadata;
use App\Models\Event;
use App\Services\TaskPipeline\TaskDefinition;
use App\Services\TaskPipeline\TaskExecutionStore;
use App\Services\TaskPipeline\TaskRegistry;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Dispatched from model `created`/`updated` hooks, often from inside the
 * caller's open transaction. Without ShouldQueueAfterCommit, the redis queue
 * connection's after_commit=false default lets Horizon grab the job before
 * that transaction commits, and restoreModel() throws ModelNotFoundException
 * on the not-yet-visible row — with no retry (see $tries below), the whole
 * pipeline run for that model is silently lost.
 */
class ProcessTaskPipelineJob implements ShouldQueue, ShouldQueueAfterCommit
{
    use Batchable, Dispatchable, InteractsWithQueue, InteractsWithTaskMetadata, Queueable, SerializesModels;

    public $timeout = 300;

    public $tries = 1; // Don't retry the dispatcher itself

    public function __construct(
        public Model $model,
        public string $trigger = 'created',
        public ?array $taskFilter = null,  // Only run specific tasks
        public bool $force = false,        // Re-run even if already executed
        public array $changedFields = [],  // Fields that triggered an update run
    ) {}

    public function handle(): void
    {
        if ($this->model instanceof Event && $this->model->isInternal()) {
            return;
        }

        $applicableTasks = TaskRegistry::getTasksForModel($this->model, $this->trigger);
        $tasks = $applicableTasks;

        // Apply task filter if provided
        if ($this->taskFilter) {
            $tasks = $this->expandTaskFilterWithDependencies($applicableTasks, $this->taskFilter);
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
        $this->model->refresh();

        // Check if applicable
        if (! $task->isApplicableTo($this->model)) {
            $this->markNotApplicable($task);

            return;
        }

        if ($failedDependency = $this->firstFailedDependency($this->model, $task)) {
            $this->markBlocked($task, $failedDependency);

            return;
        }

        if ($incompleteDependency = $this->firstIncompleteDependency($this->model, $task)) {
            $this->markWaiting($task, $incompleteDependency);

            return;
        }

        // Mark as pending
        $this->updateTaskStatus($task, 'pending', [
            'started_at' => now()->toIso8601String(),
            'triggered_by' => $this->trigger,
            ...($this->changedFields ? ['changed_fields' => $this->changedFields] : []),
        ]);

        // Dispatch to appropriate queue
        $jobClass = $task->jobClass;

        // Standard task jobs expect (Model, TaskDefinition)
        dispatch(new $jobClass($this->model, $task))->onQueue($task->queue);
    }

    /**
     * Update task status in metadata
     */
    protected function updateTaskStatus(TaskDefinition $task, string $status, array $data): void
    {
        app(TaskExecutionStore::class)->recordStatus(
            model: $this->model,
            task: $task,
            status: $status,
            data: $data,
            jobContext: $this,
            mergeLastAttempt: false,
        );
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

    protected function markWaiting(TaskDefinition $task, string $dependencyKey): void
    {
        $this->updateTaskStatus($task, 'waiting', [
            'waiting_for' => $dependencyKey,
            'triggered_by' => $this->trigger,
            ...($this->changedFields ? ['changed_fields' => $this->changedFields] : []),
        ]);
    }

    protected function markBlocked(TaskDefinition $task, string $dependencyKey): void
    {
        $this->updateTaskStatus($task, 'blocked', [
            'blocked_by' => $dependencyKey,
            'completed_at' => now()->toIso8601String(),
            'triggered_by' => $this->trigger,
            ...($this->changedFields ? ['changed_fields' => $this->changedFields] : []),
        ]);
    }

    protected function expandTaskFilterWithDependencies(Collection $applicableTasks, array $taskFilter): Collection
    {
        $tasksByKey = $applicableTasks->keyBy('key');
        $selected = collect();
        $pendingKeys = $taskFilter;

        while ($pendingKeys !== []) {
            $taskKey = array_shift($pendingKeys);
            $task = $tasksByKey->get($taskKey);

            if (! $task || $selected->has($taskKey)) {
                continue;
            }

            $selected->put($taskKey, $task);

            foreach ($task->dependencies as $dependencyKey) {
                if ($tasksByKey->has($dependencyKey) && ! $selected->has($dependencyKey)) {
                    $pendingKeys[] = $dependencyKey;
                }
            }
        }

        return $selected->sortByDesc('priority')->values();
    }
}
