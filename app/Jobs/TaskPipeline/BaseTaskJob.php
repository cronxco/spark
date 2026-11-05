<?php

namespace App\Jobs\TaskPipeline;

use App\Exceptions\TaskOutcomeException;
use App\Jobs\TaskPipeline\Concerns\InteractsWithTaskMetadata;
use App\Services\TaskPipeline\TaskDefinition;
use App\Services\TaskPipeline\TaskExecutionStore;
use App\Services\TaskPipeline\TaskRegistry;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Sentry\State\Scope;

abstract class BaseTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, InteractsWithTaskMetadata, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 3;

    public $backoff = [30, 120, 300]; // 30s, 2m, 5m

    /**
     * What the run achieved, recorded with a successful status so "success"
     * can be told apart from "nothing to do" or "done with warnings".
     */
    private ?string $outcome = null;

    /**
     * @var array<string, int>
     */
    private array $outcomeCounts = [];

    public function __construct(
        public Model $model,
        public TaskDefinition $task,
    ) {}

    /**
     * Handle the job execution
     */
    public function handle(): void
    {
        $this->model->refresh();

        if ($failedDependency = $this->firstFailedDependency($this->model, $this->task)) {
            $this->updateStatus('blocked', [
                'blocked_by' => $failedDependency,
                'completed_at' => now()->toIso8601String(),
            ]);

            return;
        }

        if ($incompleteDependency = $this->firstIncompleteDependency($this->model, $this->task)) {
            $this->updateStatus('waiting', [
                'waiting_for' => $incompleteDependency,
            ]);

            $this->release(30);

            return;
        }

        $this->updateStatus('running');

        try {
            $this->execute();
            $this->updateStatus('success', array_merge(
                ['completed_at' => now()->toIso8601String()],
                $this->outcomeData(),
            ));
            $this->dispatchDependentTasks();

        } catch (TaskOutcomeException $e) {
            $this->updateStatus('failed', [
                'completed_at' => now()->toIso8601String(),
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
                'outcome' => $e->outcome,
                'outcome_counts' => $e->counts,
            ]);
        } catch (Exception $e) {
            // Report to Sentry with comprehensive context
            if (app()->bound('sentry')) {
                \Sentry\withScope(function (Scope $scope) use ($e) {
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
     * Record what a successful run achieved, e.g. `matched`, `no_candidate`,
     * `not_applicable` or `succeeded_with_warnings`, with any counts.
     *
     * @param  array<string, int>  $counts
     */
    protected function recordOutcome(string $outcome, array $counts = []): void
    {
        $this->outcome = $outcome;
        $this->outcomeCounts = $counts;
    }

    /**
     * Update the task status in metadata
     */
    protected function updateStatus(string $status, array $additionalData = []): void
    {
        app(TaskExecutionStore::class)->recordStatus(
            model: $this->model,
            task: $this->task,
            status: $status,
            data: $additionalData,
            jobContext: $this,
        );
    }

    protected function dispatchDependentTasks(): void
    {
        $dependentTaskKeys = TaskRegistry::getDependentTaskKeys($this->task->key);

        if ($dependentTaskKeys === []) {
            return;
        }

        ProcessTaskPipelineJob::dispatch(
            model: $this->model->fresh(),
            trigger: 'manual',
            taskFilter: $dependentTaskKeys,
        )->onQueue('tasks');
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

    /**
     * @return array{outcome?: string, outcome_counts?: array<string, int>}
     */
    private function outcomeData(): array
    {
        if ($this->outcome === null) {
            return [];
        }

        return array_filter([
            'outcome' => $this->outcome,
            'outcome_counts' => $this->outcomeCounts,
        ], fn ($value) => $value !== []);
    }
}
