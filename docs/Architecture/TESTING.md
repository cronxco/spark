# Testing Guide

Comprehensive guide for testing in Spark.

## Overview

Spark uses PHPUnit for testing with Laravel's testing features. Tests are organized into Unit and Feature test suites, with specific patterns for testing integrations, jobs, Livewire components, and APIs.

## Quick Start

```bash
# Run all tests
sail test

# Run specific test suite
sail test --testsuite=Unit
sail test --testsuite=Feature

# Run specific test file
sail test tests/Feature/AppleHealthIntegrationTest.php

# Run specific test method
sail test --filter=webhook_ingests_workouts_creating_event_and_blocks

# Run with coverage
sail test --coverage
```

## Test Structure

```
tests/
├── Feature/                    # Integration and feature tests
│   ├── Api/                    # API endpoint tests
│   ├── Auth/                   # Authentication tests
│   ├── Integrations/           # Integration-specific tests
│   │   ├── BlueSky/
│   │   ├── Karakeep/
│   │   └── Reddit/
│   ├── Livewire/               # Livewire component tests
│   ├── Migration/              # Migration job tests
│   ├── Services/               # Service class tests
│   └── Settings/               # Settings page tests
├── Unit/                       # Unit tests
│   ├── Fetch/                  # Fetch system tests
│   ├── Helpers/                # Helper function tests
│   ├── Integrations/           # Integration unit tests
│   └── Jobs/                   # Job class tests
└── TestCase.php                # Base test case
```

## Configuration

### phpunit.xml

The PHPUnit configuration sets up a testing environment:

```xml
<php>
    <env name="APP_ENV" value="testing"/>
    <env name="BCRYPT_ROUNDS" value="4"/>
    <env name="CACHE_STORE" value="array"/>
    <env name="DB_CONNECTION" value="pgsql"/>
    <env name="MAIL_MAILER" value="array"/>
    <env name="QUEUE_CONNECTION" value="sync"/>
    <env name="SESSION_DRIVER" value="array"/>
</php>
```

Key settings:

- Uses PostgreSQL database (required for pgvector features)
- Synchronous queue for immediate job execution
- Array drivers for cache, mail, and session
- Reduced bcrypt rounds for faster tests

### Test Database

Tests use the `RefreshDatabase` trait to reset the database between tests:

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class MyTest extends TestCase
{
    use RefreshDatabase;
}
```

## Writing Tests

### Feature Tests

Feature tests test complete features including HTTP requests, database operations, and job dispatching.

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MyFeatureTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_can_view_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/dashboard');

        $response->assertStatus(200);
    }
}
```

### Unit Tests

Unit tests test individual classes and methods in isolation.

```php
<?php

namespace Tests\Unit;

use App\Integrations\Fetch\ContentExtractor;
use Tests\TestCase;

class ContentExtractorTest extends TestCase
{
    /** @test */
    public function it_extracts_content_from_valid_html(): void
    {
        $html = '<html><head><title>Test</title></head>...</html>';

        $result = ContentExtractor::extract($html, 'https://example.com');

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['data']['title']);
    }
}
```

### Test Attributes

Use PHP 8 attributes for test annotations:

```php
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

class MyTest extends TestCase
{
    #[Test]
    public function test_with_attribute(): void
    {
        // Test code
    }

    #[Test]
    #[DataProvider('dataProvider')]
    public function test_with_data_provider(string $input, string $expected): void
    {
        $this->assertEquals($expected, process($input));
    }

    public static function dataProvider(): array
    {
        return [
            ['input1', 'expected1'],
            ['input2', 'expected2'],
        ];
    }
}
```

## Testing Patterns

### Testing Integrations

Integration tests verify plugin behavior, webhook handling, and data processing.

```php
<?php

namespace Tests\Feature;

use App\Integrations\AppleHealth\AppleHealthPlugin;
use App\Integrations\PluginRegistry;
use App\Jobs\Webhook\AppleHealth\AppleHealthWebhookHook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AppleHealthIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Register plugin for test runtime
        PluginRegistry::register(AppleHealthPlugin::class);

        // Use queue fake for testing job dispatching
        Queue::fake();
    }

    #[Test]
    public function webhook_dispatches_processing_job(): void
    {
        $user = User::factory()->create();
        [$group, $workouts, $metrics] = $this->createGroupWithInstances($user);

        $payload = ['workouts' => [/* workout data */]];

        $response = $this->postJson(
            route('webhook.handle', [
                'service' => 'apple_health',
                'secret' => $group->account_id
            ]),
            $payload
        );

        $response->assertStatus(200);
        Queue::assertPushed(AppleHealthWebhookHook::class);
    }
}
```

### Testing Jobs

Test job classes in isolation:

```php
<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Data\BlueSky\BlueSkyLikesPull;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlueSkyLikesPullTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function job_processes_likes_correctly(): void
    {
        $integration = Integration::factory()->create([
            'service' => 'bluesky',
            'instance_type' => 'likes',
        ]);

        // Mock external API responses
        Http::fake([
            'bsky.social/*' => Http::response([
                'feed' => [/* mock data */]
            ], 200)
        ]);

        $job = new BlueSkyLikesPull($integration);
        $job->handle();

        $this->assertDatabaseHas('events', [
            'integration_id' => $integration->id,
        ]);
    }
}
```

### Testing APIs

Test API endpoints with authentication:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function authenticated_user_can_list_events(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['read']);

        $response = $this->getJson('/api/v1/events');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'service', 'action', 'time']
                ]
            ]);
    }

    #[Test]
    public function unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/events');

        $response->assertStatus(401);
    }
}
```

### Testing Livewire Components

Test Livewire components with the Livewire testing API:

```php
<?php

namespace Tests\Feature\Livewire;

use App\Livewire\LogViewer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LogViewerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function component_renders_successfully(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($user)
            ->test(LogViewer::class)
            ->assertStatus(200);
    }

    #[Test]
    public function can_filter_by_service(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($user)
            ->test(LogViewer::class)
            ->set('service', 'spotify')
            ->assertSee('spotify');
    }
}
```

### Mocking External Services

Use HTTP fakes for external API calls:

```php
use Illuminate\Support\Facades\Http;

#[Test]
public function handles_api_error_gracefully(): void
{
    Http::fake([
        'api.example.com/*' => Http::response(null, 500)
    ]);

    // Test error handling
}

#[Test]
public function processes_api_response(): void
{
    Http::fake([
        'api.example.com/data' => Http::response([
            'items' => [
                ['id' => 1, 'name' => 'Test']
            ]
        ], 200)
    ]);

    // Test successful processing
}
```

### Testing Queue Jobs

Use Queue fakes to verify job dispatching:

```php
use Illuminate\Support\Facades\Queue;

#[Test]
public function action_dispatches_job(): void
{
    Queue::fake();

    // Perform action that should dispatch job

    Queue::assertPushed(MyJob::class);
    Queue::assertPushed(MyJob::class, function ($job) {
        return $job->parameter === 'expected_value';
    });
}
```

## Test Helpers

### Creating Test Data

Use factories for model creation:

```php
// Create a user
$user = User::factory()->create();

// Create with specific attributes
$user = User::factory()->create([
    'email' => 'test@example.com',
    'is_admin' => true,
]);

// Create related models
$user = User::factory()
    ->has(Integration::factory()->count(3))
    ->create();
```

### Creating Integration Groups

Common pattern for integration tests:

```php
protected function createGroupWithInstances(User $user): array
{
    $group = IntegrationGroup::create([
        'user_id' => $user->id,
        'service' => 'apple_health',
        'account_id' => Str::random(32),
    ]);

    $workouts = Integration::create([
        'user_id' => $user->id,
        'integration_group_id' => $group->id,
        'service' => 'apple_health',
        'instance_type' => 'workouts',
        'name' => 'Workouts',
    ]);

    $metrics = Integration::create([
        'user_id' => $user->id,
        'integration_group_id' => $group->id,
        'service' => 'apple_health',
        'instance_type' => 'metrics',
        'name' => 'Metrics',
    ]);

    return [$group, $workouts, $metrics];
}
```

### Testing Webhooks

Pattern for webhook testing:

```php
#[Test]
public function webhook_validates_signature(): void
{
    $payload = ['data' => 'test'];
    $secret = 'webhook_secret';
    $signature = hash_hmac('sha256', json_encode($payload), $secret);

    $response = $this->postJson('/api/webhooks/service/' . $secret, $payload, [
        'X-Signature' => $signature,
    ]);

    $response->assertStatus(200);
}
```

## Best Practices

### Test Naming

Use descriptive test names that explain the scenario:

```php
// Good
public function user_can_delete_own_integration(): void
public function webhook_rejects_invalid_signature(): void
public function job_retries_on_rate_limit_error(): void

// Avoid
public function test1(): void
public function testDelete(): void
```

### Test Organization

- Group related tests in the same file
- Use `setUp()` for common test setup
- Keep tests focused on a single behavior
- Use data providers for testing multiple inputs

### Assertions

Use specific assertions for better error messages:

```php
// Preferred
$this->assertDatabaseHas('events', ['action' => 'bookmarked']);
$this->assertCount(3, $events);
$this->assertStringContainsString('error', $message);

// Less informative on failure
$this->assertTrue($exists);
$this->assertEquals(3, count($events));
```

### Test Isolation

Each test should be independent:

```php
// Good - creates own data
public function test_example(): void
{
    $user = User::factory()->create();
    // Test with fresh user
}

// Bad - relies on external state
public function test_example(): void
{
    $user = User::find(1);  // Assumes user exists
}
```

## Troubleshooting

### Common Issues

1. **Database Connection Errors**
    - Ensure PostgreSQL is running: `sail up -d`
    - Check database exists: `sail psql -c "\l"`

2. **Tests Hanging**
    - Check for infinite loops in code
    - Verify queue is set to `sync` in testing
    - Look for external HTTP calls that aren't mocked

3. **Intermittent Failures**
    - Check for time-dependent tests
    - Look for race conditions
    - Ensure tests don't share state

4. **Memory Issues**
    - Run specific test files instead of full suite
    - Use `--memory-limit` option
    - Check for memory leaks in test setup

### Debugging Tests

```bash
# Run with verbose output
sail test -v

# Run with debug output
sail test --debug

# Show deprecation warnings
sail test --display-deprecations

# Stop on first failure
sail test --stop-on-failure
```

## Continuous Integration

Tests run automatically on pull requests. Ensure:

- All tests pass locally before pushing
- New features have corresponding tests
- Test coverage doesn't decrease significantly

```bash
# Check test coverage
sail test --coverage --coverage-html=coverage

# Run specific test suite in CI
sail test --testsuite=Feature --log-junit=junit.xml
```

## Related Documentation

- [CLAUDE.md](../CLAUDE.md) - Development commands
- [INTEGRATION_PLUGINS.md](INTEGRATION_PLUGINS.md) - Plugin testing patterns
- [README_JOBS.md](README_JOBS.md) - Job testing patterns
