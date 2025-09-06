# Integration & Task Updates Interface

The Integration Updates interface provides a centralized way to monitor and manage integration data updates.

## Features

### 1. View Status

- **Up to Date**: Integration has been successfully updated within its frequency window
- **Needs Update**: Integration is due for an update based on its frequency settings
- **Processing**: Integration is currently being updated (job is running)
- **Paused**: Instance is paused and won't run

### 2. Manual Update Triggering

- Click "Update Now" to manually trigger an update for any integration
- Updates are processed as background jobs with retry logic
- Real-time status updates with polling every 5 seconds

### 3. Update Monitoring

- Shows last successful update time
- Shows next scheduled update time (frequency or schedule override)
- Displays update frequency or schedule summary (times + timezone)
- Indicates if an integration is currently processing
- Filter by All / Integrations / Tasks, and run job for task instances

## How It Works

### Job Processing

1. When an update is triggered, a `ProcessIntegrationData` job is dispatched to the queue
2. The job marks the integration as `triggered` before processing
3. The job calls the integration plugin's `fetchData` method
4. On success, the integration is marked as `successfully updated`
5. Failed jobs are retried up to 3 times with increasing delays

### Status Detection

- **Processing**: `last_triggered_at` is more recent than `last_successful_update_at`
- **Needs Update**: Based on `update_frequency_minutes` and `last_successful_update_at`
- **Up to Date**: Not processing and not due for update

### Real-time Updates

- The interface polls every 5 seconds to refresh status
- Manual refresh button available
- Automatic status updates when jobs complete

## Navigation

The Updates interface is accessible via:

- **URL**: `/updates`
- **Navigation**: Sidebar menu item "Updates" with cloud-arrow-down icon
- **Route**: `updates.index`

## Integration with Existing System

- Uses existing `Integration` model and relationships
- Each instance can belong to an `IntegrationGroup` that owns auth (OAuth tokens / webhook secrets)
- Plugins read tokens via `$integration->group` when present
- Compatible with all OAuth and webhook integrations
- Follows existing Laravel Sail and Volt patterns

## Error Handling

- Failed updates are logged with full error details
- Jobs are retried automatically with exponential backoff
- User-friendly error messages for manual triggers
- Graceful handling of missing integrations or plugins

## Future Enhancements

Potential improvements could include:

- Real-time WebSocket updates instead of polling
- Detailed job progress tracking
- Update history and logs
- Bulk update operations
- Integration health metrics
