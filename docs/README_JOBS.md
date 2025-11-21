# Integration Jobs

The job system handles asynchronous data fetching and processing for external service integrations.

## Overview

Spark uses a job-based architecture that separates data fetching from processing. This separation improves scalability by allowing different worker pools for each job type, ensures reliability by preventing failed fetches from blocking successful data processing, and enables idempotent execution through unique job identifiers.

## Architecture

### Job Types

The system uses four distinct job types, each with a specific purpose:

| Job Type | Purpose | Timeout | Tries | Backoff |
|----------|---------|---------|-------|---------|
| Fetch | Pull data from external APIs (OAuth) | 120s | 3 | 60s, 300s, 600s |
| Processing | Transform and store fetched data | 300s | 2 | 120s, 300s |
| Webhook | Process incoming webhook payloads | 60s | 3 | 30s, 120s, 300s |
| Initialization | One-time historical data backfills | 600s | 1 | None |

### Base Classes

All jobs extend from base classes located in `app/Jobs/Base/`:

**BaseFetchJob**

Template for OAuth data fetching. Handles Sentry tracing, error logging, retry logic, and user notifications on permanent failure.

```php
abstract class BaseFetchJob implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    abstract protected function getServiceName(): string;
    abstract protected function getJobType(): string;
    abstract protected function fetchData(): array;
    abstract protected function dispatchProcessingJobs(array $rawData): void;
}
```

**BaseProcessingJob**

Template for data transformation. Includes helper methods for creating events, objects, and blocks with duplicate detection.

```php
abstract class BaseProcessingJob implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    abstract protected function getServiceName(): string;
    abstract protected function getJobType(): string;
    abstract protected function process(): void;
}
```

**BaseWebhookHookJob**

Template for webhook processing. Validates signatures, splits payloads into chunks, and dispatches processing jobs.

```php
abstract class BaseWebhookHookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    abstract protected function getServiceName(): string;
    abstract protected function getJobType(): string;
    abstract protected function validateWebhook(): void;
    abstract protected function splitWebhookData(): array;
    abstract protected function dispatchProcessingJobs(array $processingData): void;
}
```

**BaseInitializationJob**

Template for one-time setup tasks. Runs with a single attempt and longer timeout for historical data imports.

```php
abstract class BaseInitializationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    abstract protected function getServiceName(): string;
    abstract protected function getJobType(): string;
    abstract protected function initialize(): void;
}
```

### Queue Configuration

Horizon manages three supervisor pools with different queues:

| Queue | Purpose | Max Processes | Memory |
|-------|---------|---------------|--------|
| pull | Fetch and processing jobs | 5 | 256MB |
| migration | Data migration jobs | 1 | 256MB |
| embeddings | Embedding generation | 5 | 256MB |

## Implementation

### Creating a Fetch Job

```php
<?php

namespace App\Jobs\OAuth\ExampleService;

use App\Jobs\Base\BaseFetchJob;
use App\Models\Integration;

class ExampleDataPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'example_service';
    }

    protected function getJobType(): string
    {
        return 'example_data';
    }

    protected function fetchData(): array
    {
        // Make API request using integration credentials
        $client = new ExampleClient($this->integration);
        return $client->getData();
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        ExampleDataProcess::dispatch($this->integration, $rawData);
    }
}
```

### Creating a Processing Job

```php
<?php

namespace App\Jobs\Data\ExampleService;

use App\Jobs\Base\BaseProcessingJob;

class ExampleDataProcess extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'example_service';
    }

    protected function getJobType(): string
    {
        return 'example_data';
    }

    protected function process(): void
    {
        $events = [];

        foreach ($this->rawData as $item) {
            $events[] = [
                'source_id' => $item['id'],
                'time' => $item['timestamp'],
                'domain' => 'example',
                'action' => 'example_action',
                'value' => $item['value'],
                'actor' => [
                    'concept' => 'user',
                    'type' => 'example_user',
                    'title' => $item['user_name'],
                ],
                'target' => [
                    'concept' => 'item',
                    'type' => 'example_item',
                    'title' => $item['item_name'],
                ],
            ];
        }

        $this->createEvents($events);
    }
}
```

### Idempotency

Jobs use the `EnhancedIdempotency` trait which provides:

- **Unique job IDs**: Based on service, job type, integration ID, and date/data hash
- **Duplicate detection**: Cache-based tracking of recently processed jobs
- **Content hashing**: MD5-based detection of duplicate event content
- **Circuit breaker**: Stops retries after 5 consecutive failures within an hour

```php
// Unique ID format for fetch jobs
public function uniqueId(): string
{
    return $this->serviceName . '_' . $this->getJobType() . '_' . $this->integration->id . '_' . now()->toDateString();
}

// Unique ID format for processing jobs (includes data hash)
public function uniqueId(): string
{
    return $this->serviceName . '_' . $this->getJobType() . '_' . $this->integration->id . '_' . md5(serialize($this->rawData));
}
```

## Configuration

### Horizon Settings

The Horizon configuration is in `config/horizon.php`:

```php
'environments' => [
    'production' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['pull'],
            'balance' => 'auto',
            'maxProcesses' => 5,
            'memory' => 256,
            'tries' => 3,
        ],
        'supervisor-2' => [
            'connection' => 'redis',
            'queue' => ['migration'],
            'balance' => 'auto',
            'maxProcesses' => 1,
            'memory' => 256,
            'tries' => 1,
        ],
        'supervisor-3' => [
            'connection' => 'redis',
            'queue' => ['embeddings'],
            'balance' => 'auto',
            'maxProcesses' => 5,
            'memory' => 256,
            'tries' => 1,
        ],
    ],
],
```

### Retry Settings by Job Type

| Job Type | Tries | Backoff Intervals |
|----------|-------|-------------------|
| BaseFetchJob | 3 | 1 min, 5 min, 10 min |
| BaseProcessingJob | 2 | 2 min, 5 min |
| BaseWebhookHookJob | 3 | 30 sec, 2 min, 5 min |
| BaseInitializationJob | 1 | None |

### Non-Retryable Exceptions

The circuit breaker skips retries for authentication and validation errors:

- `AuthenticationException`
- `AuthorizationException`
- `ValidationException`
- Messages containing: "invalid api key", "unauthorized", "forbidden", "invalid credentials", "access denied"

### Running Jobs

```bash
# Start Horizon (recommended)
sail artisan horizon

# Check queue status
sail artisan queue:monitor

# List failed jobs
sail artisan queue:failed

# Retry failed jobs
sail artisan queue:retry all

# Clear failed jobs
sail artisan queue:flush
```

## Related Documentation

- `CLAUDE.md`: Main project documentation including plugin system architecture
- `app/Jobs/Base/`: Base job class implementations
- `app/Jobs/Concerns/EnhancedIdempotency.php`: Idempotency trait
- `config/horizon.php`: Queue worker configuration
