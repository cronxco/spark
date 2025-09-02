# Integration Job Refactoring

## Overview

This document describes the refactored integration system that separates data fetching from processing using Laravel jobs. The new architecture improves scalability, maintainability, and error handling.

## Architecture

### Job Hierarchy

```
Integration Update Flow
├── CheckIntegrationUpdates (existing, modified)
│   └── Dispatches fetch jobs based on integration type
│
├── Fetch Jobs (NEW)
│   ├── OAuth: ServiceNamePull (e.g., MonzoAccountPull)
│   └── Webhook: ServiceNameWebhookHook (e.g., AppleHealthWebhookHook)
│
└── Processing Jobs (NEW)
    ├── OAuth: ServiceNameData (e.g., MonzoAccountData)
    └── Webhook: ServiceNameData (e.g., AppleHealthWorkoutData)
```

### Base Classes

#### `BaseFetchJob`

Abstract base class for OAuth data fetching jobs.

- Handles API authentication and error logging
- Timeout: 120 seconds
- Retries: 3 with backoff (60s, 300s, 600s)

#### `BaseProcessingJob`

Abstract base class for data processing jobs.

- Handles event/object/block creation
- Timeout: 300 seconds
- Retries: 2 with backoff (120s, 300s)

#### `BaseWebhookHookJob`

Abstract base class for webhook splitting jobs.

- Handles webhook validation and logging
- Timeout: 60 seconds
- Retries: 3 with backoff (30s, 120s, 300s)

## Implementation Status

### ✅ Completed

#### Base Infrastructure

- `App\Jobs\Base\BaseFetchJob`
- `App\Jobs\Base\BaseProcessingJob`
- `App\Jobs\Base\BaseWebhookHookJob`

#### Monzo OAuth Integration

- **Fetch Jobs:**
    - `App\Jobs\OAuth\Monzo\MonzoAccountPull`
    - `App\Jobs\OAuth\Monzo\MonzoTransactionPull`
    - `App\Jobs\OAuth\Monzo\MonzoPotPull`
    - `App\Jobs\OAuth\Monzo\MonzoBalancePull`

- **Processing Jobs:**
    - `App\Jobs\Data\Monzo\MonzoAccountData`
    - `App\Jobs\Data\Monzo\MonzoTransactionData`
    - `App\Jobs\Data\Monzo\MonzoPotData`
    - `App\Jobs\Data\Monzo\MonzoBalanceData`

#### Apple Health Webhook Integration

- **Hook Job:**
    - `App\Jobs\Webhook\AppleHealth\AppleHealthWebhookHook`

- **Processing Jobs:**
    - `App\Jobs\Data\AppleHealth\AppleHealthWorkoutData`
    - `App\Jobs\Data\AppleHealth\AppleHealthMetricData`

#### System Updates

- Modified `CheckIntegrationUpdates` to dispatch fetch jobs
- Added job routing logic based on service and instance type

### 🔄 Next Steps

#### Remaining OAuth Integrations

- GoCardless (banking)
- Spotify (music)
- GitHub (repositories)
- Oura (health metrics)
- Hevy (fitness)

#### Webhook Integrations

- Slack (messaging)

#### Advanced Features

- Initialization jobs for new integrations
- Batch processing for high-volume data
- Monitoring and alerting
- Enhanced idempotency

## Usage

### Running Jobs

Using Laravel Sail:

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

Webhooks now automatically dispatch hook jobs:

```php
// Webhook endpoint dispatches hook job
AppleHealthWebhookHook::dispatch($payload, $headers, $integration);
```

## Benefits

### Scalability

- Separate worker pools for fetch vs processing jobs
- Different queue priorities for different job types
- Horizontal scaling of job workers

### Reliability

- Failed fetches don't block processing of successful data
- Idempotent jobs prevent duplicate processing
- Comprehensive error handling and retry logic

### Maintainability

- Clear separation of concerns
- Consistent job structure across integrations
- Easy to add new integrations following the pattern

### Performance

- Batch processing for high-volume integrations
- Optimized job queuing and processing
- Reduced memory usage through job chunking

## Testing

### Base Job Tests

```bash
sail artisan test tests/Unit/Jobs/BaseFetchJobTest.php
sail artisan test tests/Unit/Jobs/MonzoAccountPullTest.php
```

### Integration Tests

- Test job dispatching and processing
- Test error handling and retries
- Test webhook validation and processing

## Monitoring

### Queue Monitoring

```bash
# Check queue status
sail artisan queue:status

# Clear failed jobs
sail artisan queue:clear
```

### Job Monitoring

- Use Laravel Horizon for queue monitoring
- Monitor job success/failure rates
- Track processing times and bottlenecks

## Configuration

### Queue Configuration

Jobs are configured in `config/queue.php` with appropriate timeouts and retry settings.

### Job Priorities

Different job types can be assigned to different queues with different priorities:

```php
// config/queue.php
'connections' => [
    'database' => [
        'table' => 'jobs',
        'queue' => ['fetch', 'process', 'webhook'],
        // ...
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

### Common Issues

- **Job timeouts**: Check API response times and adjust job timeouts
- **Memory issues**: Process data in smaller chunks
- **Duplicate processing**: Ensure proper idempotency implementation
- **Webhook validation failures**: Check webhook secret configuration

### Debugging

- Check Laravel logs for job errors
- Use `sail artisan queue:failed` to see failed jobs
- Monitor Sentry for job performance and errors

## Future Enhancements

### Initialization Jobs

Jobs that run once when an integration is first connected:

- Fetch historical data (longer time periods)
- Set up initial state and objects
- Run data migrations if needed

### Advanced Batching

- Batch processing for integrations with high data volumes
- Intelligent chunking based on data size
- Parallel processing of independent data chunks

### Enhanced Monitoring

- Real-time job queue dashboards
- Performance metrics and alerting
- Integration health monitoring
