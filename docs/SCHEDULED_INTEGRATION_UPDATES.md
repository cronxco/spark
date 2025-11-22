# Scheduled Integration Updates

This document explains how the automatic integration update system works in Spark.

## Overview

The application checks for due integrations every 30 seconds and dispatches background jobs to process them. Integrations can be configured to update at a fixed frequency or at specific scheduled times.

## How It Works

The `CheckIntegrationUpdates` job runs via Laravel's scheduler and:

1. Determines due instances via `Integration::isDue()`
2. Skips paused instances (`configuration.paused=true`)
3. Skips instances currently processing or recently triggered
4. Dispatches `ProcessIntegrationData` or `RunIntegrationTask` jobs

## Configuration Options

| Setting | Description |
|---------|-------------|
| `update_frequency_minutes` | Update interval in minutes (default: 15) |
| `use_schedule` | Enable schedule-based updates |
| `schedule_times` | Array of HH:mm times (e.g., `["04:10","10:10"]`) |
| `schedule_timezone` | IANA timezone (defaults to app timezone) |
| `paused` | Prevents updates when `true` |

## Job Configuration

| Job | Timeout | Retries | Backoff |
|-----|---------|---------|---------|
| CheckIntegrationUpdates | 1 min | 1 | None |
| ProcessIntegrationData | 5 min | 3 | 1, 5, 10 min |

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
Schedule::job(new CheckIntegrationUpdates())
    ->everyThirtySeconds()
    ->withoutOverlapping()
    ->onOneServer();
```

### Production Scheduler

For true 30-second intervals, use the scheduler worker:

```bash
php artisan schedule:work
```

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

| State | Condition |
|-------|-----------|
| Processing | `last_triggered_at` is more recent than `last_successful_update_at` |
| Failed | Exception occurred; `last_triggered_at` cleared for retry |
| Due | Meets frequency or schedule requirements |

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

| Issue | Solution |
|-------|----------|
| Jobs not processing | Ensure queue worker is running |
| Integrations not updating | Check `update_frequency_minutes` settings |
| Failed jobs | Check logs for specific error messages |
| Scheduler not running | Verify cron job or `schedule:work` is active |
