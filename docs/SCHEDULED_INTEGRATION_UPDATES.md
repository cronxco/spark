# Scheduled Integration Updates

This document explains how the scheduled integration updates work in the Spark application.

## Overview

The application automatically checks for integrations that are due for updates every minute and dispatches background jobs to process them. This ensures that all integrations are kept up-to-date according to their configured frequency settings.

## How It Works

### 1. Scheduled Command

The `integrations:fetch` command runs every minute via Laravel's task scheduler. This command:

- Checks for integrations that need updating based on their `update_frequency_minutes` setting
- Skips integrations that are currently being processed
- Skips integrations that were recently triggered (within their frequency window)
- Dispatches `ProcessIntegrationData` jobs for integrations that need updating

### 2. Background Processing

Each integration update is processed as a background job using Laravel's queue system:

- Jobs are dispatched to the `default` queue
- Each job has a 5-minute timeout
- Failed jobs are retried up to 3 times with increasing delays (1, 5, 10 minutes)
- Jobs are processed by the `ProcessIntegrationData` job class

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

The task scheduler is already configured in `routes/console.php`:

```php
Schedule::command('integrations:fetch')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
```

### 3. Cron Job (Production)

For production, add this cron job to run Laravel's scheduler:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

## Commands

### Manual Execution

You can manually run the integration updates:

```bash
# Update all integrations that need updating
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

### ProcessIntegrationData Job

The `ProcessIntegrationData` job:

1. Loads the appropriate integration plugin
2. Marks the integration as triggered
3. Calls the plugin's `fetchData()` method
4. Marks the integration as successfully updated
5. Handles errors and retries

### Job Configuration

- **Timeout**: 5 minutes
- **Retries**: 3 attempts
- **Backoff**: 60, 300, 600 seconds (1, 5, 10 minutes)
- **Queue**: `default`

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
