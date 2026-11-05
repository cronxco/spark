# Places

Spark provides comprehensive location tracking and geographic features via PostGIS integration across Events and EventObjects.

## Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
    - [PostGIS Integration](#postgis-integration)
    - [Point Geometry Storage](#point-geometry-storage)
    - [Models with Location Support](#models-with-location-support)
    - [Location Fields](#location-fields)
- [Location Data Flow](#location-data-flow)
    - [How Locations Are Captured](#how-locations-are-captured)
    - [Geocoding Workflow](#geocoding-workflow)
    - [Location Inheritance](#location-inheritance)
- [Place Model](#place-model)
    - [Overview](#place-model-overview)
    - [Key Methods](#place-key-methods)
    - [Place Metadata](#place-metadata)
- [GeocodingService](#geocodingservice)
    - [Overview](#geocoding-overview)
    - [Methods](#geocoding-methods)
    - [Two-Level Caching](#two-level-caching)
    - [Rate Limiting](#rate-limiting)
- [PlaceDetectionService](#placedetectionservice)
    - [Overview](#place-detection-overview)
    - [Smart Place Detection](#smart-place-detection)
    - [Visit Tracking](#visit-tracking)
- [Location Sources](#location-sources)
- [Key Methods](#key-methods)
    - [Event Location Methods](#event-location-methods)
    - [EventObject Location Methods](#eventobject-location-methods)
    - [Location Scopes](#location-scopes)
- [Usage Examples](#usage-examples)
    - [Setting Location on Events](#setting-location-on-events)
    - [Setting Location on Objects](#setting-location-on-objects)
    - [Location Inheritance Patterns](#location-inheritance-patterns)
    - [Radius Queries](#radius-queries)
    - [Bounding Box Queries](#bounding-box-queries)
    - [Real-World Integration Examples](#real-world-integration-examples)
    - [Combining Location with Semantic Search](#combining-location-with-semantic-search)
    - [Place Detection Workflow](#place-detection-workflow)
- [Integration Patterns](#integration-patterns)
    - [Which Integrations Provide Location](#which-integrations-provide-location)
    - [Extracting Location from API Responses](#extracting-location-from-api-responses)
    - [Geocoding When Only Address Available](#geocoding-when-only-address-available)
    - [Best Practices for Location Accuracy](#best-practices-for-location-accuracy)
    - [Handling Missing Location Data](#handling-missing-location-data)
    - [Place Relationship Creation](#place-relationship-creation)
- [PostGIS Setup](#postgis-setup)
    - [Database Requirements](#database-requirements)
    - [Migration Patterns](#migration-patterns)
    - [Index Creation](#index-creation)
    - [GeocodingCache Table](#geocodingcache-table)
- [Geocoding Details](#geocoding-details)
    - [Environment Variables](#environment-variables)
    - [Cache Hit Tracking](#cache-hit-tracking)
    - [Address Normalization](#address-normalization)
    - [Coordinate Precision](#coordinate-precision)
- [Performance Considerations](#performance-considerations)
- [Related Documentation](#related-documentation)

## Overview

Spark provides location tracking and geographic features through PostGIS, allowing Events and EventObjects to store coordinates, addresses, and support spatial queries. The system includes smart place detection, geocoding services, and relationship-based location tracking.

**Key Features:**

- PostGIS geographic point storage (WGS84 coordinates)
- Geocoding via Geoapify API with two-level caching
- Smart place detection with visit tracking
- Location inheritance from objects to events
- Spatial queries (radius search, bounding box)
- Integration with multiple services (Monzo, Google Calendar, Daily Checkin, Apple Health)

## Architecture

### PostGIS Integration

Spark uses PostgreSQL's PostGIS extension for geographic data storage and queries.

**Extension:** `postgis`

**Coordinate System:** WGS84 (SRID 4326) - Standard latitude/longitude

**Geometry Type:** `GEOGRAPHY(POINT, 4326)` - Accurate distance calculations

### Point Geometry Storage

Coordinates are stored using the `Clickbar\Magellan\Data\Geometries\Point` class:

```php
use Clickbar\Magellan\Data\Geometries\Point;

// Creating points
$point = Point::makeGeodetic($latitude, $longitude);

// Getting coordinates
$latitude = $point->getLatitude();
$longitude = $point->getLongitude();

// Automatic casting
protected $casts = [
    'location' => Point::class,
];
```

### Models with Location Support

Two models support location tracking:

**Event Model** (`events` table):

- `location` (PostGIS Point)
- `location_address` (text)
- `location_geocoded_at` (timestamp)
- `location_source` (text)

**EventObject Model** (`objects` table):

- Same location fields as Event
- Includes Place model (extends EventObject)

### Location Fields

| Field                  | Type          | Description                            |
| ---------------------- | ------------- | -------------------------------------- |
| `location`             | PostGIS Point | Latitude/longitude coordinates (WGS84) |
| `location_address`     | text          | Human-readable address                 |
| `location_geocoded_at` | timestamp     | When location was geocoded             |
| `location_source`      | varchar       | Source of location data                |

## Location Data Flow

### How Locations Are Captured

Locations enter the system through:

1. **Direct API coordinates** - Services like Monzo provide latitude/longitude
2. **Geocoding addresses** - Services like Google Calendar provide addresses that are geocoded
3. **Manual entry** - Users provide coordinates via Daily Checkin
4. **GPS tracking** - Apple Health provides workout routes with GPS points
5. **Location inheritance** - Events inherit location from target objects

### Geocoding Workflow

When only an address is available:

1. Check GeocodingCache for existing result (by address hash)
2. If not found, call Geoapify API (rate limit check first)
3. Store result in GeocodingCache with hit count
4. Update model with coordinates and set `location_source` to 'geoapify'

### Location Inheritance

Events can inherit location from their target EventObject:

```php
$event->inheritLocationFromTarget();
// Copies target->location to event->location
// Sets location_source to 'inherited'
```

## Place Model

### Place Model Overview

The Place model extends EventObject with location-specific features:

```php
// Location: app/Models/Place.php

class Place extends EventObject
{
    // Special concept and type
    protected $attributes = [
        'concept' => 'place',
        'type' => 'place',
    ];
}
```

Places represent physical locations where events occur (coffee shops, gyms, homes).

### Place Key Methods

**`isNearLocation(float $lat, float $lng, int $radiusMeters = 50): bool`**

Checks if a point is within the place's radius using ST_DWithin.

```php
if ($place->isNearLocation(51.5074, -0.1278, 50)) {
    // Point is within 50m of place
}
```

**`eventsHere()`**

Returns events with 'occurred_at' relationship to this place.

```php
$events = $place->eventsHere()->get();
```

**`eventsNearby(int $radiusMeters = 50)`**

Fallback query for events within radius (when relationships not used).

```php
$events = $place->eventsNearby(100)->get();
```

### Place Metadata

Places store visit tracking and categorization in metadata:

```php
[
    'visit_count' => 5,
    'first_visit_at' => '2025-01-01T10:00:00Z',
    'last_visit_at' => '2025-01-10T12:00:00Z',
    'category' => 'cafe', // Auto-categorized
    'detection_radius_meters' => 50,
    'is_favorite' => false,
]
```

## GeocodingService

### Geocoding Overview

The GeocodingService handles address geocoding and reverse geocoding via the Geoapify API.

**Location:** `app/Services/GeocodingService.php`

**API:** Geoapify (https://api.geoapify.com/v1)

**Rate Limit:** 3000 requests/day (default, configurable)

### Geocoding Methods

**`geocode(string $address): ?array`**

Converts address to coordinates.

```php
$result = $service->geocode('123 Main St, London, UK');
// Returns:
[
    'latitude' => 51.5074,
    'longitude' => -0.1278,
    'formatted_address' => '123 Main St, London, UK',
    'country_code' => 'gb',
    'source' => 'geoapify',
]
```

**`reverseGeocode(float $lat, float $lng): ?array`**

Converts coordinates to address.

```php
$result = $service->reverseGeocode(51.5074, -0.1278);
// Returns:
[
    'latitude' => 51.5074,
    'longitude' => -0.1278,
    'formatted_address' => 'London, UK',
    'country_code' => 'gb',
    'address_components' => [
        'name' => 'City Name',
        'street' => 'Street Name',
        'housenumber' => '123',
        // ... more components
    ],
]
```

**`canMakeRequest(): bool`**

Checks if daily rate limit allows more requests.

```php
if ($service->canMakeRequest()) {
    $result = $service->geocode($address);
}
```

**`getRateLimitStatus(): array`**

Returns current rate limit usage.

```php
$status = $service->getRateLimitStatus();
// Returns: ['used' => 250, 'limit' => 3000, 'remaining' => 2750]
```

### Two-Level Caching

1. **L1: Memory Cache (Redis)** - Tracks daily rate limit
2. **L2: Database Cache (GeocodingCache)** - Stores geocoded addresses permanently

Benefits:

- Reduces API calls
- Faster response times
- Handles popular addresses efficiently

### Rate Limiting

**Daily Limit:** 3000 requests (configurable via `GEOAPIFY_DAILY_LIMIT`)

**Tracking:** Redis counter reset daily at midnight

**Behavior:** Returns null if limit reached

## PlaceDetectionService

### Place Detection Overview

The PlaceDetectionService intelligently manages place entities to prevent duplicates.

**Location:** `app/Services/PlaceDetectionService.php`

### Smart Place Detection

**`detectOrCreatePlace(float $lat, float $lng, ?string $address, User $user, int $searchRadiusMeters = 50): Place`**

Finds or creates a place within the search radius.

**Process:**

1. Search for existing place within radius (50m default)
2. If found, return existing place
3. If not found:
    - Reverse geocode if no address provided
    - Generate title from address
    - Guess category from address keywords
    - Create new Place entity
    - Set location with source 'place_detection'

**Category Guessing:**
Extracts keywords like "cafe", "restaurant", "gym", "park" from address.

```php
$place = $service->detectOrCreatePlace(
    51.5074,
    -0.1278,
    'Starbucks, London',
    $user,
    50 // 50m search radius
);

// If called again with similar coordinates, returns existing place
```

### Visit Tracking

**`linkEventToPlace(Event $event, Place $place): Relationship`**

Creates 'occurred_at' relationship and updates visit tracking.

```php
$relationship = $service->linkEventToPlace($event, $place);

// Place metadata updated:
// - visit_count incremented
// - last_visit_at set to event time
// - first_visit_at set if first visit
```

## Location Sources

Recognized sources with trust levels:

| Source            | Description                | Trust Level | When Used                   |
| ----------------- | -------------------------- | ----------- | --------------------------- |
| `monzo_api`       | Monzo merchant coordinates | High        | Direct from transaction API |
| `geoapify`        | Geocoded via Geoapify      | Medium-High | Address → coordinates       |
| `daily_checkin`   | User-provided coordinates  | High        | Manual check-in entry       |
| `apple_health`    | Apple Health GPS tracking  | High        | Workout routes              |
| `manual`          | User manually set          | High        | Direct coordinate entry     |
| `inherited`       | Copied from related object | Medium      | Event inheritance           |
| `place_detection` | Auto-detected place        | Medium      | Place detection service     |
| `google_calendar` | Geocoded calendar event    | Medium      | Calendar location field     |

## Key Methods

### Event Location Methods

**`setLocation(float $lat, float $lng, ?string $address = null, string $source = 'manual'): void`**

```php
// Location: app/Models/Event.php

$event->setLocation(
    51.5074,
    -0.1278,
    'London, UK',
    'monzo_api'
);
```

**`inheritLocationFromTarget(): bool`**

```php
if ($event->inheritLocationFromTarget()) {
    // Location successfully inherited from target
    // $event->location_source = 'inherited'
}
```

**`getLatitudeAttribute(): ?float`** and **`getLongitudeAttribute(): ?float`**

```php
$lat = $event->latitude;  // 51.5074
$lng = $event->longitude; // -0.1278
```

### EventObject Location Methods

**`setLocation(float $latitude, float $longitude, ?string $address = null, string $source = 'manual'): void`**

```php
// Location: app/Models/EventObject.php

$object->setLocation(
    51.5074,
    -0.1278,
    'London, UK',
    'geoapify'
);
```

**`getLatitudeAttribute(): ?float`** and **`getLongitudeAttribute(): ?float`**

```php
$lat = $object->latitude;
$lng = $object->longitude;
```

### Location Scopes

Available on both Event and EventObject models.

**`scopeHasLocation($query)`**

Filter to records with location.

```php
$events = Event::hasLocation()->get();
```

**`scopeWithinRadius($query, float $lat, float $lng, int $radiusMeters)`**

Find records within radius (uses ST_DWithin).

```php
// Find events within 1km of London
$events = Event::withinRadius(51.5074, -0.1278, 1000)->get();
```

**`scopeWithinBounds($query, float $north, float $south, float $east, float $west)`**

Find records in bounding box (uses ST_MakeEnvelope).

```php
// Find events in UK
$events = Event::withinBounds(58.6, 49.9, 1.8, -8.0)->get();
```

## Usage Examples

### Setting Location on Events

**From Monzo transaction with merchant coordinates:**

```php
// Location: app/Jobs/Data/Monzo/MonzoTransactionData.php

if (isset($transaction['merchant']['latitude'])) {
    $event->setLocation(
        $transaction['merchant']['latitude'],
        $transaction['merchant']['longitude'],
        $transaction['merchant']['address']['formatted'] ?? null,
        'monzo_api'
    );
}
```

### Setting Location on Objects

**From Google Calendar event geocoding:**

```php
// Location: app/Jobs/Data/GoogleCalendar/GoogleCalendarEventData.php

$geocodingService = app(GeocodingService::class);

if ($event['location'] && !filter_var($event['location'], FILTER_VALIDATE_URL)) {
    $result = $geocodingService->geocode($event['location']);

    if ($result) {
        $eventObject->setLocation(
            $result['latitude'],
            $result['longitude'],
            $result['formatted_address'],
            'geoapify'
        );
    }
}
```

### Location Inheritance Patterns

**Event inherits from target object:**

```php
// After creating event with target object
if ($event->target && $event->target->location) {
    $event->inheritLocationFromTarget();
    $event->save();
}
```

### Radius Queries

**Find events within 1km:**

```php
$events = Event::withinRadius(51.5074, -0.1278, 1000)
    ->where('service', 'monzo')
    ->get();
```

**Find places within 100m:**

```php
$places = EventObject::where('concept', 'place')
    ->withinRadius(51.5074, -0.1278, 100)
    ->get();
```

### Bounding Box Queries

**Find objects in viewport:**

```php
$objects = EventObject::withinBounds(
    52.0,  // north
    51.0,  // south
    0.5,   // east
    -0.5   // west
)->get();
```

### Real-World Integration Examples

**Monzo: merchant locations → place detection → visit tracking:**

```php
// Location: app/Jobs/Data/Monzo/MonzoTransactionData.php

$placeDetectionService = app(PlaceDetectionService::class);

// Detect or create place
$place = $placeDetectionService->detectOrCreatePlace(
    $merchant['latitude'],
    $merchant['longitude'],
    $merchant['address']['formatted'],
    $integration->user,
    50 // 50m radius
);

// Link event to place
$relationship = $placeDetectionService->linkEventToPlace($event, $place);

// Place metadata now includes:
// - visit_count (incremented)
// - last_visit_at (set to event time)
// - category (auto-categorized from address)
```

**Google Calendar: address geocoding → event location:**

```php
// Location: app/Jobs/Data/GoogleCalendar/GoogleCalendarEventData.php

$geocodingService = app(GeocodingService::class);

// Skip virtual meeting URLs
if (filter_var($calendarEvent['location'], FILTER_VALIDATE_URL)) {
    return; // Skip Zoom/Google Meet links
}

// Geocode physical address
$result = $geocodingService->geocode($calendarEvent['location']);

if ($result) {
    $event->setLocation(
        $result['latitude'],
        $result['longitude'],
        $result['formatted_address'],
        'geoapify'
    );
}
```

**Daily Checkin: manual coordinates → optional place linking:**

```php
// Location: app/Integrations/DailyCheckin/DailyCheckinPlugin.php

if ($latitude && $longitude) {
    $event->setLocation($latitude, $longitude, $address, 'daily_checkin');

    // Optional place detection
    $placeService = app(PlaceDetectionService::class);
    $place = $placeService->detectOrCreatePlace(
        $latitude,
        $longitude,
        $address,
        $integration->user
    );

    $placeService->linkEventToPlace($event, $place);
}
```

**Apple Health: workout GPS routes → first point as event location:**

```php
// Location: app/Jobs/Data/AppleHealth/AppleHealthWorkoutData.php

if (!empty($workout['route'])) {
    $firstPoint = $workout['route'][0];

    $event->setLocation(
        $firstPoint['lat'],
        $firstPoint['lng'],
        null,
        'apple_health'
    );

    // Store full route in metadata
    $event->event_metadata['route_points'] = $workout['route'];
}
```

### Combining Location with Semantic Search

**Hybrid location + content queries:**

```php
$events = Event::hybridSearch('coffee shop', [
    'service' => 'monzo',
])
    ->withinRadius(51.5074, -0.1278, 5000)
    ->limit(10)
    ->get();
```

### Place Detection Workflow

**Search existing → reverse geocode → create new:**

```php
$placeService = app(PlaceDetectionService::class);

// 1. Search for existing place within 50m
// 2. If not found, reverse geocode to get address
// 3. Generate title from address (e.g., "Starbucks, London")
// 4. Guess category from keywords
// 5. Create Place entity with metadata
$place = $placeService->detectOrCreatePlace(
    51.5074,
    -0.1278,
    null, // Will reverse geocode
    $user,
    50
);

// Result:
// - $place->title = "Starbucks, London"
// - $place->metadata['category'] = 'cafe'
// - $place->location_source = 'place_detection'
```

## Integration Patterns

### Which Integrations Provide Location

| Integration     | Domain    | Location Type        | How Location is Captured     |
| --------------- | --------- | -------------------- | ---------------------------- |
| Monzo           | Money     | Merchant coordinates | Direct from transaction API  |
| Google Calendar | Knowledge | Event address        | Geocoded from location field |
| Daily Checkin   | Health    | Manual entry         | User-provided coordinates    |
| Apple Health    | Health    | GPS route            | Workout route tracking       |
| Untappd         | Media     | Brewery location     | From check-in data           |

### Extracting Location from API Responses

**Latitude/longitude pairs:**

```php
// Direct coordinates
if (isset($apiData['latitude']) && isset($apiData['longitude'])) {
    $event->setLocation(
        $apiData['latitude'],
        $apiData['longitude'],
        $apiData['address'] ?? null,
        'api_name'
    );
}
```

### Geocoding When Only Address Available

**Use GeocodingService:**

```php
$geocodingService = app(GeocodingService::class);

if ($geocodingService->canMakeRequest()) {
    $result = $geocodingService->geocode($address);

    if ($result) {
        $object->setLocation(
            $result['latitude'],
            $result['longitude'],
            $result['formatted_address'],
            'geoapify'
        );
    }
}
```

### Best Practices for Location Accuracy

**5 decimal places = ~1.1m precision:**

```php
// Good precision for cache lookup
$latitude = round($latitude, 5);   // 51.50740
$longitude = round($longitude, 5); // -0.12780
```

**Set appropriate source:**

```php
// Be specific about source
$event->setLocation($lat, $lng, $address, 'monzo_api'); // Not 'api'
```

**Handle timezone awareness:**

```php
// Store in UTC, display in user's timezone
$event->time = Carbon::parse($timestamp)->setTimezone('UTC');
```

### Handling Missing Location Data

**Null checks:**

```php
if ($event->location) {
    // Process location-dependent logic
}
```

**Inheritance patterns:**

```php
// Try to inherit from target
if (!$event->location && $event->target) {
    $event->inheritLocationFromTarget();
}
```

**Graceful degradation:**

```php
// Use address if coordinates not available
$displayLocation = $event->location
    ? sprintf('%s, %s', $event->latitude, $event->longitude)
    : ($event->location_address ?? 'Unknown location');
```

### Place Relationship Creation

**'occurred_at' relationship type:**

```php
use App\Models\Relationship;

Relationship::createRelationship([
    'user_id' => $event->integration->user_id,
    'from_type' => Event::class,
    'from_id' => $event->id,
    'to_type' => EventObject::class,
    'to_id' => $place->id,
    'type' => 'occurred_at', // Directional
]);
```

## PostGIS Setup

### Database Requirements

**Extension:** `postgis`

**Installation:**

```sql
CREATE EXTENSION IF NOT EXISTS postgis;
```

**Migration file:** `2025_12_25_120651_enable_postgis_extension.php`

### Migration Patterns

**Location columns for events:**

```php
// Migration: 2025_12_25_120706_add_location_to_events_table.php

Schema::table('events', function (Blueprint $table) {
    $table->geography('location', 'point', 4326)->nullable();
    $table->text('location_address')->nullable();
    $table->timestamp('location_geocoded_at')->nullable();
    $table->string('location_source')->nullable();
});
```

**Location columns for objects:**

```php
// Migration: 2025_12_25_120729_add_location_to_event_objects_table.php

Schema::table('objects', function (Blueprint $table) {
    $table->geography('location', 'point', 4326)->nullable();
    $table->text('location_address')->nullable();
    $table->timestamp('location_geocoded_at')->nullable();
    $table->string('location_source')->nullable();
});
```

### Index Creation

**GIST spatial indexes:**

```php
DB::statement('CREATE INDEX events_location_idx ON events USING GIST(location)');
DB::statement('CREATE INDEX objects_location_idx ON objects USING GIST(location)');
```

These indexes optimize:

- `ST_DWithin()` - Radius queries
- `&&` operator - Bounding box overlap queries
- Geographic coordinate lookups

### GeocodingCache Table

**Migration:** `2025_12_25_120813_create_geocoding_cache_table.php`

```php
Schema::create('geocoding_cache', function (Blueprint $table) {
    $table->id();
    $table->string('address_hash', 64)->unique();
    $table->geography('location', 'point', 4326);
    $table->text('formatted_address');
    $table->string('country_code', 2)->nullable();
    $table->json('address_components')->nullable();
    $table->integer('hit_count')->default(0);
    $table->timestamp('last_used_at');
    $table->timestamps();
});

DB::statement('CREATE INDEX geocoding_cache_location_idx ON geocoding_cache USING GIST(location)');
```

## Geocoding Details

### Environment Variables

```env
GEOAPIFY_API_KEY=your_api_key_here
GEOAPIFY_DAILY_LIMIT=3000
```

**Config file:** `config/services.php`

```php
'geoapify' => [
    'api_key' => env('GEOAPIFY_API_KEY'),
    'base_url' => 'https://api.geoapify.com/v1',
    'daily_limit' => env('GEOAPIFY_DAILY_LIMIT', 3000),
],
```

### Cache Hit Tracking

**Increment hit count:**

```php
// Location: app/Models/GeocodingCache.php

public function recordHit(): void
{
    $this->increment('hit_count');
    $this->update(['last_used_at' => now()]);
}
```

**Popular addresses:**

Addresses with high `hit_count` indicate frequently geocoded locations.

### Address Normalization

**Hashing algorithm:**

```php
// Location: app/Models/GeocodingCache.php

public static function hashAddress(string $address): string
{
    // Lowercase, trim, normalize whitespace
    $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $address)));

    // SHA256 hash
    return hash('sha256', $normalized);
}
```

### Coordinate Precision

**5 decimal places for cache lookup:**

```php
// ~1.1m precision
$latitude = round($latitude, 5);
$longitude = round($longitude, 5);
```

**10m buffer for nearby cache checks:**

```php
// Check if cached location exists within 10m
$cached = GeocodingCache::query()
    ->whereRaw('ST_DWithin(location, ST_GeogFromText(?), ?)', [
        "POINT({$longitude} {$latitude})",
        10, // 10 meters
    ])
    ->first();
```

## Performance Considerations

**GIST indexes:**

- Optimize `ST_DWithin()` (radius queries)
- Optimize bounding box overlap queries
- Essential for large datasets

**Two-level caching:**

- Redis: Tracks rate limits (fast, temporary)
- Database: Stores geocoded addresses (permanent)
- Reduces API calls by ~80%

**Place detection:**

- Prevents duplicate place entities
- 50m default radius balances accuracy and deduplication
- Configurable via `detection_radius_meters` in place metadata

**User-scoped locations:**

- Locations shared across integrations for same user
- Reduces storage and geocoding requests
- Enables cross-integration location queries

## Related Documentation

- [EVENTS.md](EVENTS.md) - Event location support
- [OBJECTS.md](OBJECTS.md) - EventObject location support
- [RELATIONSHIPS.md](RELATIONSHIPS.md) - Place relationships
- [INTEGRATION_PLUGINS.md](INTEGRATION_PLUGINS.md) - How integrations provide location
- [../Integrations/MONZO_INTEGRATION.md](../Integrations/MONZO_INTEGRATION.md) - Merchant locations
- [../Integrations/GOOGLE_CALENDAR_INTEGRATION.md](../Integrations/GOOGLE_CALENDAR_INTEGRATION.md) - Calendar event locations
- [../Integrations/DAILYCHECKIN_INTEGRATION.md](../Integrations/DAILYCHECKIN_INTEGRATION.md) - Manual location entry
- [../Integrations/APPLE_HEALTH_INTEGRATION.md](../Integrations/APPLE_HEALTH_INTEGRATION.md) - GPS workout routes
- [../CLAUDE.md](../CLAUDE.md) - Development guide
