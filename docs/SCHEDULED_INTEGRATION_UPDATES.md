# Scheduled Integration Updates

This document explains how the scheduled integration updates work in the Spark application.

## Overview

The application automatically checks for integrations that are due for updates every minute and dispatches background jobs to process them. This ensures that all integrations are kept up-to-date according to their configured frequency settings.

## How It Works

### 1. Scheduled Job

The `CheckIntegrationUpdates` job runs every minute via Laravel's task scheduler. This job:

- Checks for integrations that need updating based on their `update_frequency_minutes` setting
- Skips integrations that are currently being processed
- Skips integrations that were recently triggered (within their frequency window)
- Dispatches `ProcessIntegrationData` jobs for integrations that need updating

### 2. Background Processing

Each integration update is processed as a background job using Laravel's queue system:

- The `CheckIntegrationUpdates` job runs every minute to check for integrations that need updating
- For each integration that needs updating, a `ProcessIntegrationData` job is dispatched
- Jobs are dispatched to the `default` queue
- Each `ProcessIntegrationData` job has a 5-minute timeout
- Failed `ProcessIntegrationData` jobs are retried up to 3 times with increasing delays (1, 5, 10 minutes)

### 3. Integration Frequency

Each integration has an `update_frequency_minutes` field that determines how often it should be updated:

- If `last_successful_update_at` is null, the integration needs updating
- Otherwise, the next update time is calculated as `last_successful_update_at + update_frequency_minutes`
- The system only updates integrations that are past their next scheduled update time

## Setup

### 1. Queue Worker

Make sure you have a queue worker running:

```bash
# For development with Sail
sail artisan queue:work

# For production
php artisan queue:work --daemon
```

### 2. Task Scheduler

The task scheduler is configured in `routes/console.php` to run every 30 seconds and avoid overlaps. If running multiple instances, it uses `onOneServer()`:

```php
Schedule::job(new CheckIntegrationUpdates())
    ->everyThirtySeconds()
    ->withoutOverlapping()
    ->onOneServer();
```

### 3. Scheduler Invocation (Production)

To achieve true 30-second intervals, run the scheduler worker instead of relying on cron-per-minute:

```bash
php artisan schedule:work
```

If you must use cron, note it will only trigger once per minute and won’t honor sub-minute intervals:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

## Commands

### Manual Execution

You can manually run the integration updates:

```bash
# Dispatch the check job manually
sail artisan tinker --execute="App\Jobs\CheckIntegrationUpdates::dispatch()"

# Or run the check job synchronously for testing
sail artisan tinker --execute="(new App\Jobs\CheckIntegrationUpdates())->handle()"

# You can also still use the original command for manual updates
sail artisan integrations:fetch

# Update a specific service
sail artisan integrations:fetch --service=spotify

# Force update all integrations (ignore frequency)
sail artisan integrations:fetch --force
```

### Monitoring

Check the status of scheduled tasks:

```bash
# List all scheduled tasks
sail artisan schedule:list

# Test a scheduled command
sail artisan schedule:test
```

## Job Processing

### CheckIntegrationUpdates Job

The `CheckIntegrationUpdates` job:

1. Queries for integrations that need updating
2. Checks if integrations are currently processing
3. Verifies update frequency requirements
4. Dispatches `ProcessIntegrationData` jobs for eligible integrations
5. Logs the results

**Job Configuration:**
- **Timeout**: 1 minute
- **Retries**: 1 attempt (no retry)
- **Queue**: `default`

### ProcessIntegrationData Job

The `ProcessIntegrationData` job:

1. Loads the appropriate integration plugin
2. Marks the integration as triggered
3. Calls the plugin's `fetchData()` method
4. Marks the integration as successfully updated
5. Handles errors and retries with proper state management

**Job Configuration:**
- **Timeout**: 5 minutes
- **Retries**: 3 attempts
- **Backoff**: 60, 300, 600 seconds (1, 5, 10 minutes)
- **Queue**: `default`

**Error Handling:**
- If an exception occurs during processing, the integration is marked as failed
- Failed integrations have their `last_triggered_at` cleared, allowing them to be retried
- The `failed()` callback ensures permanent failures are also properly marked

## Monitoring and Debugging

### Logs

Integration processing is logged with the following levels:

- **Info**: Successful processing
- **Error**: Failed processing with retry
- **Error**: Permanent failure after all retries

### Queue Monitoring

Check queue status:

```bash
# Check failed jobs
sail artisan queue:failed

# Retry failed jobs
sail artisan queue:retry all

# Clear failed jobs
sail artisan queue:flush
```

## Integration States

### Processing State

An integration is considered "processing" if:
- `last_triggered_at` is more recent than `last_successful_update_at`
- This prevents duplicate jobs from being dispatched

### Failed State

An integration is marked as failed when:
- An exception occurs during processing (in the catch block)
- The job fails permanently after all retries (in the failed callback)
- The `last_triggered_at` field is cleared, allowing the integration to be retried

### Update Frequency

The system respects each integration's `update_frequency_minutes` setting:
- Minimum interval between updates
- Prevents excessive API calls
- Configurable per integration

## Troubleshooting

### Common Issues

1. **Jobs not processing**: Ensure queue worker is running
2. **Integrations not updating**: Check `update_frequency_minutes` settings
3. **Failed jobs**: Check logs for specific error messages
4. **Scheduler not running**: Verify cron job is set up correctly

### Debug Commands

```bash
# Check integration status
sail artisan tinker
>>> App\Models\Integration::with('user')->get()->each(fn($i) => echo "{$i->service}: {$i->needsUpdate()}\n")

# Check queue status
sail artisan queue:work --once --verbose
```
