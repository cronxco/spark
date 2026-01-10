# Events

Events are timestamped data points that capture what happened, when, who did it, and what it affected.

## Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
    - [Database Schema](#database-schema)
    - [Key Attributes](#key-attributes)
    - [Relationships](#relationships)
    - [Unique Constraints](#unique-constraints)
    - [Indexes](#indexes)
- [Data Model](#data-model)
    - [Actor/Target Pattern](#actortarget-pattern)
    - [Value Storage](#value-storage)
    - [Metadata Fields](#metadata-fields)
    - [Location Support](#location-support)
    - [Vector Embeddings](#vector-embeddings)
- [Key Methods](#key-methods)
    - [Block Management](#block-management)
    - [Value Formatting](#value-formatting)
    - [Semantic Search](#semantic-search)
    - [Location Methods](#location-methods)
    - [Relationship Methods](#relationship-methods)
    - [Tag Management](#tag-management)
- [Usage Examples](#usage-examples)
    - [Creating Events](#creating-events)
    - [Attaching Blocks](#attaching-blocks)
    - [Creating Relationships](#creating-relationships)
    - [Semantic Search Queries](#semantic-search-queries)
    - [Location Queries](#location-queries)
    - [Value Formatting Patterns](#value-formatting-patterns)
- [Lifecycle Hooks](#lifecycle-hooks)
- [Related Documentation](#related-documentation)

## Overview

Events are the primary unit of data capture in Spark. They represent timestamped actions from integrations, capturing what happened (action), when (time), who/what did it (actor), and what was affected (target). Events can have numeric values, location data, and vector embeddings for semantic search.

Events form the foundation of the data model hierarchy:

```
Integration
  └─> Event (timestamped data point)
       └─> Block (aggregated visualizations)
```

## Architecture

### Database Schema

**Table:** `events`

**Primary Key:** `id` (UUID)

Events are soft-deletable and support tags, activity logging, and view tracking.

### Key Attributes

| Attribute              | Type          | Description                                          |
| ---------------------- | ------------- | ---------------------------------------------------- |
| `id`                   | UUID          | Primary key, auto-generated                          |
| `source_id`            | text          | External identifier from source system               |
| `time`                 | timestampTz   | When the event occurred                              |
| `integration_id`       | UUID          | Foreign key to Integration                           |
| `service`              | text          | Which service (monzo, spotify, github, etc.)         |
| `domain`               | text          | Category (health, money, media, knowledge, online)   |
| `action`               | text          | What happened (made_transaction, listened_to, etc.)  |
| `value`                | bigInteger    | Numeric value (often in smallest units, e.g., pence) |
| `value_multiplier`     | integer       | Divider for formatted value display (default 1)      |
| `value_unit`           | text          | Unit of measurement (GBP, bpm, kcal, etc.)           |
| `actor_id`             | UUID          | Foreign key to EventObject (who did it)              |
| `actor_metadata`       | JSON          | Additional actor details                             |
| `target_id`            | UUID          | Foreign key to EventObject (what it was done to)     |
| `target_metadata`      | JSON          | Additional target details                            |
| `event_metadata`       | JSON          | Extra event context                                  |
| `embeddings`           | text          | Vector embeddings for semantic search                |
| `location`             | PostGIS Point | Geographic location (latitude/longitude)             |
| `location_address`     | text          | Human-readable address                               |
| `location_geocoded_at` | timestamp     | When location was geocoded                           |
| `location_source`      | text          | Source of location data                              |
| `created_at`           | timestamp     | When record was created                              |
| `updated_at`           | timestamp     | When record was last updated                         |
| `deleted_at`           | timestamp     | When record was soft-deleted (nullable)              |

### Relationships

- `integration()` - BelongsTo Integration
- `source()` - BelongsTo EventObject (via `source_id`)
- `actor()` - BelongsTo EventObject - Who/what performed the action
- `target()` - BelongsTo EventObject - What the action was performed on
- `blocks()` - HasMany Block - Data visualizations/summaries
- `relationshipsFrom()` - MorphMany Relationship - Where this event is the "from" entity
- `relationshipsTo()` - MorphMany Relationship - Where this event is the "to" entity

### Unique Constraints

- `(integration_id, source_id)` - Prevents duplicate events from the same source

### Indexes

- Primary key index on `id`
- Index on `integration_id` for filtering by integration
- Index on `service` for filtering by service
- Index on `action` for filtering by action type
- GIST spatial index on `location` for geographic queries

## Data Model

### Actor/Target Pattern

Events use an actor/target pattern to capture "who did what to what":

- **Actor** (`actor_id`, `actor_metadata`) - The entity that performed the action
- **Target** (`target_id`, `target_metadata`) - The entity that was affected by the action

**Examples:**

```php
// Spotify listening event
$event->action = 'listened_to';
$event->actor_id = $userObject->id;  // The user
$event->target_id = $trackObject->id; // The track
$event->target_metadata = [
    'artist' => 'Artist Name',
    'album' => 'Album Name',
    'duration_ms' => 240000,
];

// Monzo transaction event
$event->action = 'made_transaction';
$event->actor_id = $accountObject->id;  // The bank account
$event->target_id = $merchantObject->id; // The merchant
$event->value = -1250; // £12.50 in pence (negative = debit)
$event->value_multiplier = 100;
$event->value_unit = 'GBP';

// GitHub commit event
$event->action = 'pushed_commit';
$event->actor_id = $userObject->id;      // The developer
$event->target_id = $repositoryObject->id; // The repository
$event->event_metadata = [
    'commit_sha' => 'abc123',
    'message' => 'Fix bug',
    'additions' => 10,
    'deletions' => 2,
];
```

### Value Storage

Events can store numeric values using three fields:

- `value` (bigInteger) - The raw value, often in smallest units
- `value_multiplier` (integer) - Divider for display (e.g., 100 for pence → pounds)
- `value_unit` (text) - Unit of measurement

**Formatted value:**

```php
$formattedValue = $event->value / $event->value_multiplier;
// Or use the accessor:
$formattedValue = $event->formatted_value;
```

**Common patterns:**

| Use Case    | value         | value_multiplier | value_unit |
| ----------- | ------------- | ---------------- | ---------- |
| Money (GBP) | 1250 (pence)  | 100              | GBP        |
| Heart rate  | 75            | 1                | bpm        |
| Distance    | 5000 (meters) | 1000             | km         |
| Calories    | 2500          | 1                | kcal       |
| Temperature | 20            | 1                | °C         |

### Metadata Fields

Three JSON fields provide flexible storage for additional context:

**`actor_metadata`** - Details about the actor:

```php
[
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'profile_url' => 'https://...',
]
```

**`target_metadata`** - Details about the target:

```php
[
    'title' => 'Item Name',
    'description' => 'Item description',
    'external_url' => 'https://...',
]
```

**`event_metadata`** - General event context:

```php
[
    'device' => 'iPhone 14 Pro',
    'platform' => 'iOS',
    'version' => '1.2.3',
    'notes' => 'User comment',
]
```

### Location Support

Events support geographic locations via PostGIS:

- `location` (PostGIS Point) - WGS84 coordinates
- `location_address` (text) - Human-readable address
- `location_geocoded_at` (timestamp) - When geocoded
- `location_source` (text) - Source (e.g., 'monzo_api', 'geoapify', 'manual', 'inherited')

See [PLACES.md](PLACES.md) for detailed location documentation.

### Vector Embeddings

Events support semantic search via vector embeddings stored in the `embeddings` field. Embeddings are automatically generated by the Task Pipeline on event creation.

**Searchable text format:**

```
Actor Action Target Value/Summary — Domain Service
```

**Example:**

```
John Doe listened to Song Name by Artist — Media Spotify
Current Account made transaction at Merchant £12.50 — Money Monzo
```

See [SEMANTIC_SEARCH.md](SEMANTIC_SEARCH.md) for implementation details.

## Key Methods

### Block Management

**`createBlock(array $blockData): Block`**

Creates or updates blocks without duplicates. This is the recommended method for attaching blocks to events.

```php
// Location: app/Models/Event.php

$event->createBlock([
    'title' => 'Heart Rate',
    'block_type' => 'biometric',
    'value' => 75,
    'value_multiplier' => 1,
    'value_unit' => 'bpm',
    'time' => now(),
]);

// If a block with the same title and block_type exists, it's updated.
// Otherwise, a new block is created.
```

> **Important**: Always use `createBlock()` or `Block::updateOrCreateForEvent()` to prevent duplicate blocks. Never use `$event->blocks()->create()` directly.

### Value Formatting

**`getFormattedValueAttribute(): ?float`**

Returns the value divided by the value_multiplier for display purposes.

```php
$event->value = 1250;
$event->value_multiplier = 100;

$formatted = $event->formatted_value; // 12.50
```

### Semantic Search

**`scopeSemanticSearch($query, string $searchQuery, int $limit = 20, float $temporalWeight = 0.3)`**

Finds events by vector similarity with temporal weighting. Recent events are ranked higher.

```php
$events = Event::semanticSearch('morning workout routine')
    ->limit(10)
    ->get();
```

**`scopeHybridSearch($query, string $searchQuery, array $filters = [], int $limit = 20)`**

Combines semantic search with metadata filters.

```php
$events = Event::hybridSearch('coffee shop', [
    'service' => 'monzo',
    'action' => 'made_transaction',
])
    ->limit(10)
    ->get();
```

**`getSearchableText(): string`**

Generates text for embeddings in the format: "Actor Action Target Value/Summary — Domain Service".

```php
$text = $event->getSearchableText();
// "Current Account made transaction at Merchant £12.50 — Money Monzo"
```

### Location Methods

**`setLocation(float $latitude, float $longitude, ?string $address = null, string $source = 'manual'): void`**

Sets the event's geographic location.

```php
$event->setLocation(51.5074, -0.1278, 'London, UK', 'monzo_api');
```

**`inheritLocationFromTarget(): bool`**

Inherits location from the target EventObject if available. Returns false if no target or target has no location.

```php
if ($event->inheritLocationFromTarget()) {
    // Location successfully inherited
    // $event->location_source = 'inherited'
}
```

**`getLatitudeAttribute(): ?float`** and **`getLongitudeAttribute(): ?float`**

Convenience accessors for coordinates.

```php
$lat = $event->latitude;  // 51.5074
$lng = $event->longitude; // -0.1278
```

### Relationship Methods

**`relatedObjects(?string $type = null)`**

Get related EventObjects via Relationships.

```php
// All related objects
$objects = $event->relatedObjects()->get();

// Only 'linked_to' relationships
$linkedObjects = $event->relatedObjects('linked_to')->get();
```

**`relatedEvents(?string $type = null)`**

Get related Events via Relationships.

```php
$relatedEvents = $event->relatedEvents()->get();
```

**`relatedBlocks(?string $type = null)`**

Get related Blocks via Relationships.

```php
$relatedBlocks = $event->relatedBlocks()->get();
```

### Tag Management

Events support Spatie Tags with activity logging.

**`attachTags($tags): void`**

Attach tags to the event.

```php
$event->attachTags(['important', 'workout', 'morning']);
```

**`detachTags($tags): void`**

Detach tags from the event.

```php
$event->detachTags(['morning']);
```

## Usage Examples

### Creating Events

**From Spotify integration:**

```php
// Location: app/Jobs/Data/Spotify/SpotifyListeningData.php

$event = Event::create([
    'source_id' => $track['id'],
    'time' => Carbon::parse($playedAt),
    'integration_id' => $integration->id,
    'service' => 'spotify',
    'domain' => 'media',
    'action' => 'listened_to',
    'actor_id' => $userObject->id,
    'target_id' => $trackObject->id,
    'target_metadata' => [
        'artist' => $track['artists'][0]['name'],
        'album' => $track['album']['name'],
        'duration_ms' => $track['duration_ms'],
        'preview_url' => $track['preview_url'],
    ],
]);
```

**From Monzo integration:**

```php
// Location: app/Jobs/Data/Monzo/MonzoTransactionData.php

$event = Event::create([
    'source_id' => $transaction['id'],
    'time' => Carbon::parse($transaction['created']),
    'integration_id' => $integration->id,
    'service' => 'monzo',
    'domain' => 'money',
    'action' => 'made_transaction',
    'value' => $transaction['amount'], // In pence
    'value_multiplier' => 100,
    'value_unit' => $transaction['currency'],
    'actor_id' => $accountObject->id,
    'target_id' => $merchantObject->id,
    'target_metadata' => [
        'category' => $transaction['category'],
        'notes' => $transaction['notes'],
        'decline_reason' => $transaction['decline_reason'],
    ],
]);

// Set location if available
if (isset($transaction['merchant']['latitude'])) {
    $event->setLocation(
        $transaction['merchant']['latitude'],
        $transaction['merchant']['longitude'],
        $transaction['merchant']['address'],
        'monzo_api'
    );
}
```

**From Oura integration:**

```php
// Location: app/Jobs/Data/Oura/OuraSleepData.php

$event = Event::create([
    'source_id' => $sleep['id'],
    'time' => Carbon::parse($sleep['bedtime_start']),
    'integration_id' => $integration->id,
    'service' => 'oura',
    'domain' => 'health',
    'action' => 'had_sleep',
    'value' => $sleep['total_sleep_duration'], // In seconds
    'value_multiplier' => 1,
    'value_unit' => 'seconds',
    'actor_id' => $userObject->id,
    'event_metadata' => [
        'efficiency' => $sleep['efficiency'],
        'restfulness' => $sleep['restfulness'],
        'rem_sleep_duration' => $sleep['rem_sleep_duration'],
        'deep_sleep_duration' => $sleep['deep_sleep_duration'],
    ],
]);
```

### Attaching Blocks

**CORRECT - Using createBlock():**

```php
// Prevents duplicates
$event->createBlock([
    'title' => 'Heart Rate',
    'block_type' => 'biometric',
    'value' => 75,
    'value_multiplier' => 1,
    'value_unit' => 'bpm',
    'time' => now(),
]);

// If called again with same title and block_type, the existing block is updated
$event->createBlock([
    'title' => 'Heart Rate',
    'block_type' => 'biometric',
    'value' => 78, // Updated value
    'value_multiplier' => 1,
    'value_unit' => 'bpm',
    'time' => now(),
]);
```

**INCORRECT - Direct create():**

```php
// Creates duplicates!
$event->blocks()->create([
    'title' => 'Heart Rate',
    'block_type' => 'biometric',
    'value' => 75,
]);

// This will create a second block with the same title
$event->blocks()->create([
    'title' => 'Heart Rate',
    'block_type' => 'biometric',
    'value' => 78,
]);
// Now you have 2 blocks with title "Heart Rate"!
```

### Creating Relationships

**Linking events together:**

```php
use App\Models\Relationship;

// Create a directional relationship
Relationship::createRelationship([
    'user_id' => $event->integration->user_id,
    'from_type' => Event::class,
    'from_id' => $event1->id,
    'to_type' => Event::class,
    'to_id' => $event2->id,
    'type' => 'caused_by', // Directional
]);
```

**Linking event to object:**

```php
Relationship::createRelationship([
    'user_id' => $event->integration->user_id,
    'from_type' => Event::class,
    'from_id' => $event->id,
    'to_type' => EventObject::class,
    'to_id' => $placeObject->id,
    'type' => 'occurred_at', // Directional
]);
```

See [RELATIONSHIPS.md](RELATIONSHIPS.md) for detailed documentation.

### Semantic Search Queries

**Basic semantic search:**

```php
$events = Event::semanticSearch('morning workout routine')
    ->limit(10)
    ->get();
```

**Hybrid search with filters:**

```php
$events = Event::hybridSearch('coffee shop', [
    'service' => 'monzo',
    'domain' => 'money',
])
    ->limit(10)
    ->get();
```

**Scoped to user:**

```php
$events = Event::forUser($userId)
    ->semanticSearch('birthday party')
    ->limit(10)
    ->get();
```

### Location Queries

**Find events with location:**

```php
$events = Event::hasLocation()->get();
```

**Find events within radius:**

```php
// Find events within 1km of London
$events = Event::withinRadius(51.5074, -0.1278, 1000) // meters
    ->get();
```

**Find events in bounding box:**

```php
// Find events in UK
$events = Event::withinBounds(
    58.6,  // north
    49.9,  // south
    1.8,   // east
    -8.0   // west
)->get();
```

**Combine location with other filters:**

```php
$events = Event::forUser($userId)
    ->where('service', 'monzo')
    ->withinRadius(51.5074, -0.1278, 5000)
    ->get();
```

### Value Formatting Patterns

**Currency:**

```php
$event->value = 1250; // Pence
$event->value_multiplier = 100;
$event->value_unit = 'GBP';

echo "£" . number_format($event->formatted_value, 2); // £12.50
```

**Duration:**

```php
$event->value = 3665; // Seconds
$event->value_multiplier = 1;
$event->value_unit = 'seconds';

// Using helper function
echo format_duration($event->value); // "1h1m5s"
```

**Distance:**

```php
$event->value = 5000; // Meters
$event->value_multiplier = 1000;
$event->value_unit = 'km';

echo $event->formatted_value . ' km'; // "5 km"
```

**Heart rate:**

```php
$event->value = 75;
$event->value_multiplier = 1;
$event->value_unit = 'bpm';

echo $event->formatted_value . ' bpm'; // "75 bpm"
```

## Lifecycle Hooks

Events have several lifecycle hooks defined in the `booted()` method:

**On creating:**

- UUID is auto-generated if not provided

**On created:**

- `ProcessTaskPipelineJob` is dispatched to the `tasks` queue
- Task pipeline handles: embedding generation, anomaly detection, receipt matching, etc.
- Can be disabled via `config('app.enable_task_pipeline', true)`

**On deleted:**

- Activity log entry created with event 'deleted'

**On restored:**

- Activity log entry created with event 'restored'

**Activity logging:**

- Only update events are logged automatically (via `$recordEvents = ['updated']`)
- Logs all fillable attributes to the `activity_log` table

**View tracking:**

- Views are tracked via the `TracksViews` trait
- Creates activity log entry with event 'viewed'

## Related Documentation

- [OBJECTS.md](OBJECTS.md) - EventObject model (actors and targets)
- [BLOCKS.md](BLOCKS.md) - Block model (visualizations)
- [RELATIONSHIPS.md](RELATIONSHIPS.md) - Relationship model (linking events)
- [PLACES.md](PLACES.md) - Location support and PostGIS integration
- [SEMANTIC_SEARCH.md](SEMANTIC_SEARCH.md) - Vector embeddings and semantic search
- [INTEGRATION_PLUGINS.md](INTEGRATION_PLUGINS.md) - How integrations create events
- [JOBS.md](JOBS.md) - Job architecture for data processing
- [TASK_PIPELINE.md](TASK_PIPELINE.md) - Event processing pipeline
- [../UI and UX/EVENTS_INTERFACE.md](../UI%20and%20UX/EVENTS_INTERFACE.md) - Event UI
- [../CLAUDE.md](../CLAUDE.md) - Development guide
