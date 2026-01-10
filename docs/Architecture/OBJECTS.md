# Objects

EventObjects are user-scoped entities that events reference, representing things like bank accounts, playlists, people, places, and documents.

## Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
    - [Database Schema](#database-schema)
    - [Key Attributes](#key-attributes)
    - [Relationships](#relationships)
    - [User-Scoped Design](#user-scoped-design)
    - [Concept vs Type](#concept-vs-type)
- [Media Management](#media-management)
    - [Media Collections](#media-collections)
    - [Media Conversions](#media-conversions)
    - [Helper Methods](#helper-methods)
- [Locking System](#locking-system)
- [Location Support](#location-support)
- [Key Methods](#key-methods)
    - [Semantic Search](#semantic-search)
    - [Location Management](#location-management)
    - [Relationship Methods](#relationship-methods)
    - [Tag Management](#tag-management)
    - [Media Helpers](#media-helpers)
- [Usage Examples](#usage-examples)
    - [Creating Objects Correctly](#creating-objects-correctly)
    - [Real-World Examples](#real-world-examples)
    - [Attaching Media](#attaching-media)
    - [Location Queries](#location-queries)
    - [Locking Objects](#locking-objects)
- [Common Patterns](#common-patterns)
- [Related Documentation](#related-documentation)

## Overview

EventObjects (database table: `objects`) are named entities that events relate to. They represent real-world things like:

- **Bank accounts** - Monzo current account, savings account
- **Music tracks** - Spotify songs, albums, artists
- **Places** - Coffee shops, gyms, home
- **Documents** - Web pages, articles, notes
- **People** - GitHub users, social media profiles
- **Devices** - Fitness trackers, phones

**CRITICAL**: EventObjects are **user-scoped**, NOT integration-scoped. They are identified by `user_id`, `concept`, `type`, and `title` — there is NO `integration_id` column. Multiple integrations for the same user can share the same EventObject.

EventObjects can have media attachments (images, videos, documents), support location tracking, and have vector embeddings for semantic search.

## Architecture

### Database Schema

**Table:** `objects`

**Primary Key:** `id` (UUID)

EventObjects are soft-deletable and support tags, media, activity logging, and view tracking.

### Key Attributes

| Attribute              | Type          | Description                                                      |
| ---------------------- | ------------- | ---------------------------------------------------------------- |
| `id`                   | UUID          | Primary key, auto-generated                                      |
| `user_id`              | UUID          | Foreign key to User (CRITICAL: user-scoped)                      |
| `concept`              | text          | Category of object (user, account, track, device, etc.)          |
| `type`                 | text          | Specific type within concept (spotify_track, bank_account, etc.) |
| `title`                | text          | Display name (lockable)                                          |
| `content`              | text          | Full content/description (lockable)                              |
| `metadata`             | JSON          | Custom data (locked flag, integration info, etc.)                |
| `url`                  | text          | Associated URL                                                   |
| `media_url`            | text          | Legacy media URL (migrated to Media Library)                     |
| `embeddings`           | vector        | Vector embeddings for semantic search                            |
| `time`                 | timestampTz   | When this object was created/updated                             |
| `location`             | PostGIS Point | Geographic location                                              |
| `location_address`     | text          | Human-readable address                                           |
| `location_geocoded_at` | timestamp     | When location was geocoded                                       |
| `location_source`      | text          | Source of location data                                          |
| `created_at`           | timestamp     | When record was created                                          |
| `updated_at`           | timestamp     | When record was last updated                                     |
| `deleted_at`           | timestamp     | When record was soft-deleted (nullable)                          |

### Relationships

- `user()` - BelongsTo User
- `integration()` - BelongsTo Integration (nullable, resolved from metadata or joins)
- `actorEvents()` - HasMany Event (where this is `actor_id`)
- `targetEvents()` - HasMany Event (where this is `target_id`)
- `events()` - Union of actorEvents and targetEvents
- `relationshipsFrom()` - MorphMany Relationship
- `relationshipsTo()` - MorphMany Relationship

### User-Scoped Design

**CRITICAL CONCEPT**: EventObjects are scoped by `user_id` ONLY, not by `integration_id`.

This means:

- Multiple integrations for the same user can reference the same EventObject
- EventObjects are shared across integrations
- There is NO `integration_id` column on the `objects` table

**Why user-scoped?**

- Enables cross-integration relationships (e.g., Spotify tracks linked to calendar events)
- Prevents duplicate objects when a user has multiple integrations
- Simplifies data model by centralizing entities per user

**Identification:**
EventObjects are uniquely identified by:

- `user_id`
- `concept`
- `type`
- `title`

### Concept vs Type

EventObjects use two fields to categorize entities:

**`concept`** - Broad category:

- `user` - Person or profile
- `account` - Financial account
- `track` - Music or audio
- `device` - Physical device
- `place` - Location or venue
- `document` - Web page, article, note
- `repository` - Code repository
- `transaction` - Financial transaction

**`type`** - Specific implementation:

- `spotify_track`, `spotify_artist`, `spotify_album`
- `monzo_account`, `gocardless_account`
- `fetch_webpage`, `outline_document`
- `github_repository`, `github_user`
- `oura_device`, `hevy_device`

**Example:**

```php
// Spotify track
$object->concept = 'track';
$object->type = 'spotify_track';

// Monzo account
$object->concept = 'account';
$object->type = 'monzo_account';

// Fetch webpage
$object->concept = 'document';
$object->type = 'fetch_webpage';
```

## Media Management

EventObjects support media attachments via Spatie Media Library with MD5-based deduplication.

### Media Collections

| Collection             | Purpose                                   |
| ---------------------- | ----------------------------------------- |
| `screenshots`          | Browser screenshots from Playwright/Fetch |
| `error_screenshots`    | Error state screenshots                   |
| `pdfs`                 | PDF documents                             |
| `downloaded_images`    | Images from integration APIs              |
| `downloaded_videos`    | Video content                             |
| `downloaded_documents` | Other document types                      |
| `article_images`       | Primary article images (singleFile)       |

### Media Conversions

Automatic conversions for images:

- `thumbnail` (300x300) - Non-queued for immediate availability
- `medium` (800px) - Queued via Horizon
- `webp` (800px) - Queued WebP variant

### Helper Methods

**`registerMediaCollections()`** - Defines available collections

**`registerMediaConversions(Media $media)`** - Defines image conversions

See [MEDIA.md](MEDIA.md) for detailed media documentation.

## Locking System

EventObjects can be locked to prevent accidental updates to `title` and `content` fields.

**`isLocked(): bool`**

Checks if the object is locked via `metadata['locked']`.

```php
if ($object->isLocked()) {
    // Object is locked
}
```

**`lock(): void`**

Sets `metadata['locked']` to true.

```php
$object->lock();
```

**`unlock(): void`**

Sets `metadata['locked']` to false.

```php
$object->unlock();
```

**Automatic protection:**

The `updating` lifecycle hook automatically reverts changes to `title` and `content` if the object is locked:

```php
protected static function booted()
{
    static::updating(function ($model) {
        if ($model->isLocked()) {
            $original = $model->getOriginal();

            // Revert title and content to original values
            if ($model->isDirty('title')) {
                $model->title = $original['title'];
            }
            if ($model->isDirty('content')) {
                $model->content = $original['content'];
            }
        }
    });
}
```

**Use cases:**

- Prevent automated jobs from overwriting user-edited titles
- Protect important objects from accidental updates
- Lock objects that have been manually curated

## Location Support

EventObjects support geographic locations via PostGIS. See [PLACES.md](PLACES.md) for detailed documentation.

**Location scopes:**

- `hasLocation()` - Filter to objects with location
- `withinRadius($lat, $lng, $radiusMeters)` - Geographic radius search
- `withinBounds($north, $south, $east, $west)` - Bounding box search

## Key Methods

### Semantic Search

**`scopeSemanticSearch($query, string $searchQuery, int $limit = 20)`**

Finds objects by vector similarity.

```php
$objects = EventObject::semanticSearch('coffee shop near me')
    ->limit(10)
    ->get();
```

**`scopeHybridSearch($query, string $searchQuery, array $filters = [], int $limit = 20)`**

Combines semantic search with metadata filters.

```php
$objects = EventObject::hybridSearch('workout app', [
    'concept' => 'device',
])
    ->limit(10)
    ->get();
```

**`getSearchableText(): string`**

Generates text for embeddings.

```php
$text = $object->getSearchableText();
// "Song Title by Artist — Album Name"
```

### Location Management

**`setLocation(float $latitude, float $longitude, ?string $address = null, string $source = 'manual'): void`**

Sets the object's geographic location.

```php
$object->setLocation(51.5074, -0.1278, 'London, UK', 'geoapify');
```

**`getLatitudeAttribute(): ?float`** and **`getLongitudeAttribute(): ?float`**

Convenience accessors for coordinates.

```php
$lat = $object->latitude;
$lng = $object->longitude;
```

### Relationship Methods

**`relatedObjects(?string $type = null)`**

Get related EventObjects via Relationships.

```php
$relatedObjects = $object->relatedObjects()->get();
```

**`relatedEvents(?string $type = null)`**

Get related Events via Relationships.

```php
$relatedEvents = $object->relatedEvents()->get();
```

**`relatedBlocks(?string $type = null)`**

Get related Blocks via Relationships.

```php
$relatedBlocks = $object->relatedBlocks()->get();
```

### Tag Management

**`attachTags($tags): void`**

Attach tags to the object.

```php
$object->attachTags(['favorite', 'important']);
```

**`detachTags($tags): void`**

Detach tags from the object.

```php
$object->detachTags(['favorite']);
```

### Media Helpers

**`addMedia($file)`** - Add media file

**`getMedia($collection)`** - Get media from collection

**`getFirstMedia($collection)`** - Get first media item

**`getFirstMediaUrl($collection, $conversion)`** - Get media URL

See [MEDIA.md](MEDIA.md) for detailed usage.

## Usage Examples

### Creating Objects Correctly

**CORRECT - User-scoped:**

```php
// Query by user_id, concept, type, title
$object = EventObject::firstOrCreate([
    'user_id' => $integration->user_id,
    'concept' => 'track',
    'type' => 'spotify_track',
    'title' => 'Song Name',
], [
    'content' => 'Full track details...',
    'time' => now(),
    'metadata' => [
        'artist' => 'Artist Name',
        'album' => 'Album Name',
        'duration_ms' => 240000,
    ],
]);
```

**INCORRECT - Using integration_id:**

```php
// ❌ This column doesn't exist!
$object = EventObject::firstOrCreate([
    'user_id' => $integration->user_id,
    'integration_id' => $integration->id, // ❌ WRONG
    'concept' => 'track',
    'type' => 'spotify_track',
], [
    // ...
]);
```

**Storing integration association:**

If you need to track which integration created the object, use metadata:

```php
$object = EventObject::firstOrCreate([
    'user_id' => $integration->user_id,
    'concept' => 'account',
    'type' => 'monzo_account',
    'title' => 'Current Account',
], [
    'time' => now(),
    'metadata' => [
        'integration_id' => $integration->id, // Store in metadata
        'account_number' => '****1234',
        'sort_code' => '11-22-33',
    ],
]);
```

### Real-World Examples

**Spotify track object:**

```php
// Location: app/Jobs/Data/Spotify/SpotifyListeningData.php

$trackObject = EventObject::firstOrCreate([
    'user_id' => $integration->user_id,
    'concept' => 'track',
    'type' => 'spotify_track',
    'title' => $track['name'],
], [
    'content' => sprintf(
        '%s by %s on %s',
        $track['name'],
        $track['artists'][0]['name'],
        $track['album']['name']
    ),
    'time' => now(),
    'url' => $track['external_urls']['spotify'],
    'media_url' => $track['album']['images'][0]['url'] ?? null,
    'metadata' => [
        'spotify_id' => $track['id'],
        'artist' => $track['artists'][0]['name'],
        'album' => $track['album']['name'],
        'duration_ms' => $track['duration_ms'],
        'popularity' => $track['popularity'],
    ],
]);
```

**Monzo merchant object:**

```php
// Location: app/Jobs/Data/Monzo/MonzoTransactionData.php

$merchantObject = EventObject::firstOrCreate([
    'user_id' => $integration->user_id,
    'concept' => 'place',
    'type' => 'merchant',
    'title' => $merchant['name'],
], [
    'content' => $merchant['address']['formatted'] ?? '',
    'time' => now(),
    'metadata' => [
        'merchant_id' => $merchant['id'],
        'category' => $merchant['category'],
        'online' => $merchant['online'] ?? false,
    ],
]);

// Set location if available
if (isset($merchant['latitude'])) {
    $merchantObject->setLocation(
        $merchant['latitude'],
        $merchant['longitude'],
        $merchant['address']['formatted'] ?? null,
        'monzo_api'
    );
}
```

**Fetch webpage object:**

```php
// Location: app/Jobs/Data/Fetch/FetchUrlData.php

$webpageObject = EventObject::firstOrCreate([
    'user_id' => $integration->user_id,
    'concept' => 'document',
    'type' => 'fetch_webpage',
    'title' => $pageTitle,
], [
    'content' => $markdownContent,
    'time' => now(),
    'url' => $url,
    'metadata' => [
        'fetched_at' => now()->toISOString(),
        'fetch_method' => 'playwright',
        'word_count' => str_word_count($markdownContent),
        'reading_time_minutes' => ceil(str_word_count($markdownContent) / 200),
    ],
]);

// Lock to prevent overwriting user edits
$webpageObject->lock();
```

**GitHub repository object:**

```php
// Location: app/Jobs/Data/GitHub/GitHubRepositoryData.php

$repositoryObject = EventObject::firstOrCreate([
    'user_id' => $integration->user_id,
    'concept' => 'repository',
    'type' => 'github_repository',
    'title' => $repo['full_name'],
], [
    'content' => $repo['description'] ?? '',
    'time' => now(),
    'url' => $repo['html_url'],
    'metadata' => [
        'repo_id' => $repo['id'],
        'language' => $repo['language'],
        'stars' => $repo['stargazers_count'],
        'forks' => $repo['forks_count'],
        'private' => $repo['private'],
    ],
]);
```

**Oura device object:**

```php
// Location: app/Jobs/Data/Oura/OuraPersonalInfoData.php

$deviceObject = EventObject::firstOrCreate([
    'user_id' => $integration->user_id,
    'concept' => 'device',
    'type' => 'oura_device',
    'title' => 'Oura Ring',
], [
    'content' => sprintf('Oura Ring (Generation %s)', $personalInfo['age']),
    'time' => now(),
    'metadata' => [
        'generation' => $personalInfo['age'],
        'email' => $personalInfo['email'],
        'weight' => $personalInfo['weight'],
        'height' => $personalInfo['height'],
    ],
]);
```

### Attaching Media

**From URL with deduplication:**

```php
use App\Services\Media\MediaDownloadHelper;

$helper = app(MediaDownloadHelper::class);

// Download and attach
$media = $helper->downloadAndAttachMedia(
    'https://example.com/image.jpg',
    $object,
    'downloaded_images',
    ['alt_text' => 'Description']
);

// If another object downloads the same URL, the file is only stored once
```

**From base64 data:**

```php
$helper = app(MediaDownloadHelper::class);

$media = $helper->attachMediaFromBase64(
    $base64Data,
    $object,
    'screenshot.png',
    'screenshots'
);
```

**Displaying media:**

```blade
@php
$imageUrl = get_media_url($object, 'downloaded_images', 'thumbnail');
@endphp

@if ($imageUrl)
    <img src="{{ $imageUrl }}" alt="{{ $object->title }}">
@endif
```

### Location Queries

**Find objects with location:**

```php
$objects = EventObject::hasLocation()->get();
```

**Find places within radius:**

```php
$places = EventObject::where('concept', 'place')
    ->withinRadius(51.5074, -0.1278, 1000) // 1km
    ->get();
```

**Find objects in bounding box:**

```php
$objects = EventObject::withinBounds(
    58.6,  // north
    49.9,  // south
    1.8,   // east
    -8.0   // west
)->get();
```

**Combine with semantic search:**

```php
$objects = EventObject::hybridSearch('coffee shop', [
    'concept' => 'place',
])
    ->withinRadius(51.5074, -0.1278, 5000)
    ->get();
```

### Locking Objects

**Lock object to prevent updates:**

```php
// After user edits a webpage title
$webpageObject = EventObject::find($id);
$webpageObject->title = $userInput;
$webpageObject->save();

// Lock to prevent fetch job from overwriting
$webpageObject->lock();
```

**Check lock status:**

```php
if ($object->isLocked()) {
    // Skip automated updates
    return;
}

// Safe to update
$object->title = $newTitle;
$object->save();
```

**Unlock object:**

```php
$object->unlock();

// Now updates are allowed again
$object->title = $newTitle;
$object->save();
```

## Common Patterns

### firstOrCreate Pattern

Always use `firstOrCreate` to prevent duplicate objects:

```php
$object = EventObject::firstOrCreate(
    [
        // Unique identifiers
        'user_id' => $user->id,
        'concept' => 'account',
        'type' => 'bank_account',
        'title' => 'Current Account',
    ],
    [
        // Additional attributes (only set on create)
        'content' => 'Account details...',
        'time' => now(),
        'metadata' => [...],
    ]
);
```

### Metadata Structure

Store integration-specific data in metadata:

```php
$object->metadata = [
    'integration_id' => $integration->id,
    'external_id' => 'abc123',
    'last_sync_at' => now()->toISOString(),
    'custom_field' => 'value',
    'locked' => false, // For locking system
];
```

### Integration Association

To track which integration an object belongs to:

```php
// Store in metadata
$object->metadata['integration_id'] = $integration->id;

// Or query via join
$objects = EventObject::where('user_id', $user->id)
    ->whereHas('events', function ($query) use ($integration) {
        $query->where('integration_id', $integration->id);
    })
    ->get();
```

### Concept/Type Naming

Use consistent naming:

```php
// Good
$object->concept = 'track';
$object->type = 'spotify_track';

// Good
$object->concept = 'account';
$object->type = 'monzo_account';

// Bad (inconsistent)
$object->concept = 'SpotifyTrack';
$object->type = 'track';
```

## Related Documentation

- [EVENTS.md](EVENTS.md) - Event model (uses objects as actors/targets)
- [BLOCKS.md](BLOCKS.md) - Block model
- [RELATIONSHIPS.md](RELATIONSHIPS.md) - Relationship model (linking objects)
- [PLACES.md](PLACES.md) - Location support and Place model
- [MEDIA.md](MEDIA.md) - Media attachment details
- [SEMANTIC_SEARCH.md](SEMANTIC_SEARCH.md) - Vector search implementation
- [INTEGRATION_PLUGINS.md](INTEGRATION_PLUGINS.md) - How integrations create objects
- [JOBS.md](JOBS.md) - Job architecture for data processing
- [../CLAUDE.md](../CLAUDE.md) - Development guide
