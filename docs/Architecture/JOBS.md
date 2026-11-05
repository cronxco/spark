# Integration Jobs

This document describes the refactored integration system that separates data fetching from processing using Laravel jobs.

## Overview

The job architecture improves scalability, maintainability, and error handling by separating concerns into distinct job types. Fetch jobs retrieve data from external APIs, while processing jobs handle data transformation and storage.

## Architecture

### Job Hierarchy

```
Integration Update Flow
├── CheckIntegrationUpdates (existing, modified)
│   └── Dispatches fetch jobs based on integration type
│
├── Fetch Jobs
│   ├── OAuth: ServiceNamePull (e.g., MonzoAccountPull)
│   └── Webhook: ServiceNameWebhookHook (e.g., AppleHealthWebhookHook)
│
└── Processing Jobs
    ├── OAuth: ServiceNameData (e.g., MonzoAccountData)
    └── Webhook: ServiceNameData (e.g., AppleHealthWorkoutData)
```

### Base Classes

| Base Class           | Purpose             | Timeout | Retries                     |
| -------------------- | ------------------- | ------- | --------------------------- |
| `BaseFetchJob`       | OAuth data fetching | 120s    | 3 (60s, 300s, 600s backoff) |
| `BaseProcessingJob`  | Data processing     | 300s    | 2 (120s, 300s backoff)      |
| `BaseWebhookHookJob` | Webhook splitting   | 60s     | 3 (30s, 120s, 300s backoff) |

### Integration Runs

`DispatchIntegrationFetchJobs` queues an integration's fetch jobs as one job batch through `IntegrationRunService`. While a batched fetch job runs `dispatchProcessingJobs()`, every `BaseProcessingJob` it dispatches joins the same batch (keeping its own queue and delay), so the batch only finishes once the data has been processed. The run's state lives in `configuration.last_run`:

| Status       | Meaning                                                        |
| ------------ | -------------------------------------------------------------- |
| `requested`  | Batch created, fetch jobs queued                               |
| `fetching`   | A fetch job has started                                        |
| `processing` | Fetching finished; processing jobs are queued or running       |
| `up_to_date` | Every job in the batch succeeded                               |
| `partial`    | Some jobs failed for good                                      |
| `failed`     | Every job failed, or the run was still in flight after an hour |

`last_run` is written with a jsonb merge in SQL, so it never overwrites other configuration keys. Fetch and processing jobs dispatched outside a run (webhooks, migrations, detail fetches) behave exactly as before.

## Implementation Status

### Base Infrastructure

- `App\Jobs\Base\BaseFetchJob`
- `App\Jobs\Base\BaseProcessingJob`
- `App\Jobs\Base\BaseWebhookHookJob`

### Monzo OAuth Integration

**Fetch Jobs:**

- `App\Jobs\OAuth\Monzo\MonzoAccountPull`
- `App\Jobs\OAuth\Monzo\MonzoTransactionPull`
- `App\Jobs\OAuth\Monzo\MonzoPotPull`
- `App\Jobs\OAuth\Monzo\MonzoBalancePull`

**Processing Jobs:**

- `App\Jobs\Data\Monzo\MonzoAccountData`
- `App\Jobs\Data\Monzo\MonzoTransactionData`
- `App\Jobs\Data\Monzo\MonzoPotData`
- `App\Jobs\Data\Monzo\MonzoBalanceData`

### Apple Health Webhook Integration

**Hook Job:**

- `App\Jobs\Webhook\AppleHealth\AppleHealthWebhookHook`

**Processing Jobs:**

- `App\Jobs\Data\AppleHealth\AppleHealthWorkoutData`
- `App\Jobs\Data\AppleHealth\AppleHealthMetricData`

## Usage

### Running Jobs

```bash
# Run queue worker
sail artisan queue:work

# Run scheduler (for periodic integration updates)
sail artisan schedule:work
```

### Manual Job Dispatch

```php
use App\Jobs\OAuth\Monzo\MonzoTransactionPull;
use App\Models\Integration;

// Dispatch a specific fetch job
MonzoTransactionPull::dispatch($integration);
```

### Webhook Handling

```php
// Webhook endpoint dispatches hook job
AppleHealthWebhookHook::dispatch($payload, $headers, $integration);
```

## Benefits

| Category        | Benefit                                                                           |
| --------------- | --------------------------------------------------------------------------------- |
| Scalability     | Separate worker pools, different queue priorities, horizontal scaling             |
| Reliability     | Failed fetches don't block processing, idempotent jobs, comprehensive retry logic |
| Maintainability | Clear separation of concerns, consistent job structure, easy to extend            |
| Performance     | Batch processing, optimized queuing, reduced memory through chunking              |

## Testing

```bash
sail artisan test tests/Unit/Jobs/BaseFetchJobTest.php
sail artisan test tests/Unit/Jobs/MonzoAccountPullTest.php
```

## Monitoring

```bash
# Check queue status
sail artisan queue:status

# Clear failed jobs
sail artisan queue:clear
```

Use Laravel Horizon for queue monitoring, job success/failure rates, and processing time tracking.

## Configuration

### Queue Configuration

Jobs are configured in `config/queue.php` with appropriate timeouts and retry settings.

### Job Priorities

```php
// config/queue.php
'connections' => [
    'database' => [
        'table' => 'jobs',
        'queue' => ['fetch', 'process', 'webhook'],
    ],
],
```

## Migration Path

### Existing Integrations

1. Create fetch and processing jobs for each integration
2. Update `CheckIntegrationUpdates` to dispatch new jobs
3. Test thoroughly before deploying
4. Monitor job queues and performance

### New Integrations

1. Follow the established job pattern
2. Create appropriate fetch/processing jobs
3. Add routing logic to `CheckIntegrationUpdates`
4. Test webhook/OAuth flows

## Troubleshooting

| Issue                       | Solution                                         |
| --------------------------- | ------------------------------------------------ |
| Job timeouts                | Check API response times and adjust job timeouts |
| Memory issues               | Process data in smaller chunks                   |
| Duplicate processing        | Ensure proper idempotency implementation         |
| Webhook validation failures | Check webhook secret configuration               |

### Debugging

- Check Laravel logs for job errors
- Use `sail artisan queue:failed` to see failed jobs
- Monitor Sentry for job performance and errors
