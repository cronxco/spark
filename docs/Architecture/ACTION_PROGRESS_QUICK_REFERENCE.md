# Action Progress System - Quick Reference

A quick reference guide for tracking long-running operations using the ActionProgress model.

## Overview

The ActionProgress system provides real-time progress tracking for asynchronous operations like deletions, migrations, syncs, and exports. It integrates with Livewire for UI updates and supports automatic history tracking.

## Quick Start

### 1. Create Progress Record

```php
$progress = ActionProgress::createProgress(
    userId: $userId,
    actionType: 'deletion',  // or 'migration', 'sync', 'backup', etc.
    actionId: $entityId,     // unique identifier for this action
    step: 'starting',
    message: 'Starting operation...',
    progress: 0
);
```

### 2. Update Progress

```php
$progress->updateProgress(
    step: 'processing',
    message: 'Processing items...',
    progress: 50,
    details: ['items_processed' => 25, 'items_total' => 50]
);

// Updates are automatically tracked in the 'updates' JSON column
// Each change to step, message, or progress is recorded with a timestamp
```

### 3. Complete Action

```php
$progress->markCompleted([
    'final_count' => 50,
    'duration' => '2m 30s'
]);
```

### 4. Handle Failure

```php
$progress->markFailed('Operation failed: ' . $error->getMessage(), [
    'error_code' => $error->getCode()
]);
```

## Livewire Integration

### Component Method

```php
public function checkProgress(): void
{
    $progress = ActionProgress::getLatestProgress(
        Auth::id(),
        'deletion',  // action_type
        $this->groupId  // action_id
    );

    if ($progress) {
        $this->progressStep = $progress->step;
        $this->progressMessage = $progress->message;
        $this->progressPercentage = $progress->progress;
        $this->progressDetails = $progress->details ?? [];
        $this->progressUpdates = $progress->updates ?? []; // Access update history

        if ($progress->isCompleted()) {
            $this->handleComplete();
        } elseif ($progress->isFailed()) {
            $this->handleFailed($progress->error_message);
        }
    }
}
```

### Blade Template

```blade
<div wire:poll.2s="checkProgress">
    <progress class="progress progress-primary w-full"
             value="{{ $progressPercentage }}" max="100"></progress>
    <p>{{ $progressMessage }}</p>
</div>
```

## Job Integration

```php
class MyLongRunningJob implements ShouldQueue
{
    public ?ActionProgress $progressRecord = null;

    public function handle(): void
    {
        // Create progress record
        $this->progressRecord = ActionProgress::createProgress(
            $this->userId,
            'my_action',
            $this->entityId,
            'starting',
            'Starting operation...'
        );

        try {
            $this->updateProgress('step1', 'Doing step 1...', 25);
            // ... do work ...

            $this->updateProgress('step2', 'Doing step 2...', 75);
            // ... do work ...

            $this->progressRecord->markCompleted();

        } catch (\Exception $e) {
            $this->progressRecord->markFailed($e->getMessage());
            throw $e;
        }
    }

    private function updateProgress(string $step, string $message, int $progress, array $details = []): void
    {
        $this->progressRecord?->updateProgress($step, $message, $progress, $details);
    }
}
```

## Common Action Types

| Action Type        | Description              | Example Action ID           |
| ------------------ | ------------------------ | --------------------------- |
| `deletion`         | Data deletion operations | `integration_group_123`     |
| `migration`        | Database migrations      | `add_users_table`           |
| `sync`             | Data synchronization     | `github_integration_456`    |
| `backup`           | Backup operations        | `daily_backup_2024_01_15`   |
| `export`           | Data export              | `user_data_export_789`      |
| `import`           | Data import              | `csv_import_batch_001`      |
| `bulk_operation`   | Bulk user operations     | `role_update_batch_002`     |
| `report`           | Report generation        | `monthly_analytics_2024_01` |
| `maintenance`      | System maintenance       | `cache_cleanup_2024_01_15`  |
| `integration_test` | API testing              | `github_api_test_003`       |

## Utility Methods

```php
// Get latest progress for an action
$progress = ActionProgress::getLatestProgress($userId, 'deletion', $groupId);

// Check status
$progress->isCompleted();  // bool
$progress->isFailed();      // bool
$progress->isInProgress();  // bool

// Access update history (automatically tracked)
$updates = $progress->updates; // Array of all progress updates
// Each update contains: timestamp, step, message, percentage

// Clean up old records (run daily)
ActionProgress::cleanupOldRecords();
```

## Testing

```php
// Create test progress record
$progress = ActionProgressFactory::new()
    ->deletion($userId, $groupId)
    ->inProgress()
    ->create();

// Create progress with multiple update entries for testing
$progress = ActionProgressFactory::new()
    ->withMultipleUpdates(5) // Creates 5 update entries
    ->create();

// Test completion
$progress->markCompleted();
$this->assertTrue($progress->isCompleted());

// Test failure
$progress->markFailed('Test error');
$this->assertTrue($progress->isFailed());
```

## Best Practices

1. Always create progress record at job start
2. Use descriptive step names and messages
3. Include relevant details in progress updates
4. Handle both success and failure cases
5. Clean up old progress records regularly
6. Use consistent action_type naming
7. Stop polling when operation completes
8. Test both success and failure scenarios
