# Task Pipeline System - Proposal

## Executive Summary

This proposal outlines a comprehensive redesign of how automated tasks are triggered and executed in Spark. The new **Task Pipeline System** provides a centralized, extensible, and transparent architecture for running tasks against Events, EventObjects, Blocks, and Integration instances.

### Key Goals

1. **Centralized Management** - Single registry for all task definitions
2. **Execution Tracking** - Know what's been run, when, and with what result
3. **Re-run Capability** - Easily retry failed or outdated tasks
4. **Extensibility** - Plugins can register custom tasks
5. **Transparency** - Clear visibility into task execution via UI
6. **Race Condition Prevention** - Single-process queue for sequential execution
7. **Dependency Management** - Tasks run in the correct order

---

## Current State Analysis

### Existing Tasks

| Task Type | Trigger Method | Queue | Issues |
|-----------|---------------|-------|--------|
| Embedding Generation | Observer (create/update) | `embeddings` | No tracking, can't retry |
| Receipt Matching (Forward) | Webhook handler | `default` | Scattered logic |
| Receipt Matching (Reverse) | AppServiceProvider boot listener | `default` | Hard to discover |
| Anomaly Detection (RT) | Event model boot method | `default` | Hidden in model |
| Anomaly Detection (Retro) | Daily schedule | `default` | Separate from RT |
| Trend Detection | Daily schedule | `default` | No per-item tracking |
| Metric Statistics | Hourly schedule | `default` | Batch only |
| Transaction Linking | Manual command | `default` | Never automatic |

### Current Problems

1. **Discovery** - Hard to find what tasks exist and where they're triggered
2. **No Execution History** - Can't see what's been run against an item
3. **No Re-run** - Can't easily retry failed or outdated processing
4. **Scattered Triggers** - Observers, ServiceProvider, Model boot, Webhooks, Schedules
5. **Configuration Fragmentation** - Conditions spread across multiple files
6. **Extensibility** - Adding tasks requires modifying core files
7. **Race Conditions** - Possible with multiple queue workers
8. **Dependency Management** - No guarantee tasks run in correct order

---

## Proposed Architecture

### Core Components

```
┌─────────────────────────────────────────────────────────────┐
│                      Task Registry                          │
│  - Registers all available tasks                            │
│  - Defines conditions, dependencies, applicability          │
│  - Plugin tasks auto-discovered                             │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│                    Task Dispatcher                          │
│  - Determines applicable tasks for an item                  │
│  - Resolves dependencies and execution order                │
│  - Dispatches jobs to 'tasks' queue                         │
│  - Updates metadata with execution tracking                 │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│              'tasks' Queue (Single Worker)                  │
│  - Processes tasks sequentially                             │
│  - Prevents race conditions                                 │
│  - Runs tasks in dependency order                           │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│                  Individual Task Jobs                       │
│  - Execute specific task logic                              │
│  - Report results back to dispatcher                        │
│  - Retry with exponential backoff on failure                │
└─────────────────────────────────────────────────────────────┘
```

### Data Flow

1. **Event/Object/Block Created or Updated**
   - Observer dispatches `ProcessTaskPipelineJob` to `tasks` queue
   - Passes model and trigger type ('created' or 'updated')

2. **Task Dispatcher Runs**
   - Loads task registry
   - Filters tasks applicable to this model
   - Checks execution history in metadata
   - Resolves dependency order
   - Dispatches applicable tasks

3. **Individual Tasks Execute**
   - Run in dependency order
   - Update metadata on start/completion/failure
   - Track timestamps, attempts, errors

4. **Scheduled Tasks**
   - Cron triggers batch job (e.g., `CalculateMetricStatisticsJob`)
   - Batch job identifies items needing processing
   - Dispatches `ProcessTaskPipelineJob` for each item with specific task filter

5. **Manual Re-runs**
   - UI or command triggers `ProcessTaskPipelineJob`
   - Optional: Force re-run even if already executed
   - Optional: Filter to specific task(s)

---

## Technical Design

### 1. Task Definition Structure

```php
// app/Services/TaskPipeline/TaskDefinition.php
class TaskDefinition
{
    public function __construct(
        public string $key,                          // Unique identifier (e.g., 'generate_embedding')
        public string $name,                         // Display name
        public string $description,                  // What it does
        public string $jobClass,                     // Job to dispatch
        public array $appliesTo,                     // ['event', 'block', 'object', 'integration']
        public array $conditions = [],               // Filtering conditions
        public array $dependencies = [],             // Task keys that must run first
        public string $queue = 'tasks',              // Queue name
        public int $priority = 50,                   // Execution priority (higher = first)
        public bool $runOnCreate = true,             // Run when item created
        public bool $runOnUpdate = false,            // Run when item updated
        public ?Closure $shouldRun = null,          // Custom condition callback
        public ?string $registeredBy = null,         // Plugin class that registered it
    ) {}

    public function isApplicableTo(Model $model): bool
    {
        // Check model type
        if (!in_array($this->getModelType($model), $this->appliesTo)) {
            return false;
        }

        // Check conditions
        foreach ($this->conditions as $field => $value) {
            if (is_array($value)) {
                if (!in_array($model->$field, $value)) {
                    return false;
                }
            } else {
                if ($model->$field !== $value) {
                    return false;
                }
            }
        }

        // Check custom condition
        if ($this->shouldRun && !($this->shouldRun)($model)) {
            return false;
        }

        return true;
    }

    private function getModelType(Model $model): string
    {
        return match (get_class($model)) {
            Event::class => 'event',
            Block::class => 'block',
            EventObject::class => 'object',
            Integration::class => 'integration',
            default => throw new InvalidArgumentException('Unsupported model type'),
        };
    }
}
```

### 2. Task Registry

```php
// app/Services/TaskPipeline/TaskRegistry.php
class TaskRegistry
{
    protected static array $tasks = [];

    public static function register(TaskDefinition $task): void
    {
        static::$tasks[$task->key] = $task;
    }

    public static function getTask(string $key): ?TaskDefinition
    {
        return static::$tasks[$key] ?? null;
    }

    public static function getAllTasks(): array
    {
        return static::$tasks;
    }

    public static function getTasksForModel(Model $model, string $trigger = 'created'): Collection
    {
        return collect(static::$tasks)
            ->filter(fn(TaskDefinition $task) => $task->isApplicableTo($model))
            ->filter(function(TaskDefinition $task) use ($trigger) {
                return ($trigger === 'created' && $task->runOnCreate)
                    || ($trigger === 'updated' && $task->runOnUpdate)
                    || $trigger === 'manual';
            })
            ->sortByDesc('priority')
            ->values();
    }

    public static function resolveExecutionOrder(Collection $tasks): Collection
    {
        $ordered = collect();
        $remaining = $tasks->keyBy('key');

        while ($remaining->isNotEmpty()) {
            $resolved = $remaining->filter(function($task) use ($ordered) {
                // All dependencies already in ordered list?
                return collect($task->dependencies)
                    ->every(fn($dep) => $ordered->has($dep));
            });

            if ($resolved->isEmpty() && $remaining->isNotEmpty()) {
                // Circular dependency - throw error
                throw new CircularDependencyException(
                    'Circular dependency detected in tasks: ' . $remaining->pluck('key')->join(', ')
                );
            }

            $ordered = $ordered->merge($resolved);
            $remaining = $remaining->except($resolved->keys()->toArray());
        }

        return $ordered;
    }
}
```

### 3. Task Execution Metadata Structure

Stored in existing `metadata` JSON field under key `task_executions`:

```json
{
  "task_executions": {
    "generate_embedding": {
      "last_attempt": {
        "started_at": "2025-11-29T10:30:00Z",
        "completed_at": "2025-11-29T10:30:05Z",
        "status": "success",
        "attempts": 1,
        "error": null,
        "triggered_by": "created"
      },
      "last_success": {
        "started_at": "2025-11-29T10:30:00Z",
        "completed_at": "2025-11-29T10:30:05Z",
        "status": "success",
        "attempts": 1,
        "triggered_by": "created"
      }
    },
    "detect_anomalies": {
      "last_attempt": {
        "started_at": "2025-11-29T10:30:10Z",
        "completed_at": "2025-11-29T10:30:15Z",
        "status": "failed",
        "attempts": 3,
        "error": "Insufficient data points",
        "triggered_by": "created"
      },
      "last_success": null
    },
    "match_receipt": {
      "last_attempt": {
        "started_at": "2025-11-29T10:30:08Z",
        "completed_at": "2025-11-29T10:30:08Z",
        "status": "not_applicable",
        "triggered_by": "created"
      },
      "last_success": null
    }
  }
}
```

**Status Values:**
- `success` - Task completed successfully
- `failed` - Task failed after retries
- `running` - Currently executing
- `pending` - Queued but not started
- `not_applicable` - Conditions not met (skipped)

### 4. Task Dispatcher Job

```php
// app/Jobs/TaskPipeline/ProcessTaskPipelineJob.php
class ProcessTaskPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 1; // Don't retry the dispatcher itself
    public $queue = 'tasks';

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
        if (!$this->force) {
            $tasks = $tasks->reject(function($task) {
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

    protected function wasSuccessfullyExecuted(TaskDefinition $task): bool
    {
        $executions = $this->model->metadata['task_executions'] ?? [];
        $lastAttempt = $executions[$task->key]['last_attempt'] ?? null;

        return $lastAttempt && $lastAttempt['status'] === 'success';
    }

    protected function dispatchTask(TaskDefinition $task): void
    {
        // Check if applicable
        if (!$task->isApplicableTo($this->model)) {
            $this->markNotApplicable($task);
            return;
        }

        // Mark as pending
        $this->updateTaskStatus($task, 'pending', [
            'started_at' => now(),
            'triggered_by' => $this->trigger,
        ]);

        // Dispatch to appropriate queue
        $jobClass = $task->jobClass;
        dispatch(new $jobClass($this->model, $task))->onQueue($task->queue);
    }

    protected function updateTaskStatus(TaskDefinition $task, string $status, array $data): void
    {
        $metadata = $this->model->metadata ?? [];
        $executions = $metadata['task_executions'] ?? [];

        $executions[$task->key]['last_attempt'] = array_merge($data, [
            'status' => $status,
        ]);

        $metadata['task_executions'] = $executions;

        // Update without triggering observers
        $this->model->withoutEvents(function() use ($metadata) {
            $this->model->update(['metadata' => $metadata]);
        });
    }

    protected function markNotApplicable(TaskDefinition $task): void
    {
        $this->updateTaskStatus($task, 'not_applicable', [
            'started_at' => now(),
            'completed_at' => now(),
            'triggered_by' => $this->trigger,
        ]);
    }
}
```

### 5. Base Task Job

```php
// app/Jobs/TaskPipeline/BaseTaskJob.php
abstract class BaseTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 3;
    public $backoff = [30, 120, 300]; // 30s, 2m, 5m

    public function __construct(
        public Model $model,
        public TaskDefinition $task,
    ) {}

    public function handle(): void
    {
        $this->updateStatus('running');

        try {
            $this->execute();
            $this->updateStatus('success', ['completed_at' => now()]);

        } catch (Exception $e) {
            // Report to Sentry
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }

            $this->updateStatus('failed', [
                'completed_at' => now(),
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            throw $e; // Re-throw for Laravel retry logic
        }
    }

    abstract protected function execute(): void;

    protected function updateStatus(string $status, array $additionalData = []): void
    {
        $metadata = $this->model->metadata ?? [];
        $executions = $metadata['task_executions'] ?? [];

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

        $metadata['task_executions'] = $executions;

        // Update without triggering observers
        $this->model->withoutEvents(function() use ($metadata) {
            $this->model->update(['metadata' => $metadata]);
        });
    }
}
```

### 6. Example Task Implementation

```php
// app/Jobs/TaskPipeline/Tasks/GenerateEmbeddingTask.php
class GenerateEmbeddingTask extends BaseTaskJob
{
    public $queue = 'embeddings'; // Can override base queue

    protected function execute(): void
    {
        // Existing embedding generation logic
        $service = app(OpenAIService::class);

        $text = $this->model instanceof Event
            ? $this->model->getEmbeddingText()
            : $this->model->title . ' ' . $this->model->content;

        $embedding = $service->generateEmbedding($text);

        $this->model->withoutEvents(function() use ($embedding) {
            $this->model->update(['embedding' => $embedding]);
        });
    }
}
```

### 7. Task Registration (Core Tasks)

```php
// app/Providers/TaskPipelineServiceProvider.php
class TaskPipelineServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerCoreTasks();
        $this->registerPluginTasks();
    }

    protected function registerCoreTasks(): void
    {
        // Embedding Generation
        TaskRegistry::register(new TaskDefinition(
            key: 'generate_embedding',
            name: 'Generate Embedding',
            description: 'Generate AI embedding for semantic search',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event', 'block', 'object'],
            conditions: [],
            dependencies: [],
            queue: 'embeddings',
            priority: 100,
            runOnCreate: true,
            runOnUpdate: true,
            shouldRun: fn() => config('services.openai.api_key') !== null,
        ));

        // Metric Statistics
        TaskRegistry::register(new TaskDefinition(
            key: 'calculate_metric_stats',
            name: 'Calculate Metric Statistics',
            description: 'Calculate mean, stddev, and normal bounds',
            jobClass: CalculateMetricStatsTask::class,
            appliesTo: ['event'],
            conditions: [
                'domain' => ['health', 'money', 'media'],
            ],
            dependencies: [],
            queue: 'tasks',
            priority: 90,
            runOnCreate: false, // Only scheduled
            runOnUpdate: false,
            shouldRun: function($model) {
                return $model->value !== null && $model->value_unit !== null;
            },
        ));

        // Anomaly Detection
        TaskRegistry::register(new TaskDefinition(
            key: 'detect_anomalies',
            name: 'Detect Anomalies',
            description: 'Detect if metric value is anomalous',
            jobClass: DetectAnomaliesTask::class,
            appliesTo: ['event'],
            conditions: [],
            dependencies: ['calculate_metric_stats'], // Requires stats first
            queue: 'tasks',
            priority: 80,
            runOnCreate: true,
            runOnUpdate: false,
            shouldRun: function($model) {
                if (!$model->value || !$model->value_unit) {
                    return false;
                }

                $integration = $model->integration;
                if (!$integration) {
                    return false;
                }

                $mode = $integration->getAnomalyDetectionMode();
                return $mode === 'realtime';
            },
        ));

        // Trend Detection
        TaskRegistry::register(new TaskDefinition(
            key: 'detect_trends',
            name: 'Detect Trends',
            description: 'Detect week/month/quarter trends',
            jobClass: DetectTrendsTask::class,
            appliesTo: ['event'],
            conditions: [],
            dependencies: ['calculate_metric_stats'],
            queue: 'tasks',
            priority: 70,
            runOnCreate: false, // Only scheduled
            runOnUpdate: false,
        ));

        // Receipt Matching (Forward)
        TaskRegistry::register(new TaskDefinition(
            key: 'match_receipt_to_transaction',
            name: 'Match Receipt to Transaction',
            description: 'Find matching transaction for receipt',
            jobClass: MatchReceiptToTransactionTask::class,
            appliesTo: ['event'],
            conditions: [
                'service' => 'receipt',
                'action' => 'had_receipt_from',
            ],
            dependencies: ['generate_embedding'],
            queue: 'tasks',
            priority: 60,
            runOnCreate: true,
        ));

        // Receipt Matching (Reverse)
        TaskRegistry::register(new TaskDefinition(
            key: 'find_receipt_for_transaction',
            name: 'Find Receipt for Transaction',
            description: 'Find matching receipt for transaction',
            jobClass: FindReceiptForTransactionTask::class,
            appliesTo: ['event'],
            conditions: [
                'service' => ['monzo', 'gocardless'],
                'domain' => 'money',
                'action' => [
                    'had_card_payment',
                    'had_transaction',
                    'had_account_debit',
                    'had_faster_payment',
                ],
            ],
            dependencies: ['generate_embedding'],
            queue: 'tasks',
            priority: 60,
            runOnCreate: true,
        ));

        // Transaction Linking
        TaskRegistry::register(new TaskDefinition(
            key: 'link_transactions',
            name: 'Link Related Transactions',
            description: 'Find and link related transactions',
            jobClass: LinkTransactionsTask::class,
            appliesTo: ['event'],
            conditions: [
                'service' => ['monzo', 'gocardless'],
                'domain' => 'money',
            ],
            dependencies: ['generate_embedding'],
            queue: 'tasks',
            priority: 50,
            runOnCreate: true,
        ));
    }

    protected function registerPluginTasks(): void
    {
        foreach (PluginRegistry::getAllPlugins() as $pluginClass) {
            if (in_array(SupportsTaskPipeline::class, class_implements($pluginClass))) {
                $tasks = $pluginClass::getTaskDefinitions();

                foreach ($tasks as $task) {
                    // Mark as registered by plugin
                    $task->registeredBy = $pluginClass;
                    TaskRegistry::register($task);
                }
            }
        }
    }
}
```

### 8. Plugin Contract

```php
// app/Integrations/Contracts/SupportsTaskPipeline.php
interface SupportsTaskPipeline
{
    /**
     * Get task definitions for this plugin
     *
     * @return array<TaskDefinition>
     */
    public static function getTaskDefinitions(): array;
}
```

### 9. Example Plugin Implementation

```php
// app/Integrations/Spotify/SpotifyPlugin.php
class SpotifyPlugin extends OAuthPlugin implements SupportsTaskPipeline
{
    public static function getTaskDefinitions(): array
    {
        return [
            new TaskDefinition(
                key: 'spotify_generate_playlist_summary',
                name: 'Generate Playlist Summary',
                description: 'Create AI summary of playlist listening habits',
                jobClass: GenerateSpotifyPlaylistSummaryTask::class,
                appliesTo: ['object'],
                conditions: [
                    'service' => 'spotify',
                    'concept' => 'playlist',
                ],
                dependencies: ['generate_embedding'],
                queue: 'tasks',
                priority: 40,
                runOnCreate: false,
                runOnUpdate: true,
            ),
        ];
    }
}
```

### 10. Observer Updates

```php
// app/Observers/EventObserver.php
class EventObserver
{
    public function created(Event $event): void
    {
        ProcessTaskPipelineJob::dispatch($event, 'created')->onQueue('tasks');
    }

    public function updated(Event $event): void
    {
        ProcessTaskPipelineJob::dispatch($event, 'updated')->onQueue('tasks');
    }
}

// Similar for BlockObserver, EventObjectObserver
```

### 11. Horizon Configuration

```php
// config/horizon.php
return [
    // ...
    'environments' => [
        'production' => [
            // Existing workers...

            'tasks' => [
                'connection' => 'redis',
                'queue' => ['tasks'],
                'balance' => 'simple',
                'processes' => 1,  // SINGLE WORKER - prevents race conditions
                'tries' => 3,
                'timeout' => 300,
                'backoff' => [30, 120, 300],
            ],
        ],

        'local' => [
            // Existing workers...

            'tasks' => [
                'connection' => 'redis',
                'queue' => ['tasks'],
                'balance' => 'simple',
                'processes' => 1,  // SINGLE WORKER
                'tries' => 3,
                'timeout' => 300,
                'backoff' => [30, 120, 300],
            ],
        ],
    ],
];
```

### 12. Scheduled Task Integration

```php
// routes/console.php

// Metric Statistics - Hourly
Schedule::job(new DispatchMetricStatisticsTasksJob)->hourly();

// Trend Detection - Daily
Schedule::job(new DispatchTrendDetectionTasksJob)->daily();

// Retrospective Anomaly Detection - Daily
Schedule::job(new DispatchRetrospectiveAnomalyTasksJob)->daily();


// app/Jobs/TaskPipeline/DispatchMetricStatisticsTasksJob.php
class DispatchMetricStatisticsTasksJob
{
    public function handle(): void
    {
        // Find all events with metrics that need stats calculated
        Event::query()
            ->whereNotNull('value')
            ->whereNotNull('value_unit')
            ->where('time', '>=', now()->subDays(1))
            ->chunk(100, function($events) {
                foreach ($events as $event) {
                    ProcessTaskPipelineJob::dispatch(
                        model: $event,
                        trigger: 'scheduled',
                        taskFilter: ['calculate_metric_stats'],
                    )->onQueue('tasks');
                }
            });
    }
}
```

---

## Migration Strategy

### 1. Initial Task Execution State Migration

```php
// database/migrations/2025_11_29_000001_populate_initial_task_executions.php
class PopulateInitialTaskExecutions extends Migration
{
    public function up(): void
    {
        Artisan::call('task-pipeline:populate-initial-state');
    }
}

// app/Console/Commands/PopulateInitialTaskExecutionState.php
class PopulateInitialTaskExecutionState extends Command
{
    protected $signature = 'task-pipeline:populate-initial-state
                            {--model= : Model type (event, block, object, integration)}
                            {--limit= : Limit number of records}
                            {--dry-run : Preview without making changes}';

    protected $description = 'Populate initial task execution state in metadata';

    public function handle(): void
    {
        $this->info('Populating initial task execution states...');

        $models = $this->getModelsToProcess();

        foreach ($models as $modelClass => $modelName) {
            $this->processModel($modelClass, $modelName);
        }

        $this->info('Done!');
    }

    protected function processModel(string $modelClass, string $modelName): void
    {
        $query = $modelClass::query();

        if ($limit = $this->option('limit')) {
            $query->limit($limit);
        }

        $bar = $this->output->createProgressBar($query->count());
        $bar->start();

        $query->chunk(100, function($items) use ($bar, $modelName) {
            foreach ($items as $item) {
                $this->populateTaskExecutions($item, $modelName);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
    }

    protected function populateTaskExecutions(Model $model, string $modelType): void
    {
        // Get all tasks that could apply to this model
        $tasks = TaskRegistry::getTasksForModel($model, 'created');

        $metadata = $model->metadata ?? [];
        $executions = [];

        foreach ($tasks as $task) {
            // Infer execution state from model state
            $executionState = $this->inferExecutionState($model, $task);

            if ($executionState) {
                $executions[$task->key] = $executionState;
            }
        }

        if (!empty($executions)) {
            $metadata['task_executions'] = $executions;

            if (!$this->option('dry-run')) {
                $model->withoutEvents(function() use ($model, $metadata) {
                    $model->update(['metadata' => $metadata]);
                });
            } else {
                $this->line("Would update {$modelType} #{$model->id} with " . count($executions) . " task executions");
            }
        }
    }

    protected function inferExecutionState(Model $model, TaskDefinition $task): ?array
    {
        // Try to infer if task has already been executed

        if ($task->key === 'generate_embedding') {
            if ($model->embedding) {
                return [
                    'last_attempt' => [
                        'started_at' => $model->created_at,
                        'completed_at' => $model->created_at,
                        'status' => 'success',
                        'attempts' => 1,
                        'triggered_by' => 'migration',
                    ],
                    'last_success' => [
                        'started_at' => $model->created_at,
                        'completed_at' => $model->created_at,
                        'status' => 'success',
                        'attempts' => 1,
                        'triggered_by' => 'migration',
                    ],
                ];
            }
        }

        // For other tasks, mark as not run (null)
        // This allows them to be run on next trigger
        return null;
    }

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
            return array_filter($all, fn($name) => $name === $modelOption);
        }

        return $all;
    }
}
```

### 2. Migration Execution Plan

1. **Deploy Code** - Deploy new task pipeline system (inactive)
2. **Run Migration** - Populate initial states with `--dry-run` first
3. **Verify** - Check sample records have correct states
4. **Activate** - Enable observers to start using new system
5. **Monitor** - Watch Sentry and queue metrics for issues
6. **Remove Old Code** - Clean up old observer logic after stabilization

---

## UI/UX Design

### 1. Event/Block/Object Show Page - Task Drawer Section

```blade
<!-- resources/views/components/task-execution-drawer.blade.php -->
@props(['model'])

@php
use App\Services\TaskPipeline\TaskRegistry;

$executions = $model->metadata['task_executions'] ?? [];
$allTasks = TaskRegistry::getTasksForModel($model, 'manual');

$showNotApplicable = request()->boolean('show_not_applicable', false);
@endphp

<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold">Task Executions</h3>

        <div class="flex gap-2">
            <!-- Toggle Not Applicable -->
            <label class="label cursor-pointer gap-2">
                <span class="label-text text-xs">Show N/A</span>
                <input
                    type="checkbox"
                    class="toggle toggle-sm"
                    wire:model.live="showNotApplicable"
                />
            </label>

            <!-- Re-run All Button -->
            <button
                class="btn btn-sm btn-outline"
                wire:click="rerunAllTasks"
                wire:loading.attr="disabled"
            >
                <x-icon name="o-arrow-path" class="w-4 h-4" />
                Re-run All
            </button>
        </div>
    </div>

    <div class="space-y-2">
        @forelse($allTasks as $task)
            @php
            $execution = $executions[$task->key] ?? null;
            $lastAttempt = $execution['last_attempt'] ?? null;
            $lastSuccess = $execution['last_success'] ?? null;
            $status = $lastAttempt['status'] ?? 'not_run';

            // Skip not applicable if toggle is off
            if (!$showNotApplicable && $status === 'not_applicable') {
                continue;
            }
            @endphp

            <div class="card bg-base-200 border @if($status === 'failed') border-error @elseif($status === 'success') border-success @endif">
                <div class="card-body p-4 gap-2">
                    <!-- Header -->
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <h4 class="font-semibold">{{ $task->name }}</h4>

                                <!-- Status Badge -->
                                @if($status === 'success')
                                    <span class="badge badge-success badge-sm gap-1">
                                        <x-icon name="o-check-circle" class="w-3 h-3" />
                                        Success
                                    </span>
                                @elseif($status === 'failed')
                                    <span class="badge badge-error badge-sm gap-1">
                                        <x-icon name="o-x-circle" class="w-3 h-3" />
                                        Failed
                                    </span>
                                @elseif($status === 'running')
                                    <span class="badge badge-warning badge-sm gap-1">
                                        <span class="loading loading-spinner loading-xs"></span>
                                        Running
                                    </span>
                                @elseif($status === 'pending')
                                    <span class="badge badge-info badge-sm gap-1">
                                        <x-icon name="o-clock" class="w-3 h-3" />
                                        Pending
                                    </span>
                                @elseif($status === 'not_applicable')
                                    <span class="badge badge-ghost badge-sm gap-1">
                                        <x-icon name="o-minus-circle" class="w-3 h-3" />
                                        Not Applicable
                                    </span>
                                @else
                                    <span class="badge badge-outline badge-sm">Not Run</span>
                                @endif
                            </div>

                            <p class="text-sm text-base-content/70 mt-1">{{ $task->description }}</p>
                        </div>

                        <!-- Re-run Button -->
                        <button
                            class="btn btn-xs btn-outline"
                            wire:click="rerunTask('{{ $task->key }}')"
                            wire:loading.attr="disabled"
                            @if($status === 'running' || $status === 'pending') disabled @endif
                        >
                            <x-icon name="o-arrow-path" class="w-3 h-3" />
                            Re-run
                        </button>
                    </div>

                    <!-- Execution Details -->
                    @if($lastAttempt)
                        <div class="text-xs text-base-content/60 space-y-1">
                            <div class="flex items-center gap-4">
                                <span>Last run: {{ Carbon\Carbon::parse($lastAttempt['started_at'])->diffForHumans() }}</span>
                                @if(isset($lastAttempt['attempts']) && $lastAttempt['attempts'] > 1)
                                    <span>Attempts: {{ $lastAttempt['attempts'] }}</span>
                                @endif
                                <span>Trigger: {{ $lastAttempt['triggered_by'] ?? 'unknown' }}</span>
                            </div>

                            @if($status === 'failed' && isset($lastAttempt['error']))
                                <div class="alert alert-error alert-sm mt-2">
                                    <x-icon name="o-exclamation-triangle" class="w-4 h-4" />
                                    <span>{{ $lastAttempt['error'] }}</span>
                                </div>
                            @endif
                        </div>
                    @endif

                    <!-- Dependencies -->
                    @if(!empty($task->dependencies))
                        <div class="text-xs text-base-content/50">
                            Depends on: {{ collect($task->dependencies)->map(fn($key) => TaskRegistry::getTask($key)?->name ?? $key)->join(', ') }}
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="text-center text-base-content/60 py-8">
                No tasks available for this item
            </div>
        @endforelse
    </div>
</div>
```

### 2. Admin Task Overview Page

```blade
<!-- resources/views/admin/task-pipeline.blade.php -->
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-3xl font-bold">Task Pipeline</h1>

        <div class="flex gap-2">
            <a href="{{ route('admin.task-pipeline.registry') }}" class="btn btn-outline">
                View Registry
            </a>
        </div>
    </div>

    <!-- Stats Overview -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="stat bg-base-200 rounded-lg">
            <div class="stat-title">Registered Tasks</div>
            <div class="stat-value">{{ $totalTasks }}</div>
            <div class="stat-desc">{{ $pluginTasks }} from plugins</div>
        </div>

        <div class="stat bg-base-200 rounded-lg">
            <div class="stat-title">Tasks Pending</div>
            <div class="stat-value text-info">{{ $pendingTasks }}</div>
            <div class="stat-desc">In queue</div>
        </div>

        <div class="stat bg-base-200 rounded-lg">
            <div class="stat-title">Failed (24h)</div>
            <div class="stat-value text-error">{{ $failedTasks }}</div>
            <div class="stat-desc">{{ $failureRate }}% failure rate</div>
        </div>

        <div class="stat bg-base-200 rounded-lg">
            <div class="stat-title">Success (24h)</div>
            <div class="stat-value text-success">{{ $successTasks }}</div>
            <div class="stat-desc">{{ $successRate }}% success rate</div>
        </div>
    </div>

    <!-- Task Registry Table -->
    <div class="card bg-base-200">
        <div class="card-body">
            <h2 class="card-title">Registered Tasks</h2>

            <table class="table">
                <thead>
                    <tr>
                        <th>Task</th>
                        <th>Applies To</th>
                        <th>Dependencies</th>
                        <th>Registered By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($tasks as $task)
                        <tr>
                            <td>
                                <div class="font-semibold">{{ $task->name }}</div>
                                <div class="text-xs text-base-content/60">{{ $task->description }}</div>
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    @foreach($task->appliesTo as $type)
                                        <span class="badge badge-sm">{{ $type }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td>
                                @if(!empty($task->dependencies))
                                    <div class="text-xs">{{ count($task->dependencies) }} dependencies</div>
                                @else
                                    <span class="text-base-content/40">None</span>
                                @endif
                            </td>
                            <td>
                                @if($task->registeredBy)
                                    <span class="badge badge-outline badge-sm">Plugin</span>
                                @else
                                    <span class="text-base-content/60">Core</span>
                                @endif
                            </td>
                            <td>
                                <button class="btn btn-xs btn-ghost" onclick="viewTaskDetails('{{ $task->key }}')">
                                    Details
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Failures -->
    <div class="card bg-base-200">
        <div class="card-body">
            <h2 class="card-title">Recent Failures</h2>

            <table class="table">
                <thead>
                    <tr>
                        <th>Task</th>
                        <th>Model</th>
                        <th>Error</th>
                        <th>When</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentFailures as $failure)
                        <tr>
                            <td>{{ $failure['task_name'] }}</td>
                            <td>
                                <a href="{{ $failure['model_url'] }}" class="link">
                                    {{ $failure['model_type'] }} #{{ $failure['model_id'] }}
                                </a>
                            </td>
                            <td class="text-xs max-w-xs truncate" title="{{ $failure['error'] }}">
                                {{ $failure['error'] }}
                            </td>
                            <td>{{ $failure['failed_at'] }}</td>
                            <td>
                                <button class="btn btn-xs btn-outline" wire:click="retryFailure('{{ $failure['id'] }}')">
                                    Retry
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
```

### 3. Livewire Components

```php
// app/Livewire/TaskExecutionDrawer.php
class TaskExecutionDrawer extends Component
{
    public Model $model;
    public bool $showNotApplicable = false;

    public function rerunTask(string $taskKey): void
    {
        ProcessTaskPipelineJob::dispatch(
            model: $this->model,
            trigger: 'manual',
            taskFilter: [$taskKey],
            force: true,
        )->onQueue('tasks');

        $this->dispatch('task-rerun-initiated', taskKey: $taskKey);

        $this->js("alert('Task queued for re-run')");
    }

    public function rerunAllTasks(): void
    {
        ProcessTaskPipelineJob::dispatch(
            model: $this->model,
            trigger: 'manual',
            force: true,
        )->onQueue('tasks');

        $this->dispatch('tasks-rerun-initiated');

        $this->js("alert('All tasks queued for re-run')");
    }

    public function render()
    {
        return view('livewire.task-execution-drawer');
    }
}
```

---

## Command-Line Interface

```php
// app/Console/Commands/TaskPipelineCommands.php

// Re-run specific task for specific item
class RerunTaskCommand extends Command
{
    protected $signature = 'task-pipeline:rerun
                            {task : Task key}
                            {model : Model type (event, block, object, integration)}
                            {id : Model ID}
                            {--force : Force re-run even if successful}';

    protected $description = 'Re-run a specific task for a specific item';

    public function handle(): void
    {
        $modelClass = $this->getModelClass($this->argument('model'));
        $model = $modelClass::findOrFail($this->argument('id'));
        $taskKey = $this->argument('task');

        ProcessTaskPipelineJob::dispatch(
            model: $model,
            trigger: 'manual',
            taskFilter: [$taskKey],
            force: $this->option('force'),
        )->onQueue('tasks');

        $this->info("Task '{$taskKey}' queued for re-run on {$this->argument('model')} #{$this->argument('id')}");
    }
}

// Bulk re-run for filtered items
class BulkRerunTasksCommand extends Command
{
    protected $signature = 'task-pipeline:bulk-rerun
                            {task : Task key}
                            {model : Model type}
                            {--service= : Filter by service}
                            {--domain= : Filter by domain}
                            {--since= : Only items created since date}
                            {--limit= : Limit number of items}
                            {--force : Force re-run even if successful}
                            {--dry-run : Preview without dispatching}';

    protected $description = 'Bulk re-run a task for filtered items';

    public function handle(): void
    {
        $modelClass = $this->getModelClass($this->argument('model'));
        $query = $modelClass::query();

        // Apply filters
        if ($service = $this->option('service')) {
            $query->where('service', $service);
        }

        if ($domain = $this->option('domain')) {
            $query->where('domain', $domain);
        }

        if ($since = $this->option('since')) {
            $query->where('created_at', '>=', Carbon::parse($since));
        }

        if ($limit = $this->option('limit')) {
            $query->limit($limit);
        }

        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info("Would re-run task '{$this->argument('task')}' for {$count} items");
            return;
        }

        if (!$this->confirm("Re-run task for {$count} items?")) {
            return;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $query->chunk(100, function($items) use ($bar) {
            foreach ($items as $item) {
                ProcessTaskPipelineJob::dispatch(
                    model: $item,
                    trigger: 'manual',
                    taskFilter: [$this->argument('task')],
                    force: $this->option('force'),
                )->onQueue('tasks');

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Tasks dispatched!');
    }
}

// List all registered tasks
class ListTasksCommand extends Command
{
    protected $signature = 'task-pipeline:list
                            {--plugin= : Filter by plugin}
                            {--applies-to= : Filter by model type}';

    protected $description = 'List all registered tasks';

    public function handle(): void
    {
        $tasks = collect(TaskRegistry::getAllTasks());

        if ($plugin = $this->option('plugin')) {
            $tasks = $tasks->filter(fn($task) => $task->registeredBy === $plugin);
        }

        if ($appliesTo = $this->option('applies-to')) {
            $tasks = $tasks->filter(fn($task) => in_array($appliesTo, $task->appliesTo));
        }

        $this->table(
            ['Key', 'Name', 'Applies To', 'Dependencies', 'Priority', 'Source'],
            $tasks->map(fn($task) => [
                $task->key,
                $task->name,
                implode(', ', $task->appliesTo),
                count($task->dependencies),
                $task->priority,
                $task->registeredBy ? 'Plugin' : 'Core',
            ])->toArray()
        );
    }
}
```

---

## Benefits

### 1. Centralization
- **Single source of truth** for all task definitions
- Easy to discover what tasks exist
- Simple to understand execution flow

### 2. Transparency
- **Complete execution history** in metadata
- UI visibility into what's been run
- Easy debugging of failures

### 3. Extensibility
- **Plugins register tasks** without modifying core code
- New tasks added via registry, not scattered observers
- Dependency system ensures correct execution order

### 4. Reliability
- **Single-process queue** prevents race conditions
- Retry logic with exponential backoff
- Sentry integration for monitoring

### 5. Flexibility
- **Re-run capabilities** at item, task, or bulk level
- Manual triggers via UI or CLI
- Scheduled task integration

### 6. Maintainability
- **Clear separation of concerns** - registry, dispatcher, jobs
- Consistent patterns across all tasks
- Easy to test individual components

---

## Risks & Mitigation

### Risk 1: Performance Impact
**Concern:** Single-process queue could become bottleneck

**Mitigation:**
- Keep `embeddings` queue separate (already high volume)
- Monitor queue depth in Horizon
- Add more single-process workers if needed (still prevents race on same item)
- Use job priorities for critical tasks

### Risk 2: Metadata Size
**Concern:** Task execution history could bloat metadata field

**Mitigation:**
- Only store last attempt + last success (not full history)
- Monitor metadata field sizes
- Add cleanup command if needed
- Consider dedicated table if metadata grows too large (future enhancement)

### Risk 3: Migration Complexity
**Concern:** Populating initial states for existing records

**Mitigation:**
- Dry-run mode to preview changes
- Limit option for testing on subset
- Inference logic for common tasks (embeddings)
- Can run migration multiple times safely

### Risk 4: Breaking Changes
**Concern:** Removing old observer logic could break existing behavior

**Mitigation:**
- Deploy new system inactive first
- Run both systems in parallel briefly
- Comprehensive testing before cutover
- Easy rollback by disabling new observers

### Risk 5: Circular Dependencies
**Concern:** Plugin tasks could create circular dependencies

**Mitigation:**
- Detection in `resolveExecutionOrder()` throws exception
- Registry validation on boot
- Documentation and examples for plugin developers

---

## Implementation Phases

### Phase 1: Core Infrastructure (Week 1)
- [ ] Create `TaskDefinition` class
- [ ] Create `TaskRegistry` class
- [ ] Create `TaskPipelineServiceProvider`
- [ ] Create `ProcessTaskPipelineJob`
- [ ] Create `BaseTaskJob`
- [ ] Update Horizon config
- [ ] Add Sentry integration

### Phase 2: Core Task Migration (Week 2)
- [ ] Create task jobs for embeddings
- [ ] Create task jobs for anomaly detection
- [ ] Create task jobs for trend detection
- [ ] Create task jobs for metric statistics
- [ ] Create task jobs for receipt matching
- [ ] Create task jobs for transaction linking
- [ ] Register all core tasks

### Phase 3: Scheduled Task Integration (Week 2)
- [ ] Create `DispatchMetricStatisticsTasksJob`
- [ ] Create `DispatchTrendDetectionTasksJob`
- [ ] Create `DispatchRetrospectiveAnomalyTasksJob`
- [ ] Update console schedules

### Phase 4: Migration & Testing (Week 3)
- [ ] Create `PopulateInitialTaskExecutionState` command
- [ ] Run migration on dev/staging
- [ ] Test task execution
- [ ] Test re-run functionality
- [ ] Monitor Sentry for errors

### Phase 5: UI Development (Week 3-4)
- [ ] Create `TaskExecutionDrawer` component
- [ ] Create admin task pipeline page
- [ ] Add to event/block/object show pages
- [ ] Test UI interactions

### Phase 6: Plugin Support (Week 4)
- [ ] Create `SupportsTaskPipeline` interface
- [ ] Update plugin registration logic
- [ ] Document for plugin developers
- [ ] Create example plugin task

### Phase 7: CLI Tools (Week 4)
- [ ] Create `RerunTaskCommand`
- [ ] Create `BulkRerunTasksCommand`
- [ ] Create `ListTasksCommand`
- [ ] Document CLI usage

### Phase 8: Cutover & Cleanup (Week 5)
- [ ] Enable new observers
- [ ] Disable old observer logic
- [ ] Monitor production for issues
- [ ] Remove old code after stabilization

---

## Open Questions for Feedback

1. **Metadata vs Dedicated Table:**
   - Are you comfortable with task executions in metadata?
   - Should we plan for a dedicated table if metadata grows?

2. **Queue Separation:**
   - Keep embeddings separate or move to `tasks` queue?
   - Any other tasks that should have dedicated queues?

3. **Notification System:**
   - Should users be notified of task failures?
   - Integration health dashboard for task status?

4. **Audit Trail:**
   - Beyond metadata, should we log task executions to activity log?
   - Keep long-term analytics on task success/failure rates?

5. **Priority System:**
   - Is the proposed priority system (100 = highest) sufficient?
   - Should we support dynamic priorities based on conditions?

6. **Plugin Developer Experience:**
   - What documentation/examples do plugin developers need?
   - Should we provide helper methods or traits?

---

## Next Steps

1. **Review this proposal** and provide feedback
2. **Answer open questions** above
3. **Approve implementation plan** or suggest changes
4. **Begin Phase 1** development

---

## Appendix: File Structure

```
app/
├── Services/
│   └── TaskPipeline/
│       ├── TaskDefinition.php
│       ├── TaskRegistry.php
│       └── Exceptions/
│           └── CircularDependencyException.php
├── Jobs/
│   └── TaskPipeline/
│       ├── ProcessTaskPipelineJob.php
│       ├── BaseTaskJob.php
│       ├── DispatchMetricStatisticsTasksJob.php
│       ├── DispatchTrendDetectionTasksJob.php
│       ├── DispatchRetrospectiveAnomalyTasksJob.php
│       └── Tasks/
│           ├── GenerateEmbeddingTask.php
│           ├── CalculateMetricStatsTask.php
│           ├── DetectAnomaliesTask.php
│           ├── DetectTrendsTask.php
│           ├── MatchReceiptToTransactionTask.php
│           ├── FindReceiptForTransactionTask.php
│           └── LinkTransactionsTask.php
├── Integrations/
│   └── Contracts/
│       └── SupportsTaskPipeline.php
├── Providers/
│   └── TaskPipelineServiceProvider.php
├── Console/
│   └── Commands/
│       ├── PopulateInitialTaskExecutionState.php
│       ├── RerunTaskCommand.php
│       ├── BulkRerunTasksCommand.php
│       └── ListTasksCommand.php
├── Livewire/
│   ├── TaskExecutionDrawer.php
│   └── Admin/
│       └── TaskPipelineOverview.php
└── Observers/
    ├── EventObserver.php (updated)
    ├── BlockObserver.php (updated)
    └── EventObjectObserver.php (updated)

resources/
└── views/
    ├── components/
    │   └── task-execution-drawer.blade.php
    ├── livewire/
    │   └── task-execution-drawer.blade.php
    └── admin/
        ├── task-pipeline.blade.php
        └── task-registry.blade.php

config/
└── horizon.php (updated)

routes/
└── console.php (updated)

database/
└── migrations/
    └── 2025_11_29_000001_populate_initial_task_executions.php
```
