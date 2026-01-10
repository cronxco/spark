# Integration and Task Updates Interface

A centralized interface for monitoring and managing integration data updates with real-time status tracking.

## Overview

The Updates interface provides visibility into the state of all integrations and tasks, allowing users to monitor update schedules, trigger manual updates, and track processing status. It uses Livewire polling for real-time feedback without WebSocket complexity.

## Status States

| Status       | Description                                      |
| ------------ | ------------------------------------------------ |
| Up to Date   | Successfully updated within its frequency window |
| Needs Update | Due for an update based on frequency settings    |
| Processing   | Currently being updated (job is running)         |
| Paused       | Instance is paused and will not run              |

## Features

### Manual Update Triggering

- Click "Update Now" to trigger an update for any integration
- Updates are processed as background jobs with retry logic
- Real-time status updates with polling every 5 seconds

### Update Monitoring

- Last successful update time
- Next scheduled update time (frequency or schedule override)
- Update frequency or schedule summary (times + timezone)
- Current processing indicator
- Filter by All / Integrations / Tasks

## How It Works

### Job Processing

1. When an update is triggered, a `ProcessIntegrationData` job is dispatched to the queue
2. The job marks the integration as `triggered` before processing
3. The job calls the integration plugin's `fetchData` method
4. On success, the integration is marked as `successfully updated`
5. Failed jobs are retried up to 3 times with increasing delays

### Status Detection

| Status       | Detection Logic                                                     |
| ------------ | ------------------------------------------------------------------- |
| Processing   | `last_triggered_at` is more recent than `last_successful_update_at` |
| Needs Update | Based on `update_frequency_minutes` and `last_successful_update_at` |
| Up to Date   | Not processing and not due for update                               |

### Real-time Updates

- Interface polls every 5 seconds to refresh status
- Manual refresh button available
- Automatic status updates when jobs complete

## Navigation

| Access Method | Value                                |
| ------------- | ------------------------------------ |
| URL           | `/updates`                           |
| Sidebar       | "Updates" with cloud-arrow-down icon |
| Route Name    | `updates.index`                      |

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
