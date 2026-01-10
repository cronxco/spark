# Task Pipeline System

A comprehensive, extensible task execution system for managing automated tasks triggered by events, objects, blocks, and integration instances in Spark.

## Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [Core Concepts](#core-concepts)
- [Getting Started](#getting-started)
- [Creating Tasks](#creating-tasks)
- [Plugin Development](#plugin-development)
- [CLI Commands](#cli-commands)
- [UI Components](#ui-components)
- [Configuration](#configuration)
- [Testing](#testing)
- [Troubleshooting](#troubleshooting)

## Overview

The Task Pipeline System provides:

- **Centralized task management** - All tasks registered in one place
- **Execution tracking** - Complete history of what ran, when, and with what result
- **Dependency management** - Tasks run in correct order
- **Re-run capabilities** - Retry failed tasks or reprocess data
- **Plugin extensibility** - Plugins can register custom tasks
- **UI transparency** - View task status in admin and item pages
- **CLI tools** - Manage tasks from command line
- **Race condition prevention** - Single-process queue ensures sequential execution

## Architecture

### Data Flow

```
Event/Object/Block Created/Updated
          ↓
    ProcessTaskPipelineJob (dispatcher)
          ↓
    TaskRegistry (find applicable tasks)
          ↓
    Resolve dependencies & order
          ↓
    Dispatch individual task jobs
          ↓
    Execute & track in metadata
```

### Core Components

1. **TaskDefinition** - Defines a task's configuration, conditions, and dependencies
2. **TaskRegistry** - Central registry for all task definitions
3. **ProcessTaskPipelineJob** - Orchestrates task execution
4. **BaseTaskJob** - Abstract base class for task implementations
5. **InteractsWithTaskMetadata** - Trait for metadata field handling

### Metadata Storage

Task executions are tracked in model metadata fields:

- **Event**: Uses `event_metadata['task_executions']`
- **Block**: Uses `metadata['task_executions']`
- **EventObject**: Uses `metadata['task_executions']`
- **Integration**: Uses `metadata['task_executions']`

Structure:

```json
{
    "task_executions": {
        "task_key": {
            "last_attempt": {
                "started_at": "2025-11-29T10:00:00Z",
                "completed_at": "2025-11-29T10:00:05Z",
                "status": "success|failed|running|pending|not_applicable",
                "attempts": 1,
                "error": null,
                "triggered_by": "created|manual|scheduled|migration"
            },
            "last_success": {
                "started_at": "2025-11-29T10:00:00Z",
                "completed_at": "2025-11-29T10:00:05Z",
                "status": "success",
                "attempts": 1,
                "triggered_by": "created"
            }
        }
    }
}
```

## Core Concepts

### Task Definition

A task definition specifies:

- **key**: Unique identifier
- **name**: Display name
- **description**: What the task does
- **jobClass**: Job class to execute
- **appliesTo**: Model types (`['event', 'block', 'object', 'integration']`)
- **conditions**: Filtering conditions (e.g., `['service' => 'monzo']`)
- **dependencies**: Other tasks that must run first
- **queue**: Queue name (default: `'tasks'`)
- **priority**: Execution priority (higher = first, 0-100)
- **runOnCreate**: Run when model created
- **runOnUpdate**: Run when model updated
- **shouldRun**: Custom callback for additional conditions

### Task Lifecycle

1. **Registration** - Task registered in TaskPipelineServiceProvider
2. **Trigger** - Event/model created/updated or scheduled
3. **Dispatch** - ProcessTaskPipelineJob finds applicable tasks
4. **Execution** - Tasks run in dependency order
5. **Tracking** - Status updated in metadata
6. **Completion** - Success/failure recorded

### Dependencies

Tasks can depend on other tasks:

```php
dependencies: ['calculate_metric_stats', 'generate_embedding']
```

The system ensures:

- Dependencies run first
- Circular dependencies are detected
- Order is deterministic

## Getting Started

### View Registered Tasks

```bash
php artisan task-pipeline:list
```

### Populate Initial State (One-time)

After deploying, populate task execution states for existing records:

```bash
# Dry run first
php artisan task-pipeline:populate-initial-state --dry-run

# Process all models
php artisan task-pipeline:populate-initial-state

# Process specific model type
php artisan task-pipeline:populate-initial-state --model=event

# Limit for testing
php artisan task-pipeline:populate-initial-state --limit=100
```

### Re-run a Task

```bash
# Re-run for specific item
php artisan task-pipeline:rerun generate_embedding event abc-123-def

# Force re-run even if successful
php artisan task-pipeline:rerun detect_anomalies event abc-123-def --force
```

### Bulk Re-run

```bash
# Dry run to preview
php artisan task-pipeline:bulk-rerun detect_anomalies event \
  --service=monzo \
  --since=2025-01-01 \
  --dry-run

# Execute bulk re-run
php artisan task-pipeline:bulk-rerun detect_anomalies event \
  --service=monzo \
  --since=2025-01-01 \
  --limit=1000
```

## Creating Tasks

### 1. Create Task Job

Extend `BaseTaskJob` and implement `execute()`:

```php
<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;

class MyCustomTask extends BaseTaskJob
{
    protected function execute(): void
    {
        // Your task logic here
        $data = $this->processData($this->model);

        // Update model without triggering observers
        $this->model->withoutEvents(function() use ($data) {
            $this->model->update(['processed_data' => $data]);
        });
    }

    private function processData($model)
    {
        // Your processing logic
        return ['result' => 'processed'];
    }
}
```

### 2. Register Task

Add to `TaskPipelineServiceProvider::registerCoreTasks()`:

```php
TaskRegistry::register(new TaskDefinition(
    key: 'my_custom_task',
    name: 'My Custom Task',
    description: 'Processes custom data for events',
    jobClass: \App\Jobs\TaskPipeline\Tasks\MyCustomTask::class,
    appliesTo: ['event'],
    conditions: [
        'service' => 'my_service',
        'domain' => 'my_domain',
    ],
    dependencies: ['generate_embedding'], // Optional
    queue: 'tasks',
    priority: 50,
    runOnCreate: true,
    runOnUpdate: false,
    shouldRun: function($model) {
        // Optional custom condition
        return $model->value !== null;
    },
));
```

### Task Configuration Options

**Basic:**

```php
key: 'unique_key',              // Required: unique identifier
name: 'Display Name',           // Required: human-readable name
description: 'What it does',    // Required: description
jobClass: TaskClass::class,     // Required: job to execute
appliesTo: ['event'],           // Required: model types
```

**Filtering:**

```php
conditions: [
    'service' => 'monzo',                    // Single value
    'action' => ['create', 'update'],        // Multiple values
],
shouldRun: fn($model) => $model->value > 100,  // Custom callback
```

**Execution:**

```php
dependencies: ['task1', 'task2'],  // Run after these tasks
queue: 'tasks',                    // Queue name
priority: 75,                      // 0-100 (higher = first)
runOnCreate: true,                 // Run on model creation
runOnUpdate: false,                // Run on model update
```

## Plugin Development

### 1. Create Plugin Class

Implement `SupportsTaskPipeline` interface:

```php
<?php

namespace App\Integrations\MyPlugin;

use App\Integrations\Contracts\SupportsTaskPipeline;
use App\Services\TaskPipeline\TaskDefinition;

class MyPlugin implements SupportsTaskPipeline
{
    public static function getTaskDefinitions(): array
    {
        return [
            new TaskDefinition(
                key: 'myplugin_enrich',
                name: 'Enrich with MyPlugin Data',
                description: 'Enriches events with data from MyPlugin API',
                jobClass: \App\Integrations\MyPlugin\Jobs\EnrichTask::class,
                appliesTo: ['event'],
                conditions: ['service' => 'myplugin'],
                dependencies: ['generate_embedding'],
                queue: 'tasks',
                priority: 45,
                runOnCreate: true,
            ),
        ];
    }
}
```

### 2. Create Task Job

```php
<?php

namespace App\Integrations\MyPlugin\Jobs;

use App\Jobs\TaskPipeline\BaseTaskJob;

class EnrichTask extends BaseTaskJob
{
    protected function execute(): void
    {
        // Call plugin API
        $client = new MyPluginApiClient();
        $data = $client->enrich($this->model);

        // Store enriched data
        $this->model->withoutEvents(function() use ($data) {
            $metadata = $this->model->metadata ?? [];
            $metadata['myplugin_data'] = $data;
            $this->model->update(['metadata' => $metadata]);
        });
    }
}
```

### 3. Auto-Discovery

Place your plugin anywhere under `app/Integrations/`:

```
app/Integrations/
├── MyPlugin/
│   ├── MyPlugin.php          (implements SupportsTaskPipeline)
│   └── Jobs/
│       └── EnrichTask.php
```

The system automatically discovers and registers plugin tasks on boot.

## CLI Commands

### task-pipeline:list

List all registered tasks.

```bash
# List all tasks
php artisan task-pipeline:list

# Filter by model type
php artisan task-pipeline:list --applies-to=event

# Filter by plugin
php artisan task-pipeline:list --plugin="App\Integrations\MyPlugin\MyPlugin"

# JSON output
php artisan task-pipeline:list --json
```

### task-pipeline:rerun

Re-run a specific task for a specific item.

```bash
php artisan task-pipeline:rerun {task_key} {model_type} {model_id} [--force]

# Examples:
php artisan task-pipeline:rerun generate_embedding event abc-123
php artisan task-pipeline:rerun detect_anomalies event abc-123 --force
```

**Arguments:**

- `task_key`: Task to run (from task-pipeline:list)
- `model_type`: event | block | object | integration
- `model_id`: Model's UUID

**Options:**

- `--force`: Re-run even if already successful

### task-pipeline:bulk-rerun

Bulk re-run a task for filtered items.

```bash
php artisan task-pipeline:bulk-rerun {task_key} {model_type} [options]

# Examples:
php artisan task-pipeline:bulk-rerun detect_anomalies event --service=monzo --since=2025-01-01
php artisan task-pipeline:bulk-rerun generate_embedding block --limit=100 --dry-run
```

**Options:**

- `--service={service}`: Filter by service
- `--domain={domain}`: Filter by domain
- `--action={action}`: Filter by action
- `--since={date}`: Only items created since date
- `--limit={number}`: Limit number of items
- `--force`: Re-run even if successful
- `--dry-run`: Preview without dispatching

### task-pipeline:populate-initial-state

Populate task execution states for existing records (one-time migration).

```bash
php artisan task-pipeline:populate-initial-state [options]

# Examples:
php artisan task-pipeline:populate-initial-state --dry-run
php artisan task-pipeline:populate-initial-state --model=event
php artisan task-pipeline:populate-initial-state --limit=100
```

**Options:**

- `--model={type}`: Process specific model type only
- `--limit={number}`: Limit number of records
- `--dry-run`: Preview without making changes

## UI Components

### Task Execution Drawer

Display task status for individual items (events, blocks, objects):

```blade
<livewire:task-execution-drawer :model="$event" />
```

Features:

- Shows all applicable tasks
- Color-coded status badges
- Execution history (last attempt + last success)
- Individual and bulk re-run buttons
- Toggle to show/hide not applicable tasks
- Error messages for failed tasks
- Dependency information

### Admin Overview

Monitor task pipeline health:

```blade
<livewire:admin.task-pipeline-overview />
```

Features:

- Statistics dashboard (total, pending, failed, success)
- Complete task registry table
- Recent failures with retry buttons
- Filter and search capabilities

## Configuration

### Queue Configuration

Task pipeline uses a dedicated `tasks` queue with a single worker to prevent race conditions.

Horizon configuration (already set up):

```php
// config/horizon.php
'tasks' => [
    'connection' => 'redis',
    'queue' => ['tasks'],
    'balance' => 'simple',
    'processes' => 1,  // SINGLE WORKER - prevents race conditions
    'tries' => 3,
    'timeout' => 300,
    'backoff' => [30, 120, 300],
],
```

### Scheduled Tasks

Configured in `routes/console.php`:

```php
// Daily: Trend detection
Schedule::job(new DispatchTrendDetectionTasksJob)->daily();

// Daily: Retrospective anomaly detection
Schedule::job(new DispatchRetrospectiveAnomalyTasksJob)->daily();
```

**Note:** Metric statistics calculation now runs automatically in the task pipeline on event creation and update, so no scheduled job is needed.

### Task Retry Configuration

In `BaseTaskJob`:

```php
public $timeout = 120;           // 2 minutes
public $tries = 3;               // 3 attempts
public $backoff = [30, 120, 300]; // 30s, 2m, 5m
```

Override in your task:

```php
class MyTask extends BaseTaskJob
{
    public $timeout = 300;  // 5 minutes
    public $tries = 5;      // 5 attempts
}
```

## Testing

See `tests/Unit/TaskPipeline/` and `tests/Feature/TaskPipeline/` for comprehensive tests.

Run tests:

```bash
# All task pipeline tests
php artisan test --filter=TaskPipeline

# Specific test file
php artisan test tests/Unit/TaskPipeline/TaskRegistryTest.php
```

## Troubleshooting

### Task Not Running

**Check if task is registered:**

```bash
php artisan task-pipeline:list
```

**Check if task is applicable:**

- Verify model type in `appliesTo`
- Check `conditions` match model attributes
- Test `shouldRun` callback logic

**Check execution history:**

```bash
# View in UI or check model metadata
$event->event_metadata['task_executions']['task_key']
```

### Task Failing

**View error in metadata:**

```php
$execution = $event->event_metadata['task_executions']['task_key'];
$error = $execution['last_attempt']['error'];
```

**Check Sentry:**

- Errors are automatically logged with context
- Tags: task, model, queue
- Extra: task_key, model_id, attempt

**Re-run with force:**

```bash
php artisan task-pipeline:rerun task_key event model-id --force
```

### Circular Dependencies

Error: "Circular dependency detected in tasks: task1, task2"

**Solution:**

- Review task dependencies
- Remove or reorder circular references
- Use `task-pipeline:list` to see dependencies

### Race Conditions

Ensure Horizon/queue worker is configured with single process:

```bash
# Check running workers
php artisan horizon:list

# Restart if needed
php artisan horizon:terminate
```

### Performance Issues

**Queue backed up:**

```bash
# Check queue depth
php artisan queue:monitor tasks

# Add more workers (still single process per queue)
# Adjust in config/horizon.php
```

**Slow tasks:**

- Check task timeouts
- Optimize task logic
- Consider splitting into smaller tasks

## Best Practices

### 1. Task Design

- Keep tasks focused and single-purpose
- Use dependencies instead of bundling logic
- Implement idempotent operations
- Handle failures gracefully

### 2. Error Handling

- Use try-catch in execute() if you need custom handling
- Throw exceptions for retryable errors
- Log context for debugging

```php
protected function execute(): void
{
    try {
        $this->processData();
    } catch (ApiException $e) {
        // Log additional context before re-throwing
        Log::error('API call failed', [
            'model_id' => $this->model->id,
            'endpoint' => $e->getEndpoint(),
        ]);
        throw $e; // Re-throw for retry
    }
}
```

### 3. Metadata Updates

Always use `withoutEvents()` to prevent recursive triggers:

```php
$this->model->withoutEvents(function() use ($data) {
    $this->model->update(['field' => $data]);
});
```

### 4. Testing

- Write unit tests for task logic
- Test shouldRun conditions
- Test with various model states
- Test failure scenarios

### 5. Monitoring

- Monitor Sentry for task failures
- Track queue depth in Horizon
- Review task execution rates in admin UI
- Set up alerts for high failure rates

## Migration Guide

### From Old System

If migrating from scattered observers/listeners:

1. **Identify existing tasks:**
    - Search for Observer files
    - Check AppServiceProvider boot listeners
    - Review scheduled jobs

2. **Create task jobs:**
    - Move logic to task job classes
    - Extend BaseTaskJob
    - Keep existing functionality intact

3. **Register tasks:**
    - Add to TaskPipelineServiceProvider
    - Define conditions and dependencies

4. **Populate initial state:**

    ```bash
    php artisan task-pipeline:populate-initial-state --dry-run
    php artisan task-pipeline:populate-initial-state
    ```

5. **Test in parallel:**
    - Run both systems temporarily
    - Compare results
    - Verify execution tracking

6. **Cutover:**
    - Remove old observers
    - Clean up listeners
    - Monitor for issues

## Support

For issues or questions:

1. Check this documentation
2. Review tests in `tests/TaskPipeline/`
3. Check Sentry for errors
4. Use `task-pipeline:list` to inspect configuration

## License

Part of the Spark application.
