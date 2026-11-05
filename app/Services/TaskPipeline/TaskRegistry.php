<?php

namespace App\Services\TaskPipeline;

use App\Services\TaskPipeline\Exceptions\CircularDependencyException;
use App\Services\TaskPipeline\Exceptions\UnresolvableDependencyException;
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
     * Get the keys for tasks that directly depend on the given task.
     *
     * @return array<int, string>
     */
    public static function getDependentTaskKeys(string $taskKey): array
    {
        return collect(static::$tasks)
            ->filter(fn (TaskDefinition $task) => in_array($taskKey, $task->dependencies, true))
            ->keys()
            ->values()
            ->all();
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
        // Keys present in the input batch — deps NOT in this set are externally satisfied
        $inputKeys = $remaining->keys()->all();

        while ($remaining->isNotEmpty()) {
            // Find tasks whose dependencies are all satisfied
            $resolved = $remaining->filter(function ($task) use ($ordered, $inputKeys) {
                // A dependency is satisfied if it's already ordered OR it's not in this batch
                return collect($task->dependencies)
                    ->every(fn ($dep) => $ordered->has($dep) || ! in_array($dep, $inputKeys, true));
            });

            if ($resolved->isEmpty() && $remaining->isNotEmpty()) {
                // Circular dependency detected
                throw new CircularDependencyException(
                    'Circular dependency detected in tasks: ' . $remaining->pluck('key')->join(', ')
                );
            }

            $ordered = $ordered->merge($resolved);
            $remaining = $remaining->except($resolved->keys()->toArray());
        }

        return $ordered;
    }

    /**
     * Validate that all declared dependencies exist in the registry.
     *
     * Call once after all tasks have been registered (e.g. end of ServiceProvider::boot).
     * Throws UnresolvableDependencyException listing every missing dep, so one boot-time
     * failure surfaces all misconfigured tasks at once rather than failing silently at runtime.
     *
     * @throws UnresolvableDependencyException
     */
    public static function validateDependencies(): void
    {
        $errors = [];

        foreach (static::$tasks as $task) {
            foreach ($task->dependencies as $dep) {
                if (! isset(static::$tasks[$dep])) {
                    $errors[] = "Task '{$task->key}' declares dependency '{$dep}' which is not registered.";
                }
            }
        }

        if (! empty($errors)) {
            throw new UnresolvableDependencyException(
                "Task dependency validation failed:\n" . implode("\n", $errors)
            );
        }
    }

    /**
     * Clear all registered tasks (useful for testing)
     */
    public static function clear(): void
    {
        static::$tasks = [];
    }
}
