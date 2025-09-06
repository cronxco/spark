# Spark Integration Jobs

This document provides information about the refactored integration job system.

## Overview

The integration system has been refactored to use a job-based architecture that separates data fetching from processing. This improves scalability, maintainability, and error handling.

## Key Features

- **Separation of Concerns**: Fetch jobs handle API calls, processing jobs handle data transformation
- **Scalability**: Different worker pools for different job types
- **Reliability**: Failed fetches don't block processing of successful data
- **Idempotency**: Jobs can be safely retried without duplicates
- **Monitoring**: Comprehensive logging and error tracking

## Getting Started

### Prerequisites

- Laravel Sail (Docker environment)
- PHP 8.1+
- Composer
- Node.js & npm

### Installation

1. Clone the repository
2. Install dependencies:

    ```bash
    composer install
    npm install
    ```

3. Set up the environment:

    ```bash
    cp .env.example .env
    php artisan key:generate
    ```

4. Start Laravel Sail:

    ```bash
    ./vendor/bin/sail up -d
    ```

5. Run migrations:
    ```bash
    sail artisan migrate
    ```

### Running Jobs

#### Queue Worker

```bash
sail artisan queue:work
```

#### Scheduler (for periodic integration updates)

```bash
sail artisan schedule:work
```

#### Manual Job Dispatch

```bash
# Dispatch a specific integration update
sail artisan tinker
```

```php
use App\Jobs\CheckIntegrationUpdates;
CheckIntegrationUpdates::dispatch();
```

## Job Structure

### Base Classes

- `App\Jobs\Base\BaseFetchJob` - For OAuth data fetching
- `App\Jobs\Base\BaseProcessingJob` - For data processing
- `App\Jobs\Base\BaseWebhookHookJob` - For webhook splitting
- `App\Jobs\Base\BaseInitializationJob` - For initial setup

### Implemented Jobs

#### Monzo (OAuth)

- **Fetch**: `MonzoAccountPull`, `MonzoTransactionPull`, `MonzoPotPull`, `MonzoBalancePull`
- **Process**: `MonzoAccountData`, `MonzoTransactionData`, `MonzoPotData`, `MonzoBalanceData`
- **Init**: `MonzoHistoricalData`

#### Apple Health (Webhook)

- **Hook**: `AppleHealthWebhookHook`
- **Process**: `AppleHealthWorkoutData`, `AppleHealthMetricData`

## Testing

### Running Tests

```bash
# Run all tests
sail artisan test

# Run specific test file
sail artisan test tests/Unit/Jobs/BaseFetchJobTest.php

# Run with coverage
sail artisan test --coverage
```

### Test Structure

```
tests/
├── Unit/
│   └── Jobs/
│       ├── BaseFetchJobTest.php
│       └── MonzoAccountPullTest.php
└── Feature/
    └── Integration/
        └── MonzoIntegrationTest.php
```

### Writing Tests

#### Unit Test Example

```php
<?php

namespace Tests\Unit\Jobs;

use App\Jobs\OAuth\Monzo\MonzoAccountPull;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonzoAccountPullTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_processing_jobs()
    {
        Queue::fake();

        $integration = Integration::factory()->create();
        $accounts = [['id' => 'acc_123', 'type' => 'uk_retail']];

        $job = new MonzoAccountPull($integration);
        $job->dispatchProcessingJobs($accounts);

        Queue::assertPushed(MonzoAccountData::class);
    }
}
```

#### Feature Test Example

```php
<?php

namespace Tests\Feature\Integration;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonzoIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_integration_flow()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'monzo',
        ]);

        // Test the complete flow from fetch to processing
        // ... test implementation
    }
}
```

## Configuration

### Queue Configuration

Jobs are configured in `config/queue.php`. You can set up different queues for different priorities:

```php
'connections' => [
    'database' => [
        'table' => 'jobs',
        'queue' => ['fetch', 'process', 'webhook', 'init'],
        // ...
    ],
],
```

### Environment Variables

Set these in your `.env` file:

```env
# Queue
QUEUE_CONNECTION=database

# Monzo OAuth
MONZO_CLIENT_ID=your_client_id
MONZO_CLIENT_SECRET=your_client_secret

# Apple Health Webhook
APPLE_HEALTH_WEBHOOK_SECRET=your_webhook_secret
```

## Monitoring

### Queue Status

```bash
# Check queue status
sail artisan queue:status

# List failed jobs
sail artisan queue:failed

# Retry failed jobs
sail artisan queue:retry all
```

### Laravel Horizon (Recommended)

Install Laravel Horizon for advanced queue monitoring:

```bash
composer require laravel/horizon
php artisan horizon:install
```

Access the dashboard at `/horizon` in your application.

## Troubleshooting

### Common Issues

#### Job Timeouts

- Check API response times
- Adjust job timeouts in job classes
- Monitor network connectivity

#### Memory Issues

- Process data in smaller chunks
- Use pagination for large datasets
- Monitor memory usage in logs

#### Duplicate Processing

- Ensure proper idempotency implementation
- Check unique job IDs
- Monitor for duplicate events

### Debugging

#### Logs

Check Laravel logs:

```bash
tail -f storage/logs/laravel.log
```

#### Queue Monitoring

```bash
# Check pending jobs
sail artisan queue:pending

# Check active jobs
sail artisan queue:active
```

#### Sentry Integration

Jobs include Sentry integration for error tracking. Check your Sentry dashboard for detailed error information.

## Development

### Adding New Integrations

1. **Create Base Classes** (if needed)
2. **Create Fetch Jobs** for OAuth integrations
3. **Create Processing Jobs** for data transformation
4. **Create Hook Jobs** for webhooks (if applicable)
5. **Update CheckIntegrationUpdates** to dispatch your jobs
6. **Add Tests** for all new jobs
7. **Update Documentation**

### Code Standards

- Use typed properties
- Follow PSR-12
- Use relative imports (`App\...` not `\App\...`)
- Add comprehensive logging
- Write tests for all jobs

### Performance Tips

- Use batch processing for high-volume data
- Implement proper caching where appropriate
- Monitor job queue lengths
- Use appropriate job priorities

## Contributing

1. Follow the established job patterns
2. Add comprehensive tests
3. Update documentation
4. Test with Laravel Sail
5. Monitor performance impact

## License

This project is licensed under the MIT License.
