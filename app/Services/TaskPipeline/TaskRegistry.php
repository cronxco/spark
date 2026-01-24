<?php

namespace App\Services\TaskPipeline;

use App\Services\TaskPipeline\Exceptions\CircularDependencyException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class TaskRegistry
{
    /**
     * Registered tasks
     *
     * @var array<string, TaskDefinition>
     */
    protected static array $tasks = [];

    /**
     * Register a task definition
     */
    public static function register(TaskDefinition $task): void
    {
        static::$tasks[$task->key] = $task;
    }

    /**
     * Get a specific task by key
     */
    public static function getTask(string $key): ?TaskDefinition
    {
        return static::$tasks[$key] ?? null;
    }

    /**
     * Get all registered tasks
     *
     * @return array<string, TaskDefinition>
     */
    public static function getAllTasks(): array
    {
        return static::$tasks;
    }

    /**
     * Get tasks applicable to a specific model and trigger
     */
    public static function getTasksForModel(Model $model, string $trigger = 'created'): Collection
    {
        return collect(static::$tasks)
            ->filter(fn (TaskDefinition $task) => $task->isApplicableTo($model))
            ->filter(function (TaskDefinition $task) use ($trigger) {
                return ($trigger === 'created' && $task->runOnCreate)
                    || ($trigger === 'updated' && $task->runOnUpdate)
                    || $trigger === 'manual'
                    || $trigger === 'scheduled';
            })
            ->sortByDesc('priority')
            ->values();
    }

    /**
     * Resolve the execution order based on task dependencies
     *
     * @throws CircularDependencyException
     */
    public static function resolveExecutionOrder(Collection $tasks): Collection
    {
        $ordered = collect();
        $remaining = $tasks->keyBy('key');

        while ($remaining->isNotEmpty()) {
            // Find tasks whose dependencies are all satisfied
            $resolved = $remaining->filter(function ($task) use ($ordered) {
                // All dependencies already in ordered list?
                return collect($task->dependencies)
                    ->every(fn ($dep) => $ordered->has($dep));
            });

            if ($resolved->isEmpty() && $remaining->isNotEmpty()) {
                // Circular dependency detected
                throw new CircularDependencyException(
                    'Circular dependency detected in tasks: '.$remaining->pluck('key')->join(', ')
                );
            }

            $ordered = $ordered->merge($resolved);
            $remaining = $remaining->except($resolved->keys()->toArray());
        }

        return $ordered;
    }

    /**
     * Clear all registered tasks (useful for testing)
     */
    public static function clear(): void
    {
        static::$tasks = [];
    }
}
