# Block Creation Migration Guide

A guide for migrating from the deprecated `blocks()->create()` method to the new `createBlock()` method to prevent duplicate blocks.

## Overview

The old pattern `$event->blocks()->create()` creates duplicate blocks when the same plugin runs multiple times on the same event. This leads to data inconsistency, inflated metrics, and database bloat. The `createBlock()` method uses upsert logic to prevent duplicates automatically.

## Recommended Pattern

**Always use the `createBlock()` method:**

```php
// CORRECT - Prevents duplicates
$event->createBlock([
    'title' => 'Heart Rate',
    'block_type' => 'biometric',
    'value' => 72,
    'value_unit' => 'bpm',
]);
```

**Never use the old pattern:**

```php
// DEPRECATED - Creates duplicates
$event->blocks()->create([
    'title' => 'Heart Rate',
    'block_type' => 'biometric',
    'value' => 72,
    'value_unit' => 'bpm',
]);
```

## Migration Steps

### Step 1: Find All Occurrences

Search your codebase for `blocks()->create()`:

```bash
grep -r "blocks()->create" app/
```

### Step 2: Update Each Instance

**Before:**

```php
$this->event->blocks()->create([
    'title' => 'Workout Duration',
    'value' => 45,
    'value_unit' => 'minutes',
]);
```

**After:**

```php
$this->event->createBlock([
    'title' => 'Workout Duration',
    'block_type' => 'duration',
    'value' => 45,
    'value_unit' => 'minutes',
]);
```

### Step 3: Add Block Types

The new method works best with meaningful `block_type` values:

| Block Type | Use Case |
|------------|----------|
| `heart_rate` | Heart rate data |
| `calories` | Calorie data |
| `distance` | Distance data |
| `duration` | Time-based data |
| `weight` | Weight measurements |
| `sleep` | Sleep data |
| `activity` | Activity summaries |
| `biometric` | General health metrics |
| `financial` | Money/transaction data |
| `social` | Social platform data |

### Step 4: Test Thoroughly

After migration, verify:

1. **No Duplicates**: Run the same plugin multiple times and verify only one block is created
2. **Updates Work**: Ensure existing blocks are updated when data changes
3. **All Data Present**: Verify all expected blocks are still created

```php
// Example test
$event = Event::factory()->create();

// Run plugin twice
$plugin = new YourPlugin($event);
$plugin->handle();
$plugin->handle();

// Should only have one block per title + block_type
$this->assertEquals(1, $event->blocks()->where('title', 'Heart Rate')->count());
```

## How createBlock() Works

The `createBlock()` method uses upsert logic:

1. **Uniqueness Key**: `event_id` + `title` + `block_type`
2. **If exists**: Updates the existing block
3. **If new**: Creates a new block
4. **Database constraint**: Prevents duplicates at DB level

```php
// This will create OR update - never duplicate
$event->createBlock([
    'title' => 'Steps',           // Part of uniqueness key
    'block_type' => 'activity',   // Part of uniqueness key
    'value' => 10000,             // Will be updated if block exists
]);
```

## Detection and Warnings

### Deprecation Warning

```php
// This will log a warning but still work
$event->blocks()->create([...]);
// Warning: "Deprecated: Use createBlock() instead of blocks()->create()"
```

### PHPStan Rule

```php
// PHPStan will flag this in static analysis
$event->blocks()->create([...]); // Error: Use createBlock() instead
```

## Migration Checklist

- [ ] Search for all `blocks()->create()` occurrences
- [ ] Replace with `createBlock()` calls
- [ ] Add meaningful `block_type` values
- [ ] Test for duplicate prevention
- [ ] Test data integrity
- [ ] Remove any custom duplicate-prevention logic
- [ ] Update plugin documentation
- [ ] Run static analysis (PHPStan)

## Advanced Usage

### Multiple Blocks with Same Title

Use different `block_type` values to distinguish blocks:

```php
// These won't conflict - different block_type
$event->createBlock([
    'title' => 'Heart Rate',
    'block_type' => 'resting',
    'value' => 60,
]);

$event->createBlock([
    'title' => 'Heart Rate',
    'block_type' => 'max',
    'value' => 180,
]);
```

### Batch Creation

```php
private function createMultipleBlocks(array $blocksData): void
{
    foreach ($blocksData as $blockData) {
        $this->event->createBlock($blockData);
    }
}
```

### Error Handling

```php
try {
    $this->event->createBlock($blockData);
} catch (\Exception $e) {
    logger()->error('Failed to create block', [
        'event_id' => $this->event->id,
        'block_data' => $blockData,
        'error' => $e->getMessage(),
    ]);
}
```

## Common Patterns

### Health/Fitness Data

```php
$this->event->createBlock([
    'title' => 'Average Heart Rate',
    'block_type' => 'heart_rate',
    'value' => $heartRate,
    'value_unit' => 'bpm',
]);
```

### Financial Data

```php
$this->event->createBlock([
    'title' => 'Transaction Amount',
    'block_type' => 'financial',
    'value' => $amount,
    'value_unit' => 'GBP',
]);
```

### Activity Data

```php
$this->event->createBlock([
    'title' => 'Workout Duration',
    'block_type' => 'duration',
    'value' => $minutes,
    'value_unit' => 'minutes',
]);
```

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Blocks not being created | Check that `title` is provided - it is required for `createBlock()` |
| Still getting duplicates | Ensure you use the exact same `title` and `block_type` values; verify database constraint exists |
| Old blocks being updated unexpectedly | Use different `block_type` values to distinguish different block purposes |
| PHPStan still flagging code | Replace ALL instances of `blocks()->create()` with `createBlock()` |

## Related Documentation

- [Plugin Template](./PLUGIN_TEMPLATE.php) - Complete example plugin
- [Integration Plugin Documentation](../docs/INTEGRATION_PLUGINS.md) - Full plugin development guide
- [Block Model](../app/Models/Block.php) - Block model details
