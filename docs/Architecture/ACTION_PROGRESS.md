# Long-Running Task Tracking System

A database-backed progress tracking system for long-running operations with real-time UI updates via Livewire polling.

## Overview

The Action Progress system tracks the progress of any long-running operation, providing users with real-time feedback through a consistent UI pattern. It replaces complex broadcasting solutions with a simple database-backed approach using Livewire polling.

## Architecture

### Core Components

| Component            | Location                        | Purpose                                                 |
| -------------------- | ------------------------------- | ------------------------------------------------------- |
| ActionProgress Model | `app/Models/ActionProgress.php` | Stores progress information and provides helper methods |
| Database Table       | `action_progress`               | Generic table supporting any type of action             |
| Livewire Polling     | UI Components                   | Real-time updates without WebSockets                    |

## Database Schema

```sql
CREATE TABLE action_progress (
    id BIGINT PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    action_type VARCHAR(255) NOT NULL,  -- e.g., 'deletion', 'migration', 'sync'
    action_id VARCHAR(255) NOT NULL,    -- e.g., group_id, migration_name
    step VARCHAR(255) NOT NULL,         -- Current step name
    message TEXT NOT NULL,              -- Human-readable progress message
    progress INTEGER DEFAULT 0,         -- Current progress (0-100)
    total INTEGER DEFAULT 100,          -- Total progress (usually 100)
    details JSON NULL,                  -- Additional metadata
    updates JSON NULL,                  -- Automatic tracking of progress changes
    completed_at TIMESTAMP NULL,
    failed_at TIMESTAMP NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

CREATE INDEX idx_action_progress_user_action ON action_progress(user_id, action_type, action_id);
CREATE INDEX idx_action_progress_user_created ON action_progress(user_id, created_at);
CREATE INDEX idx_action_progress_type_id ON action_progress(action_type, action_id);
```

## Usage

### Creating Progress Records

```php
use App\Models\ActionProgress;

$progress = ActionProgress::createProgress(
    userId: $userId,
    actionType: 'deletion',
    actionId: $groupId,
    step: 'starting',
    message: 'Starting deletion process...',
    progress: 0,
    total: 100,
    details: ['items_to_delete' => 150]
);
```

### Updating Progress

```php
$progress->updateProgress(
    step: 'deleting_events',
    message: 'Deleting events...',
    progress: 50,
    details: ['events_deleted' => 75, 'events_total' => 150]
);
```

### Completing Actions

```php
$progress->markCompleted([
    'final_counts' => [
        'events_deleted' => 150,
        'blocks_deleted' => 300
    ]
]);
```

### Handling Failures

```php
$progress->markFailed(
    errorMessage: 'Database connection lost',
    details: ['error_code' => 'DB_CONNECTION_LOST', 'retry_count' => 3]
);
```

## Job Integration

```php
class DeleteIntegrationGroupJob implements ShouldQueue
{
    public ?ActionProgress $progressRecord = null;

    public function handle(): void
    {
        $this->progressRecord = ActionProgress::createProgress(
            $this->userId,
            'deletion',
            $this->integrationGroupId,
            'starting',
            'Starting deletion process...'
        );

        try {
            $this->updateProgress('analyzing', 'Analyzing data...', 10);
            // ... do work ...
            $this->updateProgress('deleting_events', 'Deleting events...', 50);
            // ... do work ...

            $this->progressRecord->markCompleted([
                'deleted_counts' => $deletionSummary
            ]);

        } catch (\Exception $e) {
            $this->progressRecord->markFailed($e->getMessage());
            throw $e;
        }
    }

    private function updateProgress(string $step, string $message, int $progress, array $details = []): void
    {
        if ($this->progressRecord) {
            $this->progressRecord->updateProgress($step, $message, $progress, $details);
        }
    }
}
```

## Livewire Component Integration

```php
class DeleteIntegrationGroup extends Component
{
    public function checkProgress(): void
    {
        if (!$this->groupId || !$this->showProgress) {
            return;
        }

        $progress = ActionProgress::getLatestProgress(
            Auth::id(),
            'deletion',
            $this->groupId
        );

        if (!$progress) {
            $this->handleDeletionComplete();
            return;
        }

        $this->progressStep = $progress->step;
        $this->progressMessage = $progress->message;
        $this->progressPercentage = $progress->progress;
        $this->progressDetails = $progress->details ?? [];
        $this->progressHistory = $progress->updates ?? [];

        if ($progress->isCompleted()) {
            $this->handleDeletionComplete();
        } elseif ($progress->isFailed()) {
            $this->handleDeletionFailed($progress->error_message);
        }
    }
}
```

### Blade Template with Polling

```blade
<x-modal wire:model="showProgress" title="Processing..." class="modal-lg" :closable="false">
    <div class="space-y-6" wire:poll.2s="checkProgress">
        <div class="space-y-2">
            <div class="flex justify-between text-sm">
                <span class="font-medium">{{ $progressMessage }}</span>
                <span class="text-base-content/70">{{ $progressPercentage }}%</span>
            </div>
            <progress class="progress progress-primary w-full"
                     value="{{ $progressPercentage }}" max="100"></progress>
        </div>

        @if($progressDetails)
            <div class="card bg-base-200">
                <div class="card-body">
                    <h4 class="font-semibold mb-3">Progress Details:</h4>
                </div>
            </div>
        @endif
    </div>
</x-modal>
```

## Action Types

| Action Type | Action ID Example         | Steps                                                            | Use Case                    |
| ----------- | ------------------------- | ---------------------------------------------------------------- | --------------------------- |
| `deletion`  | Integration group ID      | starting, analyzing, deleting_blocks, deleting_events, completed | Data deletion operations    |
| `migration` | Migration name            | creating_table, adding_indexes, completed                        | Database migrations         |
| `sync`      | Integration ID            | fetching_data, processing_events, completed                      | Integration synchronization |
| `backup`    | `daily_backup_2024-01-15` | preparing, compressing, uploading, completed                     | Backup operations           |
| `export`    | `user_data_export_456`    | collecting_data, generating_file, completed                      | Data export                 |
| `import`    | `csv_import_789`          | validating, processing_rows, completed                           | Data import                 |

## Automatic Updates Tracking

The system automatically tracks all changes to `progress`, `step`, and `message` fields in the `updates` JSON column:

```php
$progress = ActionProgress::createProgress($userId, 'sync', $integrationId, 'starting', 'Starting...', 0);
$progress->updateProgress('fetching', 'Fetching data...', 25);
$progress->updateProgress('processing', 'Processing data...', 75);

$updateHistory = $progress->updates;
// Returns array of timestamped updates with step, message, and percentage
```

Tracking behavior:

- Automatic: No additional code needed
- Selective: Only tracks changes to progress, step, or message fields
- Timestamped: Each update includes an ISO 8601 timestamp
- Initial State: First update entry is created during record creation

## Best Practices

### Naming Conventions

| Element     | Convention             | Example                                      |
| ----------- | ---------------------- | -------------------------------------------- |
| Action Type | Lowercase, descriptive | `deletion`, `migration`, `sync`              |
| Action ID   | Meaningful identifier  | `integration_group_123`, `backup_2024_01_15` |
| Steps       | snake_case             | `starting`, `analyzing`, `cleaning_up`       |

### Error Handling

```php
try {
    $this->doWork();
    $progress->markCompleted();
} catch (\Exception $e) {
    $progress->markFailed($e->getMessage(), [
        'error_code' => $e->getCode(),
        'retry_count' => $this->retryCount
    ]);
    throw $e;
}
```

### Cleanup

```php
// Clean up old progress records (run daily)
ActionProgress::cleanupOldRecords();
```

### Testing

```php
$progress = ActionProgressFactory::new()
    ->deletion($userId, $groupId)
    ->inProgress()
    ->create();

$progress->markCompleted();
$this->assertTrue($progress->isCompleted());
```

## UI Patterns

### Polling Configuration

| Operation Speed   | Polling Interval |
| ----------------- | ---------------- |
| Fast operations   | `wire:poll.2s`   |
| Slower operations | `wire:poll.5s`   |

Stop polling when completed or failed and handle network errors gracefully.

## Performance Considerations

### Database Optimization

- Indexes on frequently queried columns
- Regular cleanup of old records
- Consider partitioning for high-volume systems

### Memory Management

- Clean up progress records after completion
- Use pagination for progress history
- Consider archiving old progress data
