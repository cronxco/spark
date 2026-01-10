# Blocks

Blocks are aggregated or formatted data visualizations derived from events, designed for display in the user interface.

## Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
    - [Database Schema](#database-schema)
    - [Key Attributes](#key-attributes)
    - [Relationships](#relationships)
    - [Unique Constraints](#unique-constraints)
- [Block Types](#block-types)
    - [Value vs Content Blocks](#value-vs-content-blocks)
    - [Block Type Field](#block-type-field)
- [Custom Card Layouts](#custom-card-layouts)
    - [Creating Custom Layouts](#creating-custom-layouts)
    - [Available Props](#available-props)
    - [Examples](#examples)
- [Media Management](#media-management)
- [Key Methods](#key-methods)
    - [Preventing Duplicates](#preventing-duplicates)
    - [Value Formatting](#value-formatting)
    - [Content Methods](#content-methods)
    - [Custom Layout Detection](#custom-layout-detection)
    - [Semantic Search](#semantic-search)
- [Usage Examples](#usage-examples)
    - [Creating Blocks from Integration Jobs](#creating-blocks-from-integration-jobs)
    - [Custom Layout Examples](#custom-layout-examples)
    - [Content vs Value Blocks](#content-vs-value-blocks)
    - [Metadata Structure Patterns](#metadata-structure-patterns)
- [Display System](#display-system)
    - [Block Card Component](#block-card-component)
    - [Default Variants](#default-variants)
    - [Where Blocks Display](#where-blocks-display)
- [Related Documentation](#related-documentation)

## Overview

Blocks are data visualizations or summaries that are linked to events. They represent aggregated metrics, formatted content, or derived insights that are optimized for display.

Examples:

- **Daily heart rate summary** - Average, min, max heart rate for a day
- **Sleep quality score** - Computed from sleep duration and efficiency
- **AI-generated summary** - LLM-generated tweet or key takeaways
- **Workout stats** - Total distance, calories, duration
- **Financial summary** - Daily spending total by category

Blocks are always linked to an event and are created with deduplication to prevent duplicate visualizations.

## Architecture

### Database Schema

**Table:** `blocks`

**Primary Key:** `id` (UUID)

Blocks are soft-deletable and support media, activity logging, and view tracking.

### Key Attributes

| Attribute          | Type        | Description                                           |
| ------------------ | ----------- | ----------------------------------------------------- |
| `id`               | UUID        | Primary key, auto-generated                           |
| `event_id`         | UUID        | Foreign key to Event (nullable)                       |
| `block_type`       | text        | Category of block (heart_rate, summary_tweet, etc.)   |
| `time`             | timestampTz | Block timestamp                                       |
| `title`            | text        | Display title (must be unique per event + block_type) |
| `value`            | bigInteger  | Numeric value (nullable)                              |
| `value_multiplier` | integer     | Divider for display (default 1)                       |
| `value_unit`       | text        | Unit (GBP, bpm, etc.)                                 |
| `metadata`         | JSON        | Extra data (content key holds markdown)               |
| `url`              | text        | Related URL                                           |
| `media_url`        | text        | Legacy media URL (migrated to Media Library)          |
| `embeddings`       | text        | Vector embeddings                                     |
| `created_at`       | timestamp   | When record was created                               |
| `updated_at`       | timestamp   | When record was last updated                          |
| `deleted_at`       | timestamp   | When record was soft-deleted (nullable)               |

### Relationships

- `event()` - BelongsTo Event
- `relationshipsFrom()` - MorphMany Relationship
- `relationshipsTo()` - MorphMany Relationship

### Unique Constraints

- `(event_id, title, block_type)` - Prevents duplicate blocks per event

This constraint ensures that calling `createBlock()` with the same `title` and `block_type` will update the existing block rather than creating a duplicate.

## Block Types

### Value vs Content Blocks

Blocks fall into two categories:

**Value Blocks** - Display numeric metrics:

- Have a `value`, `value_multiplier`, and `value_unit`
- Examples: heart rate (75 bpm), distance (5.2 km), spending (£24.50)
- Rendered with prominent stat-style display

**Content Blocks** - Display text or summaries:

- Have content stored in `metadata['content']` (markdown)
- Examples: AI summaries, tweet drafts, key takeaways, tags
- Rendered with text content and optional images

### Block Type Field

The `block_type` field categorizes blocks and determines their layout:

**Health domain:**

- `heart_rate` - Heart rate metrics
- `sleep_score` - Sleep quality scores
- `workout_summary` - Workout statistics
- `steps` - Step counts

**Finance domain:**

- `spending_summary` - Daily/weekly spending
- `balance` - Account balances
- `category_breakdown` - Spending by category

**Media domain:**

- `listening_summary` - Music listening stats
- `top_tracks` - Most played tracks
- `album_art` - Album artwork display

**Knowledge domain:**

- `fetch_summary_tweet` - AI-generated tweet
- `fetch_key_takeaways` - Bullet-point summaries
- `fetch_tags` - Extracted tags
- `bookmark_summary` - Bookmark AI summary
- `bookmark_metadata` - Bookmark preview card

## Custom Card Layouts

Blocks support custom Blade templates that override the default rendering.

### Creating Custom Layouts

**File naming:**
`resources/views/blocks/types/{block_type}.blade.php`

**Example:** `resources/views/blocks/types/fetch_summary_tweet.blade.php`

If a custom layout file exists for a block's `block_type`, it will be used instead of the default card variants.

### Available Props

Custom layouts receive a single `$block` prop with all relationships loaded:

```blade
@props(['block'])

<div class="card bg-base-200 shadow">
    <div class="card-body">
        <h3>{{ $block->title }}</h3>
        <p>{{ $block->metadata['content'] ?? '' }}</p>
    </div>
</div>
```

### Examples

The codebase includes several custom layout examples:

**`fetch_summary_tweet.blade.php`** - Twitter-style card with character count:

```blade
<div class="badge badge-info gap-1">
    <x-icon name="o-chat-bubble-left-right" class="w-3 h-3" />
    Tweet Summary
</div>
<div class="badge badge-xs">{{ mb_strlen($summary) }}/280</div>
```

**`fetch_key_takeaways.blade.php`** - Bullet list with checkmarks:

```blade
@foreach ($takeaways as $takeaway)
    <div class="flex gap-2">
        <x-icon name="o-check-circle" class="w-4 h-4 text-success" />
        <span>{{ $takeaway }}</span>
    </div>
@endforeach
```

**`fetch_tags.blade.php`** - Tag cloud with emoji support:

```blade
<div class="flex flex-wrap gap-2">
    @foreach ($tags as $tag)
        <span class="badge badge-primary">{{ $tag }}</span>
    @endforeach
</div>
```

**`bookmark_summary.blade.php`** - AI-focused card with gradient:

```blade
<div class="bg-gradient-to-br from-purple-500/10 to-pink-500/10">
    <div class="flex items-center gap-2">
        <x-icon name="o-sparkles" class="w-4 h-4 text-purple-500" />
        <span class="text-xs font-semibold text-purple-700">AI Summary</span>
    </div>
</div>
```

**`bookmark_metadata.blade.php`** - Preview card with larger image:

```blade
@if ($imageUrl)
    <img src="{{ $imageUrl }}" class="h-48 w-full object-cover" />
@endif
```

## Media Management

Blocks support media attachments via Spatie Media Library.

**Media Collections:**

- `downloaded_images` - Images (album art, previews, etc.)
- `downloaded_videos` - Video content
- `downloaded_documents` - Documents

**Media Conversions:**

- `thumbnail` (300x300) - Non-queued
- `medium` (800px) - Queued
- `webp` (800px) - Queued WebP variant

See [MEDIA.md](MEDIA.md) for detailed documentation.

## Key Methods

### Preventing Duplicates

**`static updateOrCreateForEvent(string $eventId, array $attributes): Block`**

Creates or updates a block without duplicates. This is the recommended method when creating blocks outside of the Event model.

```php
// Location: app/Models/Block.php

$block = Block::updateOrCreateForEvent($event->id, [
    'title' => 'Heart Rate',
    'block_type' => 'biometric',
    'value' => 75,
    'value_multiplier' => 1,
    'value_unit' => 'bpm',
    'time' => now(),
]);
```

**`Event::createBlock(array $blockData): Block`**

The preferred method for creating blocks. Calls `updateOrCreateForEvent` internally.

```php
// Location: app/Models/Event.php

$event->createBlock([
    'title' => 'Heart Rate',
    'block_type' => 'biometric',
    'value' => 75,
    'value_multiplier' => 1,
    'value_unit' => 'bpm',
]);
```

> **Important**: Always use `createBlock()` or `updateOrCreateForEvent()`. Never use `$event->blocks()->create()` directly as it creates duplicates.

### Value Formatting

**`getFormattedValueAttribute(): ?float`**

Returns the value divided by the value_multiplier.

```php
$block->value = 1250;
$block->value_multiplier = 100;

$formatted = $block->formatted_value; // 12.50
```

### Content Methods

**`getContent(): ?string`**

Gets markdown content from `metadata['content']`.

```php
$content = $block->getContent();
```

**`setContent(string $content): void`**

Sets markdown content in `metadata['content']`.

```php
$block->setContent('# Summary\n\nThis is a summary.');
$block->save();
```

**`hasContent(): bool`**

Checks if block has content in metadata.

```php
if ($block->hasContent()) {
    echo $block->getContent();
}
```

**`getContentAsHtml(): ?string`**

Converts markdown content to HTML using a Markdown parser.

```php
$html = $block->getContentAsHtml();
// "<h1>Summary</h1><p>This is a summary.</p>"
```

### Custom Layout Detection

**`hasCustomCardLayout(): bool`**

Checks if a custom Blade view exists for this block's type.

```php
if ($block->hasCustomCardLayout()) {
    $path = $block->getCustomCardLayoutPath();
    // "blocks.types.fetch_summary_tweet"
}
```

**`getCustomCardLayoutPath(): ?string`**

Returns the Blade view path if custom layout exists, null otherwise.

```php
$path = $block->getCustomCardLayoutPath();
// Returns: "blocks.types.{block_type}" or null
```

**`static getBlockTypesWithCustomLayouts(): array`**

Returns all block types that have custom layouts.

```php
$types = Block::getBlockTypesWithCustomLayouts();
// ['fetch_summary_tweet', 'fetch_key_takeaways', 'fetch_tags', ...]
```

### Semantic Search

**`scopeSemanticSearch($query, string $searchQuery, int $limit = 20)`**

Vector similarity search.

```php
$blocks = Block::semanticSearch('workout summary')
    ->limit(10)
    ->get();
```

**`scopeHybridSearch($query, string $searchQuery, array $filters = [], int $limit = 20)`**

Semantic search combined with metadata filters.

```php
$blocks = Block::hybridSearch('heart rate', [
    'block_type' => 'biometric',
])
    ->limit(10)
    ->get();
```

## Usage Examples

### Creating Blocks from Integration Jobs

**Spotify listening summary:**

```php
// Location: app/Jobs/Data/Spotify/SpotifyListeningData.php

$event->createBlock([
    'title' => 'Listening Summary',
    'block_type' => 'listening_stats',
    'time' => now(),
    'metadata' => [
        'total_plays' => 15,
        'unique_tracks' => 12,
        'total_duration_ms' => 3600000,
        'top_artist' => 'Artist Name',
    ],
]);
```

**Oura sleep score:**

```php
// Location: app/Jobs/Data/Oura/OuraSleepData.php

$event->createBlock([
    'title' => 'Sleep Score',
    'block_type' => 'sleep_quality',
    'value' => 85,
    'value_multiplier' => 1,
    'value_unit' => 'score',
    'time' => now(),
    'metadata' => [
        'efficiency' => 92,
        'restfulness' => 78,
        'rem_percentage' => 22,
        'deep_percentage' => 18,
    ],
]);
```

**GitHub commit summary:**

```php
// Location: app/Jobs/Data/GitHub/GitHubCommitData.php

$event->createBlock([
    'title' => 'Commit Stats',
    'block_type' => 'code_changes',
    'time' => now(),
    'metadata' => [
        'additions' => 120,
        'deletions' => 45,
        'files_changed' => 8,
        'commits_count' => 3,
    ],
]);
```

**Monzo spending summary:**

```php
// Location: app/Jobs/Data/Monzo/MonzoTransactionData.php

$event->createBlock([
    'title' => 'Daily Spending',
    'block_type' => 'spending_summary',
    'value' => 2450, // £24.50 in pence
    'value_multiplier' => 100,
    'value_unit' => 'GBP',
    'time' => now()->startOfDay(),
    'metadata' => [
        'transaction_count' => 5,
        'categories' => [
            'eating_out' => 1200,
            'groceries' => 850,
            'transport' => 400,
        ],
    ],
]);
```

**Fetch AI summary:**

```php
// Location: app/Jobs/Data/Fetch/FetchGenerateSummaryJob.php

$event->createBlock([
    'title' => 'AI Summary Tweet',
    'block_type' => 'fetch_summary_tweet',
    'time' => now(),
    'metadata' => [
        'content' => 'AI-generated tweet-length summary of the article...',
        'model' => 'claude-3-5-sonnet-20241022',
        'tokens_used' => 150,
    ],
]);

$event->createBlock([
    'title' => 'Key Takeaways',
    'block_type' => 'fetch_key_takeaways',
    'time' => now(),
    'metadata' => [
        'content' => "- First key takeaway\n- Second key takeaway\n- Third key takeaway",
    ],
]);

$event->createBlock([
    'title' => 'Tags',
    'block_type' => 'fetch_tags',
    'time' => now(),
    'metadata' => [
        'tags' => ['AI', 'Machine Learning', 'Neural Networks'],
    ],
]);
```

### Custom Layout Examples

**CORRECT - Using createBlock:**

```php
// This prevents duplicates
$event->createBlock([
    'title' => 'AI Summary',
    'block_type' => 'bookmark_summary',
    'time' => now(),
    'metadata' => [
        'content' => 'Summary text here...',
    ],
]);

// Calling again updates the existing block
$event->createBlock([
    'title' => 'AI Summary',
    'block_type' => 'bookmark_summary',
    'time' => now(),
    'metadata' => [
        'content' => 'Updated summary text...',
    ],
]);
```

**CORRECT - Using updateOrCreateForEvent:**

```php
$block = Block::updateOrCreateForEvent($event->id, [
    'title' => 'Tweet Summary',
    'block_type' => 'fetch_summary_tweet',
    'time' => now(),
    'metadata' => [
        'content' => 'Tweet-length summary...',
    ],
]);
```

**INCORRECT - Direct create:**

```php
// ❌ Creates duplicates!
$event->blocks()->create([
    'title' => 'AI Summary',
    'block_type' => 'bookmark_summary',
    'metadata' => ['content' => 'Summary...'],
]);

// This creates a SECOND block with the same title!
$event->blocks()->create([
    'title' => 'AI Summary',
    'block_type' => 'bookmark_summary',
    'metadata' => ['content' => 'Updated...'],
]);
// Now you have 2 blocks with title "AI Summary"!
```

### Content vs Value Blocks

**Value block (numeric metric):**

```php
$event->createBlock([
    'title' => 'Heart Rate',
    'block_type' => 'biometric',
    'value' => 75,           // Numeric value
    'value_multiplier' => 1,
    'value_unit' => 'bpm',
    'time' => now(),
]);

// Display: "75 bpm" in stat-style card
```

**Content block (text/markdown):**

```php
$event->createBlock([
    'title' => 'AI Summary',
    'block_type' => 'fetch_summary_tweet',
    'time' => now(),
    'metadata' => [
        'content' => 'This article discusses...', // Text content
    ],
]);

// Display: Text in content card
```

**Hybrid block (both value and content):**

```php
$event->createBlock([
    'title' => 'Workout Summary',
    'block_type' => 'workout_stats',
    'value' => 5200,         // Distance in meters
    'value_multiplier' => 1000,
    'value_unit' => 'km',
    'time' => now(),
    'metadata' => [
        'content' => 'Great workout! You covered 5.2km in 45 minutes.',
        'duration_minutes' => 45,
        'calories' => 420,
    ],
]);

// Display: Stat + content in custom layout
```

### Metadata Structure Patterns

**Simple content:**

```json
{
    "content": "Markdown content here..."
}
```

**Rich metadata:**

```json
{
    "content": "# Summary\n\nDetailed content...",
    "word_count": 150,
    "reading_time_minutes": 1,
    "generated_at": "2025-01-10T12:00:00Z",
    "model": "claude-3-5-sonnet-20241022",
    "tokens_used": 200
}
```

**Tags:**

```json
{
    "tags": ["AI", "Machine Learning", "Python"],
    "confidence": 0.95
}
```

**Metrics:**

```json
{
    "total_count": 10,
    "unique_count": 8,
    "average": 75.5,
    "min": 60,
    "max": 90,
    "categories": {
        "category_a": 5,
        "category_b": 3,
        "category_c": 2
    }
}
```

## Display System

### Block Card Component

Blocks are displayed using the `<x-block-card>` Blade component:

```blade
<x-block-card :block="$block" />
```

This component:

1. Checks if a custom layout exists for the block's type
2. Falls back to appropriate default variant (value or content)
3. Displays metadata, timestamps, and actions

### Default Variants

**Value Card** - For blocks with numeric values:

- Prominent stat-style value display at top
- Block type badge and timestamp
- Title centered below value
- Compact metadata preview
- Footer with integration badge and actions

**Content Card** - For blocks without values:

- Block type badge and timestamp
- Optional image (h-48)
- Title with line-clamp-2
- Content preview with line-clamp-5
- Footer with integration badge and actions

### Where Blocks Display

Blocks appear in:

**Event detail pages** (`/events/{event}`) - Shows all blocks linked to the event

**EventObject pages** (`/objects/{object}`) - Shows blocks related via relationships

**Custom views** - Any view using the `<x-block-card>` component

**Admin blocks table** (`/admin/blocks`) - Maintains table format for management

## Related Documentation

- [EVENTS.md](EVENTS.md) - Event model (creates blocks)
- [OBJECTS.md](OBJECTS.md) - EventObject model
- [RELATIONSHIPS.md](RELATIONSHIPS.md) - Relationship model (linking blocks)
- [MEDIA.md](MEDIA.md) - Media attachment details
- [SEMANTIC_SEARCH.md](SEMANTIC_SEARCH.md) - Vector search implementation
- [../UI and UX/BLOCK_CARDS.md](../UI%20and%20UX/BLOCK_CARDS.md) - Block card display system
- [INTEGRATION_PLUGINS.md](INTEGRATION_PLUGINS.md) - How integrations create blocks
- [JOBS.md](JOBS.md) - Job architecture for data processing
- [../CLAUDE.md](../CLAUDE.md) - Development guide
