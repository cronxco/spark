# Scheduled Integration Updates

This document explains how the automatic integration update system works in Spark.

## Overview

The application checks for due integrations every minute and dispatches background jobs to process them. Integrations can be configured to update at a fixed frequency or at specific scheduled times.

## How It Works

The `CheckIntegrationUpdates` job runs via Laravel's scheduler and:

1. Selects external OAuth instances with a token, API-key instances, and Task instances owned by an admin. Manual and webhook sources are not polled. A Task instance is only due once its `use_schedule` is explicitly `true`; a missing setting means off
2. Determines due instances via `Integration::isDue()`
3. Queues a `ProcessTaskPipelineJob` on `tasks` for each, filtered to the `run_integration_update` task
4. `RunIntegrationUpdateTask` skips paused, processing or throttled instances (recorded `not_applicable`), then calls `DispatchIntegrationFetchJobs`, which queues the service's pull jobs
5. If no pull job maps to the instance's `(service, instance_type)`, the task execution is recorded as failed rather than succeeding with nothing queued. Manual sync from web, REST, mobile and MCP reports the same case as an error
6. The pull jobs are queued as one job batch (an integration run). Processing jobs a pull job dispatches join the same batch, and the run's state is kept in `configuration.last_run` (`requested` → `fetching` → `processing` → `up_to_date`, or `partial`/`failed`). The integration reads as Processing until the batch finishes, so Up to date means the data was processed, not just fetched. A run still in flight after 60 minutes is reported as `failed`

## Configuration Options

| Setting                    | Description                                      |
| -------------------------- | ------------------------------------------------ |
| `update_frequency_minutes` | Update interval in minutes (default: 15)         |
| `use_schedule`             | Enable schedule-based updates (Task: required to run on schedule at all) |
| `schedule_times`           | Array of HH:mm times (e.g., `["04:10","10:10"]`) |
| `schedule_timezone`        | IANA timezone (defaults to app timezone)         |
| `paused`                   | Prevents updates when `true`                     |

## Job Configuration

| Job                      | Timeout | Retries | Backoff     |
| ------------------------ | ------- | ------- | ----------- |
| CheckIntegrationUpdates  | 1 min   | 1       | None        |
| ProcessTaskPipelineJob   | 5 min   | 1       | None        |
| RunIntegrationUpdateTask | –       | 3       | 30s, 2m, 5m |

## Setup

### Queue Worker

```bash
# Development
sail artisan queue:work

# Production
php artisan queue:work --daemon
```

### Task Scheduler

The scheduler is configured in `routes/console.php`:

```php
Schedule::job(new CheckIntegrationUpdates)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->sentryMonitor();
```

### Production Scheduler

Run `php artisan schedule:run` from cron every minute, or `php artisan schedule:work` as a long-running process.

## Commands

```bash
# Manual dispatch
sail artisan tinker --execute="App\Jobs\CheckIntegrationUpdates::dispatch()"

# Fetch specific service
sail artisan integrations:fetch --service=spotify

# Force update all
sail artisan integrations:fetch --force

# Check scheduled tasks
sail artisan schedule:list
```

## Integration States

| State      | Condition                                                           |
| ---------- | ------------------------------------------------------------------- |
| Processing | `last_run` is still in flight, or `last_triggered_at` is more recent than `last_successful_update_at` |
| Failed     | Exception occurred; `last_triggered_at` cleared for retry           |
| Due        | Meets frequency or schedule requirements                            |

## Monitoring

```bash
# Check failed jobs
sail artisan queue:failed

# Retry failed jobs
sail artisan queue:retry all

# Clear failed jobs
sail artisan queue:flush
```

## Troubleshooting

| Issue                     | Solution                                     |
| ------------------------- | -------------------------------------------- |
| Jobs not processing       | Ensure queue worker is running               |
| Integrations not updating | Check `update_frequency_minutes` settings    |
| Failed jobs               | Check logs for specific error messages       |
| Scheduler not running     | Verify cron job or `schedule:work` is active |
