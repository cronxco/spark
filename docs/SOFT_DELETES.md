# Soft Deletes

Soft deletes allow records to be marked as deleted without removing them from the database, enabling data recovery and maintaining referential integrity.

## Overview

All core models use Laravel's `SoftDeletes` trait. When a record is deleted, the `deleted_at` timestamp is set instead of removing the row. Relationships are configured with `withTrashed()` to maintain referential integrity.

## Supported Models

| Model | Description |
|-------|-------------|
| Integration | User integrations with external services |
| EventObject | Objects that participate in events (actors/targets) |
| Event | Events that occur in the system |
| Block | Content blocks associated with events |

## Database Schema

All model tables include a `deleted_at` column:

```sql
ALTER TABLE events ADD COLUMN deleted_at TIMESTAMP WITH TIME ZONE NULL;
```

## Available Methods

### Basic Operations

| Method | Description |
|--------|-------------|
| `delete()` | Soft delete (sets `deleted_at`) |
| `forceDelete()` | Permanently remove from database |
| `restore()` | Restore soft deleted record |
| `trashed()` | Check if model is soft deleted |

### Query Scopes

| Scope | Description |
|-------|-------------|
| `withTrashed()` | Include soft deleted records |
| `onlyTrashed()` | Query only soft deleted records |
| `withoutTrashed()` | Exclude soft deleted records (default) |

## Usage Examples

### Soft Delete

```php
$event = Event::find($id);
$event->delete();
```

### Query Deleted Records

```php
// Include deleted
$all = Integration::withTrashed()->get();

// Only deleted
$deleted = Integration::onlyTrashed()->get();
```

### Restore

```php
$deleted = Integration::onlyTrashed()->find($id);
$deleted->restore();
```

### Force Delete

```php
$record = Integration::withTrashed()->find($id);
$record->forceDelete();
```

## Relationship Handling

All relationships use `withTrashed()` to preserve referential integrity:

```php
public function integration()
{
    return $this->belongsTo(Integration::class)->withTrashed();
}
```

## API Behavior

The Event API performs soft deletes in transactions:

```php
DB::transaction(function () use ($event) {
    $event->blocks()->delete();
    $event->delete();
    // Actor/target objects are preserved for other events
});
```

## Considerations

| Concern | Notes |
|---------|-------|
| Storage | Soft deleted records consume database space |
| Performance | Consider indexing `deleted_at` for large datasets |
| Cleanup | Implement periodic cleanup of old soft deleted records |
| Compliance | Helps meet data retention requirements |

## Benefits

- Data recovery capability
- Referential integrity maintained
- Audit trail of deleted records
- Safe operations reducing accidental data loss
