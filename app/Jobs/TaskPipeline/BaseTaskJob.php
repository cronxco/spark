<?php

namespace App\Jobs\TaskPipeline;

use App\Jobs\TaskPipeline\Concerns\InteractsWithTaskMetadata;
use App\Services\TaskPipeline\TaskDefinition;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class BaseTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, InteractsWithTaskMetadata, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 3;
    public $backoff = [30, 120, 300]; // 30s, 2m, 5m

    public function __construct(
        public Model $model,
        public TaskDefinition $task,
    ) {}

    /**
     * Handle the job execution
     */
    public function handle(): void
    {
        $this->updateStatus('running');

        try {
            $this->execute();
            $this->updateStatus('success', ['completed_at' => now()->toIso8601String()]);

        } catch (Exception $e) {
            // Report to Sentry with comprehensive context
            if (app()->bound('sentry')) {
                \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($e) {
                    $scope->setContext('task', [
                        'task_key' => $this->task->key,
                        'task_name' => $this->task->name,
                        'model_type' => get_class($this->model),
                        'model_id' => $this->model->id,
                        'user_id' => $this->model->user_id ?? null,
                        'attempt' => $this->attempts(),
                        'max_tries' => $this->tries,
                        'task_conditions' => $this->task->conditions,
                        'model_attributes' => $this->getModelAttributes(),
                    ]);

                    $scope->setTag('task', $this->task->key);
                    $scope->setTag('model', class_basename($this->model));
                    $scope->setTag('queue', $this->task->queue);

                    \Sentry\captureException($e);
                });
            }

            $this->updateStatus('failed', [
                'completed_at' => now()->toIso8601String(),
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            throw $e; // Re-throw for Laravel retry logic
        }
    }

    /**
     * Execute the task logic - to be implemented by subclasses
     */
    abstract protected function execute(): void;

    /**
     * Update the task status in metadata
     */
    protected function updateStatus(string $status, array $additionalData = []): void
    {
        $executions = $this->getTaskExecutions($this->model);

        $executionData = array_merge([
            'status' => $status,
        ], $additionalData);

        // Update last_attempt
        $executions[$this->task->key]['last_attempt'] = array_merge(
            $executions[$this->task->key]['last_attempt'] ?? [],
            $executionData
        );

        // Update last_success if applicable
        if ($status === 'success') {
            $executions[$this->task->key]['last_success'] = $executionData;
        }

        $this->setTaskExecutions($this->model, $executions);
    }

    /**
     * Get relevant model attributes for Sentry context
     */
    protected function getModelAttributes(): array
    {
        $attributes = [];

        // Common fields that might exist
        $fields = ['service', 'domain', 'action', 'value', 'value_unit', 'title', 'concept', 'type'];

        foreach ($fields as $field) {
            if (isset($this->model->$field)) {
                $attributes[$field] = $this->model->$field;
            }
        }

        return $attributes;
    }
}
