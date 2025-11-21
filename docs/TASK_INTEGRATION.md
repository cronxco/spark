# Task Integration

Run scheduled tasks.

## Overview

The Task integration is a special plugin for scheduling and running automated tasks within Spark. It allows you to schedule Artisan commands or dispatch Job classes at specific times or intervals, providing a flexible way to automate maintenance and custom operations.

## Features

- Run Artisan commands on a schedule
- Dispatch Laravel Job classes
- Flexible scheduling (specific times or frequency-based)
- Custom queue selection
- JSON payload support for job parameters
- Pause/resume task execution

## Setup

### Prerequisites

No external configuration required. This is a built-in scheduling plugin.

### Configuration

1. Navigate to Integrations in Spark
2. Create a new Task integration
3. Configure the task mode (Artisan or Job)
4. Set the command or job class
5. Configure the schedule

### Environment Variables

None required.

## Data Model

### Instance Types

| Type | Description |
|------|-------------|
| `task` | A scheduled task |

### Action Types

None. Task integration executes background operations without creating events.

### Block Types

None.

### Object Types

None.

## Usage

### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `task_mode` | string | `artisan` | Execution mode: `artisan` or `job` |
| `task_command` | string | - | Artisan command (e.g., `queue:prune-batches`) |
| `task_job_class` | string | - | Job class FQCN (e.g., `App\Jobs\ReindexSearch`) |
| `task_payload` | array | `[]` | JSON payload for job parameters |
| `task_queue` | string | `pull` | Queue name for job dispatch |
| `paused` | boolean | `false` | Whether the task is paused |
| `use_schedule` | boolean | `true` | Use specific times vs frequency |
| `schedule_times` | array | - | Times to run (HH:mm format) |
| `schedule_timezone` | string | `UTC` | Timezone for scheduled times |
| `update_frequency_minutes` | integer | `60` | Fallback frequency (minutes) |

### Task Mode Examples

#### Artisan Command

```php
// Configuration for running queue:prune-batches daily
[
    'task_mode' => 'artisan',
    'task_command' => 'queue:prune-batches',
    'use_schedule' => true,
    'schedule_times' => ['03:00'],
    'schedule_timezone' => 'UTC',
]
```

#### Job Class Dispatch

```php
// Configuration for dispatching a custom job
[
    'task_mode' => 'job',
    'task_job_class' => 'App\\Jobs\\ReindexSearch',
    'task_payload' => [
        'full_reindex' => false,
        'batch_size' => 100,
    ],
    'task_queue' => 'pull',
    'use_schedule' => true,
    'schedule_times' => ['00:00', '12:00'],
]
```

### Scheduling Options

#### Time-Based Schedule

Set specific times for task execution:

```php
[
    'use_schedule' => true,
    'schedule_times' => ['06:00', '12:00', '18:00', '00:00'],
    'schedule_timezone' => 'America/New_York',
]
```

#### Frequency-Based Schedule

Run at regular intervals:

```php
[
    'use_schedule' => false,
    'update_frequency_minutes' => 30,  // Run every 30 minutes
]
```

### Manual Operations

```bash
# Run all scheduled tasks manually
sail artisan integrations:fetch --service=task

# Execute a specific task integration
sail artisan tinker
>>> $integration = App\Models\Integration::find('uuid');
>>> App\Jobs\ExecuteTaskJob::dispatch($integration);
```

## Common Use Cases

### 1. Database Maintenance

Prune old job batches:

```php
[
    'task_mode' => 'artisan',
    'task_command' => 'queue:prune-batches',
    'schedule_times' => ['04:00'],
]
```

### 2. Search Reindexing

Rebuild search indexes periodically:

```php
[
    'task_mode' => 'job',
    'task_job_class' => 'App\\Jobs\\ReindexSearch',
    'schedule_times' => ['02:00'],
]
```

### 3. Cache Warming

Pre-warm caches during low-traffic periods:

```php
[
    'task_mode' => 'job',
    'task_job_class' => 'App\\Jobs\\WarmCaches',
    'task_payload' => ['scope' => 'full'],
    'schedule_times' => ['05:00'],
]
```

### 4. Integration Maintenance

Run periodic cleanup tasks:

```php
[
    'task_mode' => 'artisan',
    'task_command' => 'model:prune',
    'update_frequency_minutes' => 1440,  // Daily
]
```

## Troubleshooting

### Common Issues

1. **Task Not Running**
   - Check if the task is paused (`paused: true`)
   - Verify Horizon is running for queue processing
   - Check the schedule times and timezone

2. **Job Class Not Found**
   - Verify the FQCN is correct with proper namespace escaping
   - Ensure the class implements `ShouldQueue`
   - Check that the class exists in the autoload

3. **Artisan Command Failed**
   - Check Laravel logs for command errors
   - Verify the command exists: `sail artisan list`
   - Test the command manually first

4. **Wrong Timezone**
   - Verify `schedule_timezone` matches your expected timezone
   - Use standard PHP timezone identifiers (e.g., `Europe/London`)

## Related Documentation

- [INTEGRATION_PLUGINS.md](INTEGRATION_PLUGINS.md) - Plugin architecture
- [README_JOBS.md](README_JOBS.md) - Job system overview
- [SCHEDULED_INTEGRATION_UPDATES.md](SCHEDULED_INTEGRATION_UPDATES.md) - Scheduling system
