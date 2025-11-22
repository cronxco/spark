# Tags Usage

This guide covers the Spatie Laravel Tags integration for categorizing Events and EventObjects.

## Overview

The application uses `spatie/laravel-tags` for tagging functionality. Both `Event` and `EventObject` models support tags via the `HasTags` trait. Tags are automatically included in API responses.

## Basic Usage

### Attaching Tags

```php
// Single tag
$event->attachTag('important');

// Multiple tags
$event->attachTags(['urgent', 'review', 'follow-up']);

// Same for EventObjects
$object->attachTags(['document', 'pdf', 'contract']);
```

### Checking Tags

```php
if ($event->hasTag('important')) {
    // Do something
}

$tags = $event->tags; // Collection of Tag models
```

### Querying by Tags

```php
// Events with any of the tags
$events = Event::withAnyTags(['important', 'urgent'])->get();

// Events with all tags
$events = Event::withAllTags(['important', 'urgent'])->get();
```

## Available Methods

### Tag Management

| Method | Description |
|--------|-------------|
| `attachTag($tag)` | Attach a single tag |
| `attachTags($tags)` | Attach multiple tags |
| `detachTag($tag)` | Remove a single tag |
| `detachTags($tags)` | Remove multiple tags |
| `syncTags($tags)` | Replace all tags |
| `hasTag($tag)` | Check for specific tag |
| `hasAnyTag($tags)` | Check for any of given tags |
| `hasAllTags($tags)` | Check for all given tags |

### Query Scopes

| Scope | Description |
|-------|-------------|
| `withAnyTags($tags)` | Models with any specified tag |
| `withAllTags($tags)` | Models with all specified tags |
| `withoutAnyTags($tags)` | Models without any specified tag |
| `withoutAllTags($tags)` | Models without all specified tags |

## API Integration

Tags are automatically loaded in Event API responses:

- `GET /api/events` - Events with tags
- `GET /api/events/{id}` - Single event with tags
- `PUT /api/events/{id}` - Updated event with tags

### Response Format

```json
{
  "data": {
    "id": "uuid-here",
    "service": "github",
    "action": "push",
    "tags": [
      {"id": 1, "name": "important", "slug": "important"},
      {"id": 2, "name": "urgent", "slug": "urgent"}
    ]
  }
}
```

## Database Schema

### Tags Table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| name | json | Tag name (multilingual) |
| slug | json | URL-friendly name |
| type | string | Tag category (nullable) |
| order_column | integer | Sort order (nullable) |

### Taggables Table

| Column | Type | Description |
|--------|------|-------------|
| tag_id | bigint | Foreign key to tags |
| taggable_id | uuid | Tagged model ID |
| taggable_type | string | Tagged model class |

## Testing

```bash
sail artisan tinker
```

```php
$event = Event::factory()->create([...]);
$event->attachTag('test');
$event->refresh();
echo $event->tags->count(); // 1
```
