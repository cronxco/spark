# Long-Running Task Tracking System

This document describes the generic action progress tracking system implemented in Spark, which provides real-time progress monitoring for long-running tasks using Livewire polling.

## Overview

The Action Progress system allows you to track the progress of any long-running operation in your application, providing users with real-time feedback through a consistent UI pattern. It replaces the need for complex broadcasting solutions with a simple database-backed approach using Livewire polling.

## Architecture

### Core Components

1. **ActionProgress Model** (`app/Models/ActionProgress.php`)
    - Stores progress information in the database
    - Provides helper methods for common operations
    - Handles completion and failure states

2. **Database Table** (`action_progress`)
    - Generic table supporting any type of action
    - Flexible `action_type` and `action_id` columns
    - Tracks progress, status, and metadata

3. **Livewire Polling**
    - Real-time updates without WebSockets
    - Consistent with existing application patterns
    - Automatic cleanup and error handling

## Database Schema

```sql
CREATE TABLE action_progress (
    id BIGINT PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    action_type VARCHAR(255) NOT NULL,  -- e.g., 'deletion', 'migration', 'sync'
    action_id VARCHAR(255) NOT NULL,    -- e.g., group_id, migration_name, integration_id
    step VARCHAR(255) NOT NULL,         -- Current step name
    message TEXT NOT NULL,              -- Human-readable progress message
    progress INTEGER DEFAULT 0,        -- Current progress (0-100)
    total INTEGER DEFAULT 100,          -- Total progress (usually 100)
    details JSON NULL,                  -- Additional metadata
    updates JSON NULL,                  -- Automatic tracking of progress changes
    completed_at TIMESTAMP NULL,        -- When action completed
    failed_at TIMESTAMP NULL,           -- When action failed
    error_message TEXT NULL,            -- Error details if failed
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- Indexes for performance
CREATE INDEX idx_action_progress_user_action ON action_progress(user_id, action_type, action_id);
CREATE INDEX idx_action_progress_user_created ON action_progress(user_id, created_at);
CREATE INDEX idx_action_progress_type_id ON action_progress(action_type, action_id);
```

## Usage Guide

### Basic Usage

#### 1. Creating Progress Records

```php
use App\Models\ActionProgress;

// Create initial progress record
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

#### 2. Updating Progress

```php
// Update progress during execution
$progress->updateProgress(
    step: 'deleting_events',
    message: 'Deleting events...',
    progress: 50,
    details: ['events_deleted' => 75, 'events_total' => 150]
);

// The 'updates' column automatically tracks all changes to step, message, or progress
// Each update is stored with a timestamp for complete history
```

#### 3. Completing Actions

```php
// Mark as completed
$progress->markCompleted([
    'final_counts' => [
        'events_deleted' => 150,
        'blocks_deleted' => 300,
        'objects_deleted' => 50
    ]
]);
```

#### 4. Handling Failures

```php
// Mark as failed
$progress->markFailed(
    errorMessage: 'Database connection lost',
    details: ['error_code' => 'DB_CONNECTION_LOST', 'retry_count' => 3]
);
```

### Job Integration

#### Example: DeleteIntegrationGroupJob

```php
class DeleteIntegrationGroupJob implements ShouldQueue
{
    public ?ActionProgress $progressRecord = null;

    public function handle(): void
    {
        // Create progress record
        $this->progressRecord = ActionProgress::createProgress(
            $this->userId,
            'deletion',
            $this->integrationGroupId,
            'starting',
            'Starting deletion process...'
        );

        try {
            // Perform work with progress updates
            $this->updateProgress('analyzing', 'Analyzing data...', 10);
            // ... do work ...
            $this->updateProgress('deleting_events', 'Deleting events...', 50);
            // ... do work ...

            // Mark completed
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

### Livewire Component Integration

#### Example: Progress Modal

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

        // Update UI properties
        $this->progressStep = $progress->step;
        $this->progressMessage = $progress->message;
        $this->progressPercentage = $progress->progress;
        $this->progressDetails = $progress->details ?? [];
        $this->progressHistory = $progress->updates ?? []; // Complete update history

        if ($progress->isCompleted()) {
            $this->handleDeletionComplete();
        } elseif ($progress->isFailed()) {
            $this->handleDeletionFailed($progress->error_message);
        }
    }
}
```

#### Blade Template with Polling

```blade
<!-- Progress Modal -->
<x-modal wire:model="showProgress" title="Processing..." class="modal-lg" :closable="false">
    <div class="space-y-6" wire:poll.2s="checkProgress">
        <!-- Progress Bar -->
        <div class="space-y-2">
            <div class="flex justify-between text-sm">
                <span class="font-medium">{{ $progressMessage }}</span>
                <span class="text-base-content/70">{{ $progressPercentage }}%</span>
            </div>
            <progress class="progress progress-primary w-full"
                     value="{{ $progressPercentage }}" max="100"></progress>
        </div>

        <!-- Step Details -->
        @if($progressDetails)
            <div class="card bg-base-200">
                <div class="card-body">
                    <h4 class="font-semibold mb-3">Progress Details:</h4>
                    <!-- Display details based on action type -->
                </div>
            </div>
        @endif

        <!-- Progress History -->
        @if($progressHistory && count($progressHistory) > 1)
            <div class="card bg-base-200">
                <div class="card-body">
                    <h4 class="font-semibold mb-3">Progress History:</h4>
                    <div class="space-y-2">
                        @foreach($progressHistory as $update)
                            <div class="flex justify-between text-sm">
                                <span>{{ $update['step'] }}: {{ $update['message'] }}</span>
                                <span class="text-base-content/70">{{ $update['percentage'] }}%</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-modal>
```

## Action Types and Use Cases

### Current Implementation

#### 1. Deletion Actions

- **Action Type**: `deletion`
- **Action ID**: Integration group ID, user ID, etc.
- **Steps**: `starting`, `analyzing`, `deleting_blocks`, `deleting_events`, `completed`
- **Use Case**: Track progress of data deletion operations

### Future Use Cases

#### 2. Database Migrations

```php
// Track long-running migrations
ActionProgress::createProgress(
    $userId,
    'migration',
    'add_user_preferences_table',
    'creating_table',
    'Creating user preferences table...',
    25
);

// Update progress
$progress->updateProgress(
    'adding_indexes',
    'Adding database indexes...',
    75
);
```

#### 3. Data Synchronization

```php
// Track integration sync progress
ActionProgress::createProgress(
    $userId,
    'sync',
    $integrationId,
    'fetching_data',
    'Fetching data from external API...',
    10,
    100,
    ['api_endpoint' => 'https://api.example.com/data']
);

// Update with sync details
$progress->updateProgress(
    'processing_events',
    'Processing fetched events...',
    60,
    ['events_processed' => 150, 'events_total' => 250]
);
```

#### 4. Backup Operations

```php
// Track backup progress
ActionProgress::createProgress(
    $userId,
    'backup',
    'daily_backup_' . now()->format('Y-m-d'),
    'preparing',
    'Preparing backup files...',
    5
);

// Update backup progress
$progress->updateProgress(
    'compressing',
    'Compressing backup files...',
    45,
    ['files_processed' => 450, 'total_size' => '2.3GB']
);
```

#### 5. Data Export

```php
// Track export progress
ActionProgress::createProgress(
    $userId,
    'export',
    'user_data_export_' . $userId,
    'collecting_data',
    'Collecting user data...',
    20
);

// Update export progress
$progress->updateProgress(
    'generating_file',
    'Generating export file...',
    80,
    ['format' => 'CSV', 'estimated_size' => '15MB']
);
```

#### 6. Data Import

```php
// Track import progress
ActionProgress::createProgress(
    $userId,
    'import',
    'csv_import_' . $importId,
    'validating',
    'Validating import file...',
    10
);

// Update import progress
$progress->updateProgress(
    'processing_rows',
    'Processing import rows...',
    65,
    ['rows_processed' => 1300, 'rows_total' => 2000]
);
```

#### 7. Bulk Operations

```php
// Track bulk user operations
ActionProgress::createProgress(
    $adminUserId,
    'bulk_operation',
    'user_role_update_' . $batchId,
    'preparing',
    'Preparing user role updates...',
    5
);

// Update bulk operation progress
$progress->updateProgress(
    'updating_users',
    'Updating user roles...',
    70,
    ['users_updated' => 140, 'users_total' => 200]
);
```

#### 8. Report Generation

```php
// Track report generation
ActionProgress::createProgress(
    $userId,
    'report',
    'monthly_analytics_' . now()->format('Y-m'),
    'collecting_data',
    'Collecting analytics data...',
    30
);

// Update report progress
$progress->updateProgress(
    'generating_charts',
    'Generating charts and visualizations...',
    85,
    ['charts_generated' => 12, 'total_charts' => 15]
);
```

#### 9. System Maintenance

```php
// Track system maintenance tasks
ActionProgress::createProgress(
    $systemUserId,
    'maintenance',
    'cache_cleanup_' . now()->format('Y-m-d-H'),
    'analyzing',
    'Analyzing cache usage...',
    15
);

// Update maintenance progress
$progress->updateProgress(
    'cleaning_cache',
    'Cleaning expired cache entries...',
    60,
    ['entries_cleaned' => 5000, 'space_freed' => '250MB']
);
```

#### 10. Integration Testing

```php
// Track integration test runs
ActionProgress::createProgress(
    $userId,
    'integration_test',
    'github_api_test_' . $testId,
    'initializing',
    'Initializing integration test...',
    5
);

// Update test progress
$progress->updateProgress(
    'running_tests',
    'Running API integration tests...',
    75,
    ['tests_passed' => 15, 'tests_total' => 20]
);
```

## Updates Tracking

The Action Progress system automatically tracks all changes to the `progress`, `step`, and `message` fields in the `updates` JSON column. This provides a complete history of progress changes without any additional code.

### Automatic Tracking

```php
// Initial creation automatically creates the first update entry
$progress = ActionProgress::createProgress(
    $userId,
    'sync',
    $integrationId,
    'starting',
    'Starting synchronization...',
    0
);

// Each update automatically adds to the updates array
$progress->updateProgress('fetching', 'Fetching data...', 25);
$progress->updateProgress('processing', 'Processing data...', 75);
$progress->updateProgress('completed', 'Sync completed!', 100);

// Access complete history
$updateHistory = $progress->updates;
/*
[
    ['timestamp' => '2024-01-01T10:00:00Z', 'step' => 'starting', 'message' => 'Starting synchronization...', 'percentage' => 0],
    ['timestamp' => '2024-01-01T10:01:30Z', 'step' => 'fetching', 'message' => 'Fetching data...', 'percentage' => 25],
    ['timestamp' => '2024-01-01T10:03:15Z', 'step' => 'processing', 'message' => 'Processing data...', 'percentage' => 75],
    ['timestamp' => '2024-01-01T10:05:00Z', 'step' => 'completed', 'message' => 'Sync completed!', 'percentage' => 100]
]
*/
```

### Tracking Behavior

- **Automatic**: No additional code needed - tracking happens automatically
- **Selective**: Only tracks changes to `progress`, `step`, or `message` fields
- **Timestamped**: Each update includes an ISO 8601 timestamp
- **Non-intrusive**: Updates to other fields (like `details` or `updated_at`) don't create new entries
- **Initial State**: The first update entry is created during record creation

### Usage in UI

```php
class ProgressComponent extends Component
{
    public array $progressHistory = [];

    public function checkProgress(): void
    {
        $progress = ActionProgress::getLatestProgress(...);

        if ($progress) {
            $this->progressHistory = $progress->updates ?? [];
            // Display progress history, show step transitions, etc.
        }
    }
}
```

```blade
<!-- Show progress timeline -->
@if(count($progressHistory) > 1)
    <div class="timeline">
        @foreach($progressHistory as $update)
            <div class="timeline-item">
                <span class="timeline-time">{{ $update['timestamp'] }}</span>
                <span class="timeline-step">{{ $update['step'] }}</span>
                <span class="timeline-message">{{ $update['message'] }}</span>
                <span class="timeline-percentage">{{ $update['percentage'] }}%</span>
            </div>
        @endforeach
    </div>
@endif
```

### Testing Updates

```php
// Test automatic tracking
$progress = ActionProgress::createProgress($userId, 'test', 'test_id', 'start', 'Starting...', 0);
$this->assertCount(1, $progress->updates);

$progress->updateProgress('middle', 'Processing...', 50);
$progress->refresh();
$this->assertCount(2, $progress->updates);
$this->assertEquals('middle', $progress->updates[1]['step']);
$this->assertEquals(50, $progress->updates[1]['percentage']);

// Test that non-tracked field updates don't create entries
$progress->update(['details' => ['new' => 'data']]);
$progress->refresh();
$this->assertCount(2, $progress->updates); // Still only 2 entries
```

## Best Practices

### 1. Action Type Naming

- Use lowercase, descriptive names: `deletion`, `migration`, `sync`, `backup`
- Be consistent across your application
- Consider using namespaces for complex systems: `integration.sync`, `user.migration`

### 2. Action ID Format

- Use meaningful identifiers: `integration_group_123`, `migration_add_users_table`
- Include relevant context: `backup_2024_01_15`, `export_user_456_csv`
- Consider using UUIDs for complex operations

### 3. Progress Steps

- Use descriptive step names: `starting`, `analyzing`, `processing`, `cleaning_up`, `completed`
- Keep steps consistent within action types
- Use snake_case for step names

### 4. Error Handling

```php
try {
    // Perform operation
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

### 5. Cleanup

```php
// Clean up old progress records (run daily)
ActionProgress::cleanupOldRecords();
```

### 6. Testing

```php
// Use the factory for testing
$progress = ActionProgressFactory::new()
    ->deletion($userId, $groupId)
    ->inProgress()
    ->create();

// Test completion
$progress->markCompleted();
$this->assertTrue($progress->isCompleted());
```

## UI Patterns

### Progress Modal Components

- Consistent progress bar styling
- Step-by-step indicators with icons
- Detailed progress information
- Error handling with retry options
- Completion celebrations

### Polling Configuration

- Use `wire:poll.2s` for most operations
- Use `wire:poll.5s` for slower operations
- Stop polling when completed or failed
- Handle network errors gracefully

## Performance Considerations

### Database Optimization

- Indexes on frequently queried columns
- Regular cleanup of old records
- Consider partitioning for high-volume systems

### Polling Optimization

- Adjust polling frequency based on operation speed
- Stop polling when not needed
- Consider using Livewire's `wire:poll.keep-alive` for critical operations

### Memory Management

- Clean up progress records after completion
- Use pagination for progress history
- Consider archiving old progress data

## Monitoring and Analytics

### Progress Metrics

- Track average completion times by action type
- Monitor failure rates and common error patterns
- Analyze user engagement with progress modals

### System Health

- Monitor database table size
- Track polling frequency and performance
- Alert on stuck or failed operations

## Migration Guide

### From Broadcasting to Polling

1. Replace broadcast events with ActionProgress records
2. Update Livewire components to use polling
3. Remove broadcasting dependencies
4. Update tests to check database state

### From Job Properties to Database

1. Create ActionProgress records in job constructors
2. Update progress through database instead of job properties
3. Use `getLatestProgress()` for status checks
4. Handle completion/failure through database

This system provides a robust, scalable foundation for tracking long-running tasks while maintaining consistency with your existing Livewire-based architecture.
