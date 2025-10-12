# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Running the Application

```bash
# Start all services (server, horizon, logs, vite) - recommended
composer dev

# Individual services (using Laravel Sail)
sail up -d
sail artisan serve
sail artisan horizon
sail artisan pail --timeout=0
npm run dev
```

### Testing

```bash
# Run all tests
composer test
# Or:
sail artisan test

# Run a single test
sail artisan test --filter TestName

# Run specific test suite
sail artisan test tests/Feature
sail artisan test tests/Unit

# Run parallel tests (faster)
sail artisan test --parallel
```

### Code Quality

```bash
# Format with Duster (includes Pint + more)
sail vendor/bin/duster fix

# Lint check only (no changes)
sail vendor/bin/duster lint
```

### Database

```bash
sail artisan migrate
sail artisan migrate:fresh --seed
sail artisan migrate:rollback
```

### Queue Management

```bash
# Horizon is preferred for queue management
sail artisan horizon

# Monitor queue health
sail artisan queue:monitor

# Clear failed jobs
sail artisan queue:flush
```

## Architecture

### Integration Plugin System

This application uses a plugin-based architecture for integrations with external services (Monzo, Oura, Spotify, GitHub, etc.). Understanding this system is critical to working in the codebase.

**Key Concepts:**

- **PluginRegistry**: Central registry (`app/Integrations/PluginRegistry.php`) that maintains all available integration plugins. Plugins register themselves via `IntegrationServiceProvider`.
- **Plugin Classes**: Each service has a plugin class (e.g., `MonzoPlugin`, `OuraPlugin`) in `app/Integrations/{Service}/` that extends base classes like `OAuthPlugin`, `WebhookPlugin`, or `ManualPlugin`.
- **Plugin Interface**: All plugins implement `IntegrationPlugin` contract which defines metadata (display name, icon, accent color, domain) and configuration (action types, block types, object types, instance types).
- **Service Types**: Plugins are categorized by service type: `oauth`, `webhook`, `manual`, or `apikey`.
- **Domains**: Integrations are grouped into domains: `health`, `money`, `media`, `knowledge`, `online`.

**Plugin Configuration:**
Each plugin defines:

- **Action Types**: Event actions (e.g., "listened_to", "had_balance") with display settings
- **Block Types**: Data visualization blocks with metadata
- **Object Types**: Entity types the integration manages (e.g., accounts, playlists)
- **Instance Types**: Different integration modes (e.g., "account", "collection")

**Value Formatters:**

Action types and block types support custom value display formatting via the `value_formatter` field. This allows you to control how values are displayed in the UI using Laravel Blade templates.

Common use cases:

- **Word replacement**: Convert numeric codes to human-readable labels (e.g., resilience levels)
- **Duration formatting**: Display seconds/minutes in human-friendly format using `format_duration()` helper
- **Custom rounding**: Control decimal places for specific value types
- **Unit formatting**: Add currency symbols or format units with HTML (e.g., superscripts)

Example formatters:

```php
// Word replacement (conditional display)
'value_formatter' => '@if($value == 5)Exceptional@elseif($value == 4)Strong@elseif($value == 3)Solid@elseif($value == 2)Adequate@elseif($value == 1)Limited@else{{ $value }}@endif'

// Duration formatting (uses format_duration helper)
'value_formatter' => '{{ format_duration($value) }}'

// Currency formatting with symbol
'value_formatter' => '£{{ number_format($value, 2) }}'
```

Available variables in formatter templates:

- `$value`: The numeric value (after applying value_multiplier)
- `$unit`: The value_unit string

The `format_duration()` helper intelligently formats durations:

- Less than 60s: shows seconds only (e.g., "45s")
- Less than 1hr: shows minutes+seconds (e.g., "2m30s")
- Less than 1 day: shows hours+minutes (e.g., "3h15m")
- 1 day or more: shows days+hours (e.g., "2d5h")

### Data Model Hierarchy

The data model follows a hierarchical structure:

```
IntegrationGroup (stores OAuth tokens/credentials)
  └─> Integration (specific instance: Monzo account, Spotify profile)
      └─> EventObject (entities: bank account, playlist, device)
          └─> Event (timestamped data points: transaction, play, workout)
              └─> Block (data visualizations for specific time periods)
```

**Key Models:**

- **IntegrationGroup**: Manages shared credentials and OAuth tokens for a service
- **Integration**: A specific integration instance with its own configuration, update schedule, and state
- **EventObject**: Named entities that events are associated with (e.g., "Current Account", "Daily Notes")
- **Event**: Individual timestamped data points with action, value, and metadata
- **Block**: Aggregated/formatted data for display (e.g., daily summaries, visualizations)

### Job Architecture

**Base Job Classes:**

- `BaseFetchJob`: Template for fetching data from external APIs
    - Handles Sentry tracing, error logging, retry logic
    - Subclasses implement `fetchData()` and `dispatchProcessingJobs()`
    - Uses `EnhancedIdempotency` trait to prevent duplicate executions
- `BaseInitializationJob`: For one-time historical data backfills

**Job Patterns:**

1. **Fetch Jobs** (`app/Jobs/OAuth/{Service}/{Type}Pull.php`): Pull data from APIs
2. **Data Jobs** (`app/Jobs/Data/{Service}/{Type}Data.php`): Process and store fetched data
3. **Migration Jobs** (`app/Jobs/Migrations/`): Handle data migrations with batching and progress tracking
4. **Integration Group Deletion Jobs**: Cascading deletion with progress events

**Important:**

- Jobs use Laravel Horizon for queue management
- Jobs support idempotency via unique IDs (service + type + integration + date)
- Failed jobs automatically retry with exponential backoff
- Integration state tracking: `last_triggered_at`, `last_successful_update_at`, `isProcessing()`

### Integration Scheduling

Integrations support two scheduling modes:

1. **Frequency-based** (default): Update every N minutes (`update_frequency_minutes` in configuration)
2. **Schedule-based**: Run at specific times of day with timezone support
    - Configured via `use_schedule`, `schedule_times` (array of "HH:mm"), `schedule_timezone`
    - See `Integration::isDue()`, `getNextScheduledRun()` for logic

Integrations can be paused via `paused` configuration flag.

### API Logging System

The codebase has helper functions for structured API logging:

- `log_integration_api_request()`: Log outgoing API requests
- `log_integration_api_response()`: Log API responses
- `log_integration_webhook()`: Log incoming webhooks
- `generate_api_log_filename()`: Creates per-service or per-instance log files
- All logging automatically sanitizes sensitive data (tokens, keys, passwords)

Logs are stored in `storage/logs/api_{service}_{uuid_block}.log` with 2-day retention.

### Migration System

Data migrations use a batched processing system:

1. `StartIntegrationMigration`: Initiates migration, creates batch
2. `MonitorBatchAndStartProcessing`: Waits for batch completion, triggers processing
3. `StartProcessingIntegrationMigration`: Processes fetched data
4. `CompleteMigration`: Finalizes migration, cleans up

Progress is tracked via `ActionProgress` model and broadcast using events.

## Code Style & Conventions

### PHP Standards

- Follow PSR-1, PSR-2, PSR-12
- Use typed properties (not docblocks)
- Always specify return types including `void`
- Use short nullable syntax: `?Type` not `Type|null`
- Use constructor property promotion when all properties can be promoted
- For iterables in docblocks, use generics: `@return Collection<int, User>`

### Control Flow

- **Happy path last**: Handle error conditions first, success case at the end
- **Avoid else**: Use early returns instead of nested conditions
- **Always use curly brackets** even for single-line statements
- **Separate conditions**: Prefer multiple if statements over compound conditions

### Laravel Conventions

- URLs: kebab-case (`/open-source`)
- Route names: camelCase (`->name('openSource')`)
- Controllers: Plural resource names (`PostsController`), stick to CRUD methods
- Configuration: Files in kebab-case, keys in snake_case
- Add service configs to `config/services.php`, don't create new config files
- Use `config()` helper, avoid `env()` outside config files
- Artisan commands: kebab-case (`delete-old-records`)
- Commands should provide feedback and show progress for loops

### Frontend

- Uses Livewire 3 + Volt for reactive components
- MaryUI blade components based on daisyUI 5
- Use daisyUI semantic color names (primary, secondary, accent, etc.) instead of Tailwind colors
- Avoid custom CSS - prefer daisyUI classes and Tailwind utilities
- Follow Refactoring UI best practices for design decisions

### Testing

- Uses PHPUnit with database: PostgreSQL
- Test environment configured in `phpunit.xml`
- Keep test classes in same file when possible
- Use descriptive test method names
- Follow arrange-act-assert pattern

## Important Files

- `app/Support/helpers.php`: Global helper functions (loaded via composer autoload)
- `app/Integrations/PluginRegistry.php`: Central plugin registry
- `app/Models/Integration.php`: Core integration model with scheduling logic
- `app/Jobs/Base/BaseFetchJob.php`: Template for all fetch jobs
- `composer.json`: Defines `dev` script for running all services concurrently
- `routes/api.php`: API routes for integration webhooks and external access

## Common Tasks

### Adding a New Integration Plugin

1. Create plugin class in `app/Integrations/{Service}/`
2. Extend `OAuthPlugin`, `WebhookPlugin`, or `ManualPlugin`
3. Implement `IntegrationPlugin` contract methods
4. Define action types, block types, object types, instance types
5. Register in `IntegrationServiceProvider::boot()`
6. Create fetch jobs extending `BaseFetchJob`
7. Create data processing jobs
8. Add migration if needed for historical data

### Creating Jobs

- Extend `BaseFetchJob` for data fetching
- Use `EnhancedIdempotency` trait for uniqueness
- Implement `getServiceName()`, `getJobType()`, `fetchData()`, `dispatchProcessingJobs()`
- Use Sentry tracing for monitoring (already built into `BaseFetchJob`)
- Add proper error handling and logging

### Working with Integration Configuration

Integration configuration is stored as JSON in `configuration` column:

```php
$integration->configuration = [
    'update_frequency_minutes' => 15,
    'paused' => false,
    'use_schedule' => true,
    'schedule_times' => ['06:00', '12:00', '18:00'],
    'schedule_timezone' => 'Europe/London',
    // Service-specific config...
];
```

Access via helper methods: `getUpdateFrequencyMinutes()`, `isPaused()`, `useSchedule()`, etc.
