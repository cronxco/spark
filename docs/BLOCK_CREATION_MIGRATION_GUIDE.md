# Block Creation Migration Guide

This guide helps you migrate from the deprecated `blocks()->create()` method to the new `createBlock()` method to prevent duplicate blocks.

## 🚨 Critical Issue: Duplicate Blocks

The old pattern `$event->blocks()->create()` creates duplicate blocks when the same plugin runs multiple times on the same event. This leads to:

- Data inconsistency
- Inflated metrics
- Poor user experience
- Database bloat

## ✅ New Recommended Pattern

**Always use the `createBlock()` method:**

```php
// ✅ CORRECT - Prevents duplicates
$event->createBlock([
    'title' => 'Heart Rate',
    'block_type' => 'biometric',
    'value' => 72,
    'value_unit' => 'bpm',
]);
```

**Never use the old pattern:**

```php
// ❌ DEPRECATED - Creates duplicates!
$event->blocks()->create([
    'title' => 'Heart Rate',
    'block_type' => 'biometric',
    'value' => 72,
    'value_unit' => 'bpm',
]);
```

## 🔄 Migration Steps

### Step 1: Find All Occurrences

Search your codebase for `blocks()->create()`:

```bash
grep -r "blocks()->create" app/
```

### Step 2: Update Each Instance

Replace each occurrence following these examples:

#### Basic Block Creation

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
    'block_type' => 'duration', // Add block_type for better categorization
    'value' => 45,
    'value_unit' => 'minutes',
]);
```

#### Conditional Block Creation

**Before:**

```php
if ($heartRate > 0) {
    $this->event->blocks()->create([
        'title' => 'Average Heart Rate',
        'value' => $heartRate,
        'value_unit' => 'bpm',
    ]);
}
```

**After:**

```php
if ($heartRate > 0) {
    $this->event->createBlock([
        'title' => 'Average Heart Rate',
        'block_type' => 'heart_rate',
        'value' => $heartRate,
        'value_unit' => 'bpm',
    ]);
}
```

#### Loop-based Block Creation

**Before:**

```php
foreach ($metrics as $metric) {
    $this->event->blocks()->create([
        'title' => $metric['name'],
        'value' => $metric['value'],
        'metadata' => $metric['details'],
    ]);
}
```

**After:**

```php
foreach ($metrics as $metric) {
    $this->event->createBlock([
        'title' => $metric['name'],
        'block_type' => 'metric',
        'value' => $metric['value'],
        'metadata' => $metric['details'],
    ]);
}
```

### Step 3: Add Block Types

The new method works best with meaningful `block_type` values for categorization:

```php
// Good block_type examples
'block_type' => 'heart_rate'     // For heart rate data
'block_type' => 'calories'       // For calorie data
'block_type' => 'distance'       // For distance data
'block_type' => 'duration'       // For time-based data
'block_type' => 'weight'         // For weight measurements
'block_type' => 'sleep'          // For sleep data
'block_type' => 'activity'       // For activity summaries
'block_type' => 'biometric'      // For general health metrics
'block_type' => 'financial'      // For money/transaction data
'block_type' => 'social'         // For social platform data
```

### Step 4: Test Thoroughly

After migration, test that:

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

## 🔍 How `createBlock()` Works

The `createBlock()` method uses "upsert" logic:

1. **Uniqueness Key**: `event_id` + `title` + `block_type`
2. **If exists**: Updates the existing block
3. **If new**: Creates a new block
4. **Database constraint**: Prevents duplicates at DB level

```php
// This will create OR update - never duplicate
$event->createBlock([
    'title' => 'Steps',           // Part of uniqueness key
    'block_type' => 'activity',   // Part of uniqueness key
    'value' => 10000,            // Will be updated if block exists
]);
```

## 🚨 Detection and Warnings

We've added several safeguards to catch old usage:

### 1. Deprecation Warning

```php
// This will log a warning but still work
$event->blocks()->create([...]);
// Warning: "Deprecated: Use createBlock() instead of blocks()->create()"
```

### 2. PHPStan Rule

```php
// PHPStan will flag this in static analysis
$event->blocks()->create([...]); // Error: Use createBlock() instead
```

### 3. IDE Support

Your IDE should show warnings and suggest the correct method.

## 📋 Migration Checklist

- [ ] Search for all `blocks()->create()` occurrences
- [ ] Replace with `createBlock()` calls
- [ ] Add meaningful `block_type` values
- [ ] Test for duplicate prevention
- [ ] Test data integrity
- [ ] Remove any custom duplicate-prevention logic
- [ ] Update plugin documentation
- [ ] Run static analysis (PHPStan)

## 🔧 Advanced Usage

### Multiple Blocks with Same Title

If you need multiple blocks with the same title, use different `block_type` values:

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

For creating many blocks efficiently:

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

## 📖 Common Patterns

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

## 🆘 Troubleshooting

### Q: My blocks aren't being created

**A:** Check that `title` is provided - it's required for `createBlock()`.

### Q: I'm still getting duplicates

**A:** Ensure you're using the exact same `title` and `block_type` values. Check database constraint exists.

### Q: Old blocks are being updated when I don't want them to be

**A:** Use different `block_type` values to distinguish different block purposes.

### Q: PHPStan is still flagging my code

**A:** Make sure you've replaced ALL instances of `blocks()->create()` with `createBlock()`.

## 📚 Further Reading

- [Plugin Template](./PLUGIN_TEMPLATE.php) - Complete example plugin
- [Integration Plugin Documentation](../docs/INTEGRATION_PLUGINS.md) - Full plugin development guide
- [Block Model Documentation](../app/Models/Block.php) - Block model details

## ❓ Need Help?

If you encounter issues during migration:

1. Check the deprecation warnings in logs
2. Run PHPStan analysis: `./vendor/bin/phpstan analyse`
3. Review the plugin template for examples
4. Test with duplicate data to ensure prevention works

---

**Remember**: The goal is consistent, duplicate-free block creation across all integrations. The `createBlock()` method handles this automatically! ✅
