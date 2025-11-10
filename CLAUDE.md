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
    - Scoped by `user_id` only (NOT by `integration_id`)
    - Has relationship to `Integration` but stores no `integration_id` column
    - Identified by: `user_id`, `concept`, `type`, `title`
    - Shared across integrations for the same user
- **Event**: Individual timestamped data points with action, value, and metadata
- **Block**: Aggregated/formatted data for display (e.g., daily summaries, visualizations)
- **Relationship**: Polymorphic relationships between Events, EventObjects, and Blocks
    - User-scoped connections between any model types
    - Supports directional and bi-directional relationship types
    - Optional value/unit/multiplier fields for monetary tracking
    - Managed via `RelationshipTypeRegistry` for type configuration

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

### Creating EventObjects

EventObjects represent entities (accounts, playlists, devices, etc.) and are user-scoped, NOT integration-scoped:

```php
// CORRECT: Query by user_id, concept, type, title
$eventObject = EventObject::firstOrCreate(
    [
        'user_id' => $integration->user_id,
        'concept' => 'user',
        'type' => 'fetch_user',
        'title' => 'Fetch',
    ],
    [
        'time' => now(),
        'metadata' => ['service' => 'fetch'],
    ]
);

// INCORRECT: Do NOT use integration_id in queries
$eventObject = EventObject::firstOrCreate(
    [
        'user_id' => $integration->user_id,
        'integration_id' => $integration->id, // ❌ This column doesn't exist!
        'concept' => 'user',
        'type' => 'fetch_user',
    ],
    [...]
);
```

**Important:** EventObjects are shared across integrations for the same user. Use unique `title` values to differentiate between instances if needed.

### Creating Relationships

Relationships connect Events, EventObjects, and Blocks with typed, directional or bi-directional links:

```php
use App\Models\Relationship;
use App\Models\EventObject;

// Create a directional relationship (e.g., A links to B)
Relationship::createRelationship([
    'user_id' => $user->id,
    'from_type' => EventObject::class,
    'from_id' => $sourceObject->id,
    'to_type' => EventObject::class,
    'to_id' => $targetObject->id,
    'type' => 'linked_to', // Directional
    'metadata' => ['url' => 'https://example.com'],
]);

// Create a bi-directional relationship (e.g., A related to B = B related to A)
Relationship::createRelationship([
    'user_id' => $user->id,
    'from_type' => EventObject::class,
    'from_id' => $object1->id,
    'to_type' => EventObject::class,
    'to_id' => $object2->id,
    'type' => 'related_to', // Bi-directional (won't create duplicates)
]);

// Create a monetary relationship
Relationship::createRelationship([
    'user_id' => $user->id,
    'from_type' => EventObject::class,
    'from_id' => $fromAccount->id,
    'to_type' => EventObject::class,
    'to_id' => $toAccount->id,
    'type' => 'transferred_to',
    'value' => 10000, // £100.00 in pence
    'value_multiplier' => 100,
    'value_unit' => 'GBP',
]);

// Query relationships
$object->relationshipsFrom()->get(); // Where this is "from"
$object->relationshipsTo()->get();   // Where this is "to"
$object->relationships()->get();     // All relationships

// Get related entities
$object->relatedObjects()->get();           // All related objects
$object->relatedObjects('linked_to')->get(); // Only "linked_to" type
$object->relatedEvents()->get();            // All related events
$object->relatedBlocks()->get();            // All related blocks
```

**Available Relationship Types** (see `app/Services/RelationshipTypeRegistry.php`):

- `linked_to` - Directional, source links to target
- `related_to` - Bi-directional, general association
- `caused_by` - Directional, causal relationship
- `part_of` - Directional, hierarchical relationship
- `similar_to` - Bi-directional, similarity relationship
- `transferred_to` - Directional, money/value transfer (supports value fields)

**Important:** The old `had_link_to` event type has been migrated to the `linked_to` relationship type. Use `Relationship` model instead of creating events with `action: 'had_link_to'`.

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

## Block Card Display System

Spark uses a flexible card-based system for displaying blocks throughout the application. Blocks are automatically rendered using smart defaults with support for custom layouts.

### Overview

The block card system provides:

- Two default card variants: **value cards** (for numeric blocks) and **content cards** (for text/summary blocks)
- Custom layout support per block type
- Automatic fallback to default layouts
- Consistent styling across the application

### Using Block Cards

Display blocks using the `<x-block-card>` component:

```blade
{{-- Single block --}}
<x-block-card :block="$block" />

{{-- Grid of blocks --}}
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    @foreach ($blocks as $block)
        <x-block-card :block="$block" />
    @endforeach
</div>
```

The component automatically:

1. Checks if a custom layout exists for the block type
2. Falls back to appropriate default variant (value or content)
3. Displays all relevant metadata, timestamps, and actions

### Default Card Variants

**Value Card** (for blocks with numeric values):

- Prominent stat-style value display at top
- Block type badge and timestamp
- Title centered below value
- Compact metadata preview
- Footer with integration badge and actions

**Content Card** (for blocks without values):

- Block type badge and timestamp
- Optional image (h-48)
- Title with line-clamp-2
- Content preview with line-clamp-5
- Footer with integration badge and actions

### Creating Custom Layouts

Create custom blade files in `resources/views/blocks/types/` named after the block type:

**File naming:** `{block_type}.blade.php` (e.g., `fetch_summary_tweet.blade.php`)

**Available props:**

- `$block` - The Block model instance with all relationships loaded

**Example custom layout:**

```blade
{{-- resources/views/blocks/types/fetch_summary_tweet.blade.php --}}
@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$summary = $block->metadata['summary'] ?? '';
$charCount = mb_strlen($summary);
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Custom header --}}
        <div class="flex items-center justify-between gap-2">
            <div class="badge badge-info badge-outline badge-sm gap-1">
                <x-icon name="o-chat-bubble-left-right" class="w-3 h-3" />
                Tweet Summary
            </div>
            <div class="badge badge-ghost badge-xs">{{ $charCount }}/280</div>
        </div>

        {{-- Custom content --}}
        <div class="bg-base-100 rounded-lg p-3 border border-base-300">
            <p class="text-sm">{{ $summary }}</p>
        </div>

        {{-- Footer (keep consistent) --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            {{-- ... standard footer elements ... --}}
        </div>
    </div>
</div>
```

### Block Model Helper Methods

The Block model provides methods for working with custom layouts:

```php
// Check if custom layout exists
$block->hasCustomCardLayout(); // returns bool

// Get custom layout path
$block->getCustomCardLayoutPath(); // returns "blocks.types.{type}" or null

// Get all block types with custom layouts
Block::getBlockTypesWithCustomLayouts(); // returns array
```

### Sense-Check Page

View custom layout coverage at `/admin/sense-check`:

The "Block Types with Custom Layouts" section shows:

- Total block types defined across all plugins
- Which types have custom layouts (✓)
- High-volume types (>100 blocks) without custom layouts (highlighted)
- Block counts per type
- Service grouping

This helps identify which block types would benefit most from custom layouts.

### Best Practices

1. **Only create custom layouts when needed** - The defaults work well for most cases
2. **Keep styling consistent** - Use daisyUI classes and maintain the card structure
3. **Test responsive behavior** - Ensure layouts work on mobile, tablet, and desktop
4. **Consider accessibility** - Use semantic HTML and ARIA labels where appropriate
5. **Focus on high-volume types** - Prioritize custom layouts for frequently-used block types

### Where Blocks Display

Blocks using the card system appear in:

- Event detail pages (`/events/{event}`) - Shows all blocks linked to the event
- EventObject pages (`/objects/{object}`) - Shows blocks related via relationships
- Any custom views using the `<x-block-card>` component

The admin blocks table (`/admin/blocks`) maintains its table format for management purposes.

### Custom Layout Examples

The codebase includes example custom layouts:

- `fetch_summary_tweet` - Twitter-style card with character count
- `fetch_key_takeaways` - Bullet list with checkmarks
- `fetch_tags` - Tag cloud with emoji support
- `bookmark_summary` - AI-focused card with gradient styling
- `bookmark_metadata` - Preview card with larger image

Use these as references when creating new custom layouts.

## Spotlight Command Palette

Spark uses Wire Elements Spotlight as a keyboard-driven command palette for power users to navigate, search, and execute actions.

### Quick Reference

- **Activation**: `Cmd+K` / `Ctrl+K` or click Search button in header
- **Modes**: `>` (actions), `#` (tags), `$` (metrics), `@` (integrations), `!` (admin), `?` (help)
- **Configuration**: All registration in `app/Providers/SpotlightServiceProvider.php`
- **Queries**: Organized in `app/Spotlight/Queries/` by category (Navigation, Search, Actions, Integration)
- **Custom Actions**: `app/Spotlight/Actions/`
- **Styling**: Custom CSS in `resources/css/app.css` (lines 140-566) using Spark theme variables

### Adding Commands

Create query classes that return `SpotlightResult` objects:

```php
// In app/Spotlight/Queries/...
public static function make(): SpotlightQuery
{
    return SpotlightQuery::asDefault(function (string $query) {
        return collect([
            SpotlightResult::make()
                ->setTitle('Command Name')
                ->setSubtitle('Description')
                ->setIcon('heroicon-name')
                ->setGroup('group-name')
                ->setPriority(10)
                ->setAction('jump_to', ['path' => route('...')])
        ]);
    });
}
```

Register in `SpotlightServiceProvider::registerQueries()`.

### Integration Plugin Commands

Plugins can provide Spotlight commands by implementing `SupportsSpotlightCommands`:

```php
use App\Integrations\Contracts\SupportsSpotlightCommands;

class YourPlugin extends OAuthPlugin implements SupportsSpotlightCommands
{
    public static function getSpotlightCommands(): array
    {
        return [
            'command-key' => [
                'title' => 'Command Title',
                'subtitle' => 'Description',
                'icon' => 'icon-name',
                'action' => 'dispatch_event',
                'actionParams' => ['name' => 'event-name', 'close' => true],
                'priority' => 5,
            ],
        ];
    }
}
```

Commands are auto-discovered via `PluginRegistry::getSpotlightCommands()` - no manual registration needed.

### Context-Aware Commands

Commands can be context-aware by checking the current route:

```php
$routeName = request()->route()->getName();
if ($routeName === 'metrics.show') {
    // Show metric-specific commands
}
```

Context commands appear only on relevant pages and are prioritized first.

### Full Documentation

See `SPOTLIGHT.md` for comprehensive documentation including:

- User guide with all modes and shortcuts
- Developer guide with examples
- Architecture overview
- Adding custom commands and actions
- Integration plugin support
- Troubleshooting and best practices

## Playwright Browser Automation (Fetch Integration)

The Fetch integration supports optional browser automation via Playwright for fetching JavaScript-heavy sites, bypassing robot detection, and managing cookie sessions.

### Architecture

```
Laravel (PHP) → FetchEngineManager → Smart Router
                                        ├─→ HTTP (default, fast)
                                        └─→ Playwright (when needed)
                                              ↓
                                        Node.js Worker (Express API)
                                              ↓
                                        Chrome (CDP on port 9222)
                                              ↓
                                        VNC (debugging on port 5900)
```

### Key Components

**PHP Layer:**

- `FetchEngineManager`: Smart routing between HTTP and Playwright
- `PlaywrightFetchClient`: HTTP client to communicate with Node.js worker
- `FetchSingleUrl` job: Updated to use engine manager

**Node.js Worker:**

- Location: `docker/playwright/index.js`
- Express REST API with endpoints: `/fetch`, `/cookies/:domain`, `/health`
- Connects to Chrome via CDP (Chrome DevTools Protocol)
- Automatic reconnection on disconnect (max 5 attempts)

**Docker Services:**

- `chrome`: Chromium browser with VNC and CDP endpoint
- `playwright-worker`: Node.js service running Playwright

### Starting Playwright Services

```bash
# Start with Docker Compose profile
sail up -d --profile playwright

# Or start individual services
docker-compose up chrome playwright-worker -d

# Check service status
sail ps
curl http://localhost:3000/health
```

### Smart Routing Logic

The `FetchEngineManager` automatically selects the appropriate fetch method:

1. **Always HTTP if:**
    - Playwright disabled globally (`PLAYWRIGHT_ENABLED=false`)
    - Webpage has `playwright_preference: 'http'`

2. **Always Playwright if:**
    - Webpage has `requires_playwright: true` (learned from past success)
    - Webpage has `playwright_preference: 'playwright'`
    - Domain in JS-required list (`PLAYWRIGHT_JS_DOMAINS` env var)

3. **Auto-escalate to Playwright if:**
    - Recent HTTP errors with robot/CAPTCHA detection
    - Paywall detected
    - 2+ consecutive failures

4. **Fallback to HTTP if:**
    - Playwright worker unavailable
    - Playwright fetch fails

### Cookie Extraction from Browser

Users can manually interact with the VNC browser to log in, then extract cookies:

1. Open VNC client to `localhost:5900` (password from env)
2. Navigate and log in to target site
3. In Fetch UI (Cookies tab), enter domain and click "Extract from Browser"
4. Cookies are captured from Playwright browser context and stored

**UI Flow:**

- Cookies tab shows "Extract from Browser" button when Playwright available
- Alert box with VNC link for manual browser access
- Extracted cookies stored in same `auth_metadata.domains` structure

### Configuration

**Environment Variables:**

```env
PLAYWRIGHT_ENABLED=true
PLAYWRIGHT_WORKER_URL=http://playwright-worker:3000
PLAYWRIGHT_TIMEOUT=30000
PLAYWRIGHT_SCREENSHOT_ENABLED=true
PLAYWRIGHT_AUTO_ESCALATE=true
PLAYWRIGHT_JS_DOMAINS=twitter.com,x.com,instagram.com,facebook.com
CHROME_VNC_PORT=5900
CHROME_CDP_PORT=9222
CHROME_VNC_URL=vnc://localhost:5900
CHROME_VNC_PASSWORD=spark-dev-vnc
```

**Services Configuration:**

See `config/services.php` -> `'playwright'` array

### Metadata Tracking

EventObject (fetch_webpage) metadata includes:

- `last_fetch_method`: "http", "playwright", or "http (fallback)"
- `requires_playwright`: Boolean flag (learned automatically)
- `playwright_learned_at`: Timestamp when Playwright requirement was learned
- `playwright_preference`: User override ("auto", "http", "playwright")

### UI Features

**Subscribed URLs Tab:**

- Badge showing fetch method (HTTP/Playwright) on each URL card

**Cookies Tab:**

- "Extract from Browser" button (when Playwright available)
- VNC link in info alert
- Same cookie formats supported

**Playwright Tab (new, conditional):**

- Service status indicator
- Fetch method statistics (requires Playwright, prefers HTTP, auto)
- How it works explanation
- VNC access button
- JavaScript-required domains list

### Testing

Playwright integration includes comprehensive tests:

```bash
sail artisan test --filter FetchPlaywrightTest
```

Tests cover:

- Engine manager routing logic
- HTTP fallback when Playwright unavailable
- Learning Playwright requirements
- Auto-escalation on robot detection
- JS-required domain handling
- Metadata storage

### Troubleshooting

**Worker not connecting:**

```bash
# Check Chrome service
curl http://chrome:9222/json/version

# Check worker health
curl http://localhost:3000/health

# View worker logs
sail logs -f playwright-worker
```

**VNC not accessible:**

- Ensure port 5900 is forwarded in docker-compose.yml
- Check VNC password matches `CHROME_VNC_PASSWORD`
- Use VNC client (e.g., RealVNC, TigerVNC, macOS Screen Sharing)

**High resource usage:**

- Limit concurrent Playwright jobs in Horizon config
- Disable screenshots if not needed
- Consider using Playwright only for problematic URLs

### Worker API Reference

See `docker/playwright/README.md` for full API documentation.

### Production Considerations

- **Resource limits**: Set memory limits in docker-compose.yml
- **VNC access**: Disable VNC port in production or use SSH tunnel
- **CDP security**: Never expose port 9222 publicly
- **Scaling**: Use multiple worker instances with load balancer for high volume
- **Monitoring**: Track Playwright success/failure rates in Sentry
