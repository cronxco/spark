<?php

namespace App\Services\TaskPipeline;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\TaskExecution;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class TaskExecutionStore
{
    /**
     * @return array<string, array{last_attempt?: array<string, mixed>, last_success?: array<string, mixed>}>
     */
    public function getTaskExecutions(Model $model): array
    {
        $legacy = $this->getLegacyTaskExecutions($model);

        if (! $model->exists || ! Schema::hasTable('task_executions')) {
            return $legacy;
        }

        $rows = TaskExecution::query()
            ->forEntity($this->entityType($model), (string) $model->getKey())
            ->get();

        if ($rows->isEmpty()) {
            return $legacy;
        }

        $fromTable = $rows
            ->mapWithKeys(fn (TaskExecution $execution) => [
                $execution->task_key => $this->legacyShapeFromRow($execution),
            ])
            ->all();

        return array_replace($legacy, $fromTable);
    }

    public function getLegacyTaskExecutions(Model $model): array
    {
        $field = $this->metadataField($model);
        $metadata = $model->$field ?? [];

        return is_array($metadata) ? ($metadata['task_executions'] ?? []) : [];
    }

    public function setTaskExecutions(Model $model, array $executions, bool $force = true): void
    {
        foreach ($executions as $taskKey => $execution) {
            if (! is_string($taskKey) || ! is_array($execution)) {
                continue;
            }

            $lastAttempt = $execution['last_attempt'] ?? null;
            if (! is_array($lastAttempt) || empty($lastAttempt['status'])) {
                continue;
            }

            $this->upsertFromLegacy($model, $taskKey, $execution, $force);
        }

        $this->mirrorLegacyTaskExecutions($model, $executions);
    }

    public function recordStatus(
        Model $model,
        TaskDefinition $task,
        string $status,
        array $data = [],
        ?object $jobContext = null,
        bool $mergeLastAttempt = true,
    ): array {
        $executions = $this->getTaskExecutions($model);

        $executionData = array_merge($data, [
            'status' => $status,
        ], $this->queueContext($task, $jobContext));

        $lastAttempt = $mergeLastAttempt
            ? array_merge($executions[$task->key]['last_attempt'] ?? [], $executionData)
            : $executionData;

        $executions[$task->key]['last_attempt'] = $lastAttempt;

        if ($status === 'success') {
            $executions[$task->key]['last_success'] = $lastAttempt;
        }

        $this->upsertFromLegacy($model, $task->key, $executions[$task->key], true, $task, $this->isTerminalStatus($status));
        $this->mirrorLegacyTaskExecutions($model, $executions);

        return $executions[$task->key];
    }

    public function upsertFromLegacy(
        Model $model,
        string $taskKey,
        array $legacyExecution,
        bool $force = false,
        ?TaskDefinition $task = null,
        bool $appendHistory = false,
    ): ?TaskExecution {
        if (! $model->exists || ! Schema::hasTable('task_executions')) {
            return null;
        }

        $lastAttempt = $legacyExecution['last_attempt'] ?? null;
        if (! is_array($lastAttempt) || empty($lastAttempt['status'])) {
            return null;
        }

        $execution = TaskExecution::firstOrNew([
            'entity_type' => $this->entityType($model),
            'entity_id' => (string) $model->getKey(),
            'task_key' => $taskKey,
        ]);

        if ($execution->exists && ! $force) {
            return $execution;
        }

        $lastSuccess = $legacyExecution['last_success'] ?? null;
        $history = $execution->history ?? [];
        if ($appendHistory) {
            $history[] = $this->historySnapshot($taskKey, $lastAttempt);
        }

        $execution->fill([
            'user_id' => $this->resolveUserId($model),
            'task_name' => $task?->name ?? TaskRegistry::getTask($taskKey)?->name ?? $taskKey,
            'status' => (string) $lastAttempt['status'],
            'attempts' => (int) ($lastAttempt['attempts'] ?? $execution->attempts ?? 0),
            'triggered_by' => $lastAttempt['triggered_by'] ?? null,
            'started_at' => $this->dateOrNull($lastAttempt['started_at'] ?? null),
            'completed_at' => $this->dateOrNull($lastAttempt['completed_at'] ?? null),
            'queue' => $lastAttempt['queue'] ?? $task?->queue,
            'queue_connection' => $lastAttempt['queue_connection'] ?? null,
            'job_id' => $lastAttempt['job_id'] ?? null,
            'error' => $lastAttempt['error'] ?? null,
            'waiting_for' => $lastAttempt['waiting_for'] ?? null,
            'blocked_by' => $lastAttempt['blocked_by'] ?? null,
            'changed_fields' => $lastAttempt['changed_fields'] ?? null,
            'history' => $history,
            'last_success' => is_array($lastSuccess) ? $lastSuccess : null,
        ]);

        $execution->save();

        return $execution;
    }

    public function mirrorLegacyTaskExecutions(Model $model, array $executions): void
    {
        $field = $this->metadataField($model);
        $metadata = $model->$field ?? [];
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata['task_executions'] = $executions;

        if (! $model->exists) {
            $model->$field = $metadata;

            return;
        }

        $model->withoutEvents(function () use ($model, $field, $metadata): void {
            $model->newQueryWithoutScopes()
                ->whereKey($model->getKey())
                ->update([$field => $metadata]);
        });

        $model->$field = $metadata;
        $model->syncOriginalAttribute($field);
    }

    public function metadataField(Model $model): string
    {
        return match (true) {
            $model instanceof Event => 'event_metadata',
            $model instanceof Integration => 'configuration',
            $model instanceof IntegrationGroup => 'auth_metadata',
            $model instanceof Block, $model instanceof EventObject => 'metadata',
            default => throw new InvalidArgumentException('Unsupported task execution model: ' . get_class($model)),
        };
    }

    public function entityType(Model $model): string
    {
        return match (true) {
            $model instanceof Event => 'event',
            $model instanceof Block => 'block',
            $model instanceof EventObject => 'object',
            $model instanceof IntegrationGroup => 'integration_group',
            $model instanceof Integration => 'integration',
            default => throw new InvalidArgumentException('Unsupported task execution model: ' . get_class($model)),
        };
    }

    public function resolveUserId(Model $model): ?string
    {
        if ($model instanceof Integration || $model instanceof EventObject || $model instanceof IntegrationGroup) {
            return $model->user_id;
        }

        if ($model instanceof Event) {
            return $model->integration?->user_id;
        }

        if ($model instanceof Block) {
            return $model->event?->integration?->user_id;
        }

        return null;
    }

    protected function legacyShapeFromRow(TaskExecution $execution): array
    {
        $lastAttempt = array_filter([
            'status' => $execution->status,
            'attempts' => $execution->attempts,
            'triggered_by' => $execution->triggered_by,
            'started_at' => $execution->started_at?->toIso8601String(),
            'completed_at' => $execution->completed_at?->toIso8601String(),
            'queue' => $execution->queue,
            'queue_connection' => $execution->queue_connection,
            'job_id' => $execution->job_id,
            'error' => $execution->error,
            'waiting_for' => $execution->waiting_for,
            'blocked_by' => $execution->blocked_by,
            'changed_fields' => $execution->changed_fields,
        ], fn (mixed $value) => $value !== null);

        $shape = ['last_attempt' => $lastAttempt];

        if (is_array($execution->last_success)) {
            $shape['last_success'] = $execution->last_success;
        }

        return $shape;
    }

    protected function queueContext(TaskDefinition $task, ?object $jobContext): array
    {
        $context = ['queue' => $task->queue];

        if (! $jobContext || ! property_exists($jobContext, 'job') || ! $jobContext->job) {
            return $context;
        }

        $job = $jobContext->job;
        $context['job_id'] = method_exists($job, 'getJobId') ? $job->getJobId() : null;
        $context['queue_connection'] = method_exists($job, 'getConnectionName') ? $job->getConnectionName() : null;
        $context['queue'] = method_exists($job, 'getQueue') ? ($job->getQueue() ?? $task->queue) : $task->queue;

        return array_filter($context, fn (mixed $value) => $value !== null);
    }

    protected function historySnapshot(string $taskKey, array $lastAttempt): array
    {
        return [
            'task_key' => $taskKey,
            'status' => $lastAttempt['status'] ?? null,
            'attempts' => $lastAttempt['attempts'] ?? null,
            'started_at' => $lastAttempt['started_at'] ?? null,
            'completed_at' => $lastAttempt['completed_at'] ?? now()->toIso8601String(),
            'triggered_by' => $lastAttempt['triggered_by'] ?? null,
            'error' => Arr::get($lastAttempt, 'error'),
            'waiting_for' => Arr::get($lastAttempt, 'waiting_for'),
            'blocked_by' => Arr::get($lastAttempt, 'blocked_by'),
        ];
    }

    protected function isTerminalStatus(string $status): bool
    {
        return in_array($status, ['success', 'failed', 'blocked', 'not_applicable'], true);
    }

    protected function dateOrNull(mixed $value): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        return $value ?: null;
    }
}
