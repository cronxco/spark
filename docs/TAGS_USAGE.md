# Tags Usage Guide

The Spatie Laravel Tags package has been successfully integrated into the application. Both `Event` and `EventObject` models can now have tags attached to them.

## Installation Status

✅ **Package Installed**: `spatie/laravel-tags` v4.10.0  
✅ **Migrations Run**: Tag tables created with UUID support  
✅ **Models Updated**: Both `Event` and `EventObject` models now use the `HasTags` trait  
✅ **API Updated**: Event API endpoints now automatically load tags with events  

## Basic Usage

### Attaching Tags to Events

```php
// Create an event
$event = Event::factory()->create([
    'integration_id' => $integration->id,
    'actor_id' => $actor->id,
    'target_id' => $target->id,
]);

// Attach a single tag
$event->attachTag('important');

// Attach multiple tags
$event->attachTags(['urgent', 'review', 'follow-up']);

// Check if event has a specific tag
if ($event->hasTag('important')) {
    // Do something
}

// Get all tags for an event
$tags = $event->tags; // Returns a collection of Tag models
```

### Attaching Tags to Event Objects

```php
// Create an event object
$object = EventObject::factory()->create([
    'integration_id' => $integration->id,
]);

// Attach tags
$object->attachTag('document');
$object->attachTags(['pdf', 'contract', 'signed']);

// Check tags
if ($object->hasTag('document')) {
    // Do something
}
```

### Querying by Tags

```php
// Find events with any of the specified tags
$importantEvents = Event::withAnyTags(['important', 'urgent'])->get();

// Find events with all of the specified tags
$urgentImportantEvents = Event::withAllTags(['important', 'urgent'])->get();

// Find objects with specific tags
$documentObjects = EventObject::withAnyTags(['document', 'pdf'])->get();
```

## API Integration

The Event API endpoints have been updated to automatically include tags in the response:

- `GET /api/events` - Returns events with their tags
- `GET /api/events/{id}` - Returns a specific event with its tags
- `PUT /api/events/{id}` - Updates an event and returns it with tags

### Example API Response

```json
{
  "data": [
    {
      "id": "uuid-here",
      "integration_id": "integration-uuid",
      "actor_id": "actor-uuid",
      "target_id": "target-uuid",
      "service": "github",
      "domain": "repository",
      "action": "push",
      "tags": [
        {
          "id": 1,
          "name": "important",
          "slug": "important"
        },
        {
          "id": 2,
          "name": "urgent",
          "slug": "urgent"
        }
      ],
      "created_at": "2025-08-09T13:30:00.000000Z",
      "updated_at": "2025-08-09T13:30:00.000000Z"
    }
  ]
}
```

## Database Schema

The following tables have been created:

### `tags` table
- `id` (bigint, primary key)
- `name` (json) - Tag name in multiple languages
- `slug` (json) - URL-friendly tag name
- `type` (string, nullable) - Tag type for categorization
- `order_column` (integer, nullable) - For ordering tags
- `created_at` / `updated_at` timestamps

### `taggables` table
- `tag_id` (bigint, foreign key to tags.id)
- `taggable_id` (uuid) - ID of the tagged model (Event or EventObject)
- `taggable_type` (string) - Class name of the tagged model
- Unique constraint on `(tag_id, taggable_id, taggable_type)`

## Available Methods

Both `Event` and `EventObject` models now have access to these methods:

### Tag Management
- `attachTag($tag)` - Attach a single tag
- `attachTags($tags)` - Attach multiple tags
- `detachTag($tag)` - Remove a single tag
- `detachTags($tags)` - Remove multiple tags
- `syncTags($tags)` - Replace all tags with the given ones
- `hasTag($tag)` - Check if model has a specific tag
- `hasAnyTag($tags)` - Check if model has any of the given tags
- `hasAllTags($tags)` - Check if model has all of the given tags

### Query Scopes
- `withAnyTags($tags)` - Get models with any of the specified tags
- `withAllTags($tags)` - Get models with all of the specified tags
- `withoutAnyTags($tags)` - Get models without any of the specified tags
- `withoutAllTags($tags)` - Get models without all of the specified tags

### Relationships
- `tags` - Get all tags for the model
- `tagsWithType($type)` - Get tags of a specific type

## Testing

The functionality has been tested and confirmed working. You can test it manually using Laravel Tinker:

```bash
./vendor/bin/sail artisan tinker
```

```php
// Test basic functionality
$user = App\Models\User::factory()->create();
$integration = App\Models\Integration::factory()->create(['user_id' => $user->id]);
$actor = App\Models\EventObject::factory()->create(['integration_id' => $integration->id]);
$target = App\Models\EventObject::factory()->create(['integration_id' => $integration->id]);
$event = App\Models\Event::factory()->create([
    'integration_id' => $integration->id,
    'actor_id' => $actor->id,
    'target_id' => $target->id,
]);

$event->attachTag('test');
$event->refresh();
echo 'Tags count: ' . $event->tags->count(); // Should output: Tags count: 1
```

## Next Steps

1. **Frontend Integration**: Add tag management UI to the frontend
2. **Tag Filtering**: Implement tag-based filtering in the API
3. **Tag Statistics**: Add endpoints to get tag usage statistics
4. **Tag Types**: Implement tag categorization using the `type` field
5. **Bulk Operations**: Add bulk tag operations for multiple events/objects
