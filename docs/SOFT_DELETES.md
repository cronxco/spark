# Soft Deletes Implementation

Soft deletes have been successfully implemented across all models in the application. This allows records to be "deleted" without actually removing them from the database, making data recovery possible and maintaining referential integrity.

## Implementation Status

✅ **Migrations Updated**: All model migrations now include `deleted_at` columns  
✅ **Models Updated**: All models now use the `SoftDeletes` trait  
✅ **Relationships Updated**: All relationships handle soft deleted models properly  
✅ **Controllers Updated**: Delete operations now perform soft deletes  
✅ **Functionality Verified**: Soft delete operations tested and confirmed working  

## Models with Soft Deletes

The following models now support soft deletes:

1. **Integration** - User integrations with external services
2. **EventObject** - Objects that participate in events (actors/targets)
3. **Event** - Events that occur in the system
4. **Block** - Content blocks associated with events

## Database Schema Changes

All model tables now include a `deleted_at` column:

```sql
-- Example for events table
ALTER TABLE events ADD COLUMN deleted_at TIMESTAMP WITH TIME ZONE NULL;
```

The `deleted_at` column is:
- `TIMESTAMP WITH TIME ZONE` (PostgreSQL)
- `NULL` by default
- Set to the current timestamp when a record is soft deleted

## Model Updates

### Integration Model
```php
use Illuminate\Database\Eloquent\SoftDeletes;

class Integration extends Model
{
    use HasFactory, SoftDeletes;
    
    protected $casts = [
        'deleted_at' => 'datetime',
        // ... other casts
    ];
}
```

### EventObject Model
```php
use Illuminate\Database\Eloquent\SoftDeletes;

class EventObject extends Model
{
    use HasFactory, HasTags, SoftDeletes;
    
    protected $casts = [
        'deleted_at' => 'datetime',
        // ... other casts
    ];
}
```

### Event Model
```php
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasFactory, HasTags, SoftDeletes;
    
    protected $casts = [
        'deleted_at' => 'datetime',
        // ... other casts
    ];
}
```

### Block Model
```php
use Illuminate\Database\Eloquent\SoftDeletes;

class Block extends Model
{
    use HasFactory, SoftDeletes;
    
    protected $casts = [
        'deleted_at' => 'datetime',
        // ... other casts
    ];
}
```

## Relationship Updates

All relationships have been updated to handle soft deleted models using `withTrashed()`:

### Event Relationships
```php
public function integration()
{
    return $this->belongsTo(Integration::class)->withTrashed();
}

public function actor()
{
    return $this->belongsTo(EventObject::class, 'actor_id')->withTrashed();
}

public function target()
{
    return $this->belongsTo(EventObject::class, 'target_id')->withTrashed();
}

public function blocks()
{
    return $this->hasMany(Block::class)->withTrashed();
}
```

### EventObject Relationships
```php
public function integration()
{
    return $this->belongsTo(Integration::class)->withTrashed();
}
```

### Block Relationships
```php
public function event()
{
    return $this->belongsTo(Event::class)->withTrashed();
}

public function integration()
{
    return $this->belongsTo(Integration::class)->withTrashed();
}
```

## Available Methods

All models now have access to these soft delete methods:

### Basic Soft Delete Operations
- `delete()` - Soft delete a model (sets `deleted_at` timestamp)
- `forceDelete()` - Permanently delete a model from the database
- `restore()` - Restore a soft deleted model (sets `deleted_at` to null)

### Query Scopes
- `withTrashed()` - Include soft deleted records in queries
- `onlyTrashed()` - Query only soft deleted records
- `withoutTrashed()` - Exclude soft deleted records (default behavior)

### Helper Methods
- `trashed()` - Check if a model is soft deleted
- `isForceDeleting()` - Check if a model is being force deleted

## Usage Examples

### Soft Deleting Records
```php
// Soft delete an integration
$integration = Integration::find($id);
$integration->delete(); // Sets deleted_at timestamp

// Soft delete an event
$event = Event::find($id);
$event->delete(); // Sets deleted_at timestamp
```

### Querying Soft Deleted Records
```php
// Find only active records (default behavior)
$activeIntegrations = Integration::all();

// Include soft deleted records
$allIntegrations = Integration::withTrashed()->get();

// Find only soft deleted records
$deletedIntegrations = Integration::onlyTrashed()->get();
```

### Restoring Soft Deleted Records
```php
// Restore a soft deleted integration
$deletedIntegration = Integration::onlyTrashed()->find($id);
$deletedIntegration->restore();

// Or restore multiple records
Integration::onlyTrashed()->restore();
```

### Force Deleting Records
```php
// Permanently delete a record
$integration = Integration::withTrashed()->find($id);
$integration->forceDelete(); // Removes from database permanently
```

### Checking Soft Delete Status
```php
$integration = Integration::withTrashed()->find($id);

if ($integration->trashed()) {
    echo "This integration has been soft deleted";
}

if ($integration->deleted_at) {
    echo "Deleted at: " . $integration->deleted_at;
}
```

## API Integration

The Event API controller has been updated to perform soft deletes:

```php
// In EventApiController::destroy()
DB::transaction(function () use ($event) {
    // Soft delete associated blocks
    $event->blocks()->delete();
    
    // Soft delete the event
    $event->delete();
    
    // Note: We don't delete actor/target objects as they might be used by other events
});
```

## Testing

The soft delete functionality has been tested and verified working. You can test it manually using Laravel Tinker:

```bash
./vendor/bin/sail artisan tinker
```

```php
// Test basic soft delete functionality
$user = App\Models\User::factory()->create();
$integration = App\Models\Integration::factory()->create(['user_id' => $user->id]);
$actor = App\Models\EventObject::factory()->create(['integration_id' => $integration->id]);
$target = App\Models\EventObject::factory()->create(['integration_id' => $integration->id]);
$event = App\Models\Event::factory()->create([
    'integration_id' => $integration->id,
    'actor_id' => $actor->id,
    'target_id' => $target->id,
]);

echo 'Event created with ID: ' . $event->id;
$event->delete();
echo 'Event soft deleted';

$deletedEvent = App\Models\Event::withTrashed()->find($event->id);
echo 'Found deleted event: ' . ($deletedEvent ? 'Yes' : 'No');
echo 'Deleted at: ' . $deletedEvent->deleted_at;
```

## Benefits

1. **Data Recovery**: Soft deleted records can be restored if needed
2. **Referential Integrity**: Foreign key relationships remain intact
3. **Audit Trail**: Maintains history of deleted records
4. **Safe Operations**: Reduces risk of accidental data loss
5. **Compliance**: Helps meet data retention requirements

## Considerations

1. **Storage**: Soft deleted records still consume database space
2. **Performance**: Queries may need to be optimized for large datasets
3. **Cleanup**: Consider implementing a cleanup strategy for old soft deleted records
4. **Indexing**: Consider adding indexes on `deleted_at` columns for better performance

## Next Steps

1. **Cleanup Strategy**: Implement automated cleanup of old soft deleted records
2. **UI Integration**: Add restore/force delete options to the frontend
3. **Bulk Operations**: Add bulk restore/force delete functionality
4. **Audit Logging**: Track who performed soft delete operations
5. **Data Retention Policy**: Define how long soft deleted records should be kept
