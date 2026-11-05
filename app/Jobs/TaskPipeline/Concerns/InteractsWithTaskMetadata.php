<?php

namespace App\Jobs\TaskPipeline\Concerns;

use App\Services\TaskPipeline\TaskDefinition;
use App\Services\TaskPipeline\TaskExecutionStore;
use App\Services\TaskPipeline\TaskRegistry;
use Illuminate\Database\Eloquent\Model;

trait InteractsWithTaskMetadata
{
    /**
     * Get the metadata field name for the given model
     */
    protected function getMetadataField(Model $model): string
    {
        return app(TaskExecutionStore::class)->metadataField($model);
    }

    /**
     * Get task executions from the model's metadata
     */
    protected function getTaskExecutions(Model $model): array
    {
        return app(TaskExecutionStore::class)->getTaskExecutions($model);
    }

    /**
     * Set task executions in the model's metadata
     */
    protected function setTaskExecutions(Model $model, array $executions): void
    {
        app(TaskExecutionStore::class)->setTaskExecutions($model, $executions);
    }

    protected function taskSucceeded(Model $model, string $taskKey): bool
    {
        return $this->taskStatus($model, $taskKey) === 'success';
    }

    protected function taskFailed(Model $model, string $taskKey): bool
    {
        return in_array($this->taskStatus($model, $taskKey), ['failed', 'blocked'], true);
    }

    protected function taskStatus(Model $model, string $taskKey): ?string
    {
        $executions = $this->getTaskExecutions($model);

        return $executions[$taskKey]['last_attempt']['status'] ?? null;
    }

    protected function firstFailedDependency(Model $model, TaskDefinition $task): ?string
    {
        foreach ($this->applicableDependencies($model, $task) as $dependencyKey) {
            if ($this->taskFailed($model, $dependencyKey)) {
                return $dependencyKey;
            }
        }

        return null;
    }

    protected function firstIncompleteDependency(Model $model, TaskDefinition $task): ?string
    {
        foreach ($this->applicableDependencies($model, $task) as $dependencyKey) {
            if (! $this->taskSucceeded($model, $dependencyKey)) {
                return $dependencyKey;
            }
        }

        return null;
    }

    /**
     * Dependencies that do not apply to this model cannot complete for it, so they
     * are ignored for runtime gating.
     *
     * @return array<int, string>
     */
    protected function applicableDependencies(Model $model, TaskDefinition $task): array
    {
        return array_values(array_filter(
            $task->dependencies,
            fn (string $dependencyKey) => TaskRegistry::getTask($dependencyKey)?->isApplicableTo($model) ?? false,
        ));
    }
}
