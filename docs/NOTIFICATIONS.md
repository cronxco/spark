# Spark Notification System

This document describes the comprehensive notification system implemented in Spark, which provides real-time in-app notifications and email support with user-configurable preferences.

## Overview

The notification system extends Laravel's built-in notification framework with:

- **In-app notifications** displayed in the global notification indicator
- **Email notifications** with user preferences
- **Work hours and delayed sending** to prevent notification fatigue
- **Priority notifications** that always send immediately
- **User preferences** stored in the existing `users->settings` JSON column

## Architecture

### Database Schema

The `notifications` table follows Laravel's standard structure:

```sql
CREATE TABLE notifications (
    id UUID PRIMARY KEY,
    type VARCHAR(255),                    -- Notification class name
    notifiable_type VARCHAR(255),         -- User
    notifiable_id UUID,                   -- User ID
    data TEXT,                            -- JSON notification data
    read_at TIMESTAMP NULL,               -- When user read the notification
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX(notifiable_type, notifiable_id)
);
```

### User Preferences Structure

Notification preferences are stored in `users->settings->notifications`:

```php
'notifications' => [
    'email_enabled' => [
        'integration_completed' => true,
        'integration_failed' => true,                    // Always sends (priority)
        'integration_authentication_failed' => true,     // Always sends (priority)
        'migration_completed' => true,
        'migration_failed' => true,                      // Always sends (priority)
        'data_export_ready' => true,
        'system_maintenance' => false,                   // Always sends (priority)
    ],
    'work_hours' => [
        'enabled' => true,
        'timezone' => 'Europe/London',
        'start' => '09:00',
        'end' => '17:00',
    ],
    'delayed_sending' => [
        'mode' => 'immediate',             // 'immediate', 'work_hours', 'daily_digest'
        'digest_time' => '09:00',
    ],
]
```

## Core Components

### 1. SparkNotification Base Class

`app/Notifications/SparkNotification.php`

Abstract base class that all Spark notifications extend. Provides:

**Abstract Methods:**

```php
abstract public function getNotificationType(): string;  // e.g., 'integration_completed'
abstract public function getTitle(): string;             // UI display title
abstract public function getMessage(): string;           // UI display message
```

**Optional Override Methods:**

```php
public function isPriority(): bool;                     // Default: false
public function getIcon(): string;                      // Default: 'o-bell'
public function getColor(): string;                     // Default: 'primary'
public function getActionUrl(): ?string;                // Default: null
```

**Automatic Features:**

- Dynamic channel selection based on user preferences
- Work hours checking for delayed sending
- Priority notification handling
- Database storage with UI metadata

### 2. Notification Types

#### IntegrationCompleted

**File:** `app/Notifications/IntegrationCompleted.php`

Success notification when an integration sync completes.

```php
$user->notify(new IntegrationCompleted($integration, [
    'events_synced' => 150,
    'duration' => '2m 30s',
]));
```

**Properties:**

- Icon: `o-check-circle`
- Color: `success`
- Priority: No
- Email: Configurable

#### IntegrationFailed

**File:** `app/Notifications/IntegrationFailed.php`

Error notification when an integration sync fails permanently after all retries.

```php
$user->notify(new IntegrationFailed(
    $integration,
    'API rate limit exceeded',
    ['retry_after' => 3600]
));
```

**Properties:**

- Icon: `o-x-circle`
- Color: `error`
- Priority: **YES** (always sends immediately)
- Email: Always sent

**Automatically Sent From:**

- `app/Jobs/Base/BaseFetchJob.php:failed()` - when fetch jobs fail permanently after 3 retries

#### IntegrationAuthenticationFailed

**File:** `app/Notifications/IntegrationAuthenticationFailed.php`

Priority notification when OAuth token refresh fails and user needs to re-authorize.

```php
$user->notify(new IntegrationAuthenticationFailed(
    $integration,
    'Your connection has expired',
    ['error_code' => 'invalid_grant']
));
```

**Properties:**

- Icon: `o-shield-exclamation`
- Color: `error`
- Priority: **YES** (always sends immediately)
- Email: Always sent

**Automatically Sent From:**

- `app/Integrations/Base/OAuthPlugin.php:refreshToken()` - when token refresh returns 401/invalid_grant

#### MigrationCompleted

**File:** `app/Notifications/MigrationCompleted.php`

Success notification when historical data migration completes.

```php
$user->notify(new MigrationCompleted(
    $integration,
    [
        'events_imported' => 1500,
        'date_range' => 'Jan 2023 - Dec 2024',
        'duration' => '5m 30s'
    ]
));
```

**Properties:**

- Icon: `o-arrow-down-circle`
- Color: `success`
- Priority: No
- Email: Configurable

#### MigrationFailed

**File:** `app/Notifications/MigrationFailed.php`

Priority notification when historical data migration fails permanently.

```php
$user->notify(new MigrationFailed(
    $integration,
    'API authentication failed',
    ['attempted_date_range' => 'Jan 2023 - Dec 2024']
));
```

**Properties:**

- Icon: `o-exclamation-triangle`
- Color: `error`
- Priority: **YES** (always sends immediately)
- Email: Always sent

**Automatically Sent From:**

- `app/Jobs/Migrations/StartIntegrationMigration.php:failed()` - when migration jobs fail permanently

#### DataExportReady

**File:** `app/Notifications/DataExportReady.php`

Notification when a data export is ready for download.

```php
$user->notify(new DataExportReady(
    'User Data',
    route('exports.download', $exportId),
    ['size' => '15MB', 'format' => 'CSV']
));
```

**Properties:**

- Icon: `o-arrow-down-tray`
- Color: `info`
- Priority: No
- Email: Configurable

#### SystemMaintenance

**File:** `app/Notifications/SystemMaintenance.php`

System maintenance and update notifications.

```php
$user->notify(new SystemMaintenance(
    'Scheduled Maintenance',
    'The system will be unavailable from 2AM-4AM UTC',
    ['scheduled_at' => '2025-01-15 02:00:00 UTC']
));
```

**Properties:**

- Icon: `o-wrench-screwdriver`
- Color: `warning`
- Priority: **YES** (always sends immediately)
- Email: Always sent

### 3. UI Components

#### Global Notification Indicator

**File:** `resources/views/livewire/global-progress-indicator.blade.php`

Always-visible bell icon in the navbar that shows:

- **Active operations** with spinner and count
- **Unread notifications** with badge and count
- **Recently completed operations** with success/error indicators
- **Recent history** (last 5 minutes)
- **Empty state** when no notifications exist

**Features:**

- Auto-polling every 30s when notifications exist
- Mark as read/unread
- Delete notifications
- Action buttons linking to relevant pages
- Notification history

#### Notification Preferences Page

**File:** `resources/views/livewire/settings/notifications.blade.php`
**Route:** `/settings/notifications`

User-friendly settings interface with:

- **Email Notifications Section**
    - Per-notification-type toggles
    - Disabled toggles for priority notifications (with explanation)

- **Work Hours Section**
    - Enable/disable work hours
    - Timezone selection (11 major timezones)
    - Start/end time pickers

- **Email Delivery Timing Section**
    - Immediate: Send all emails right away
    - During Work Hours Only: Delay non-urgent emails until work hours
    - Daily Digest: Group notifications into one daily email

### 4. User Model Helper Methods

`app/Models/User.php` includes these helper methods:

```php
// Get all notification preferences
$preferences = $user->getNotificationPreferences();

// Check if email is enabled for a specific type
if ($user->hasEmailNotificationsEnabled('integration_completed')) {
    // ...
}

// Enable/disable email notifications
$user->enableEmailNotifications('data_export_ready');
$user->disableEmailNotifications('data_export_ready');

// Update multiple preferences at once
$user->updateNotificationPreferences([
    'email_enabled' => ['integration_completed' => false],
    'work_hours' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
]);

// Check if user is currently in work hours
if ($user->isInWorkHours()) {
    // ...
}

// Get delayed sending mode
$mode = $user->getDelayedSendingMode(); // 'immediate', 'work_hours', or 'daily_digest'

// Get daily digest time
$time = $user->getDigestTime(); // '09:00'
```

## Usage Guide

### Creating a New Notification Type

1. **Create Notification Class**

```php
<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class MyCustomNotification extends SparkNotification
{
    public function __construct(
        public string $title,
        public string $details,
    ) {}

    public function getNotificationType(): string
    {
        return 'my_custom_notification';
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getMessage(): string
    {
        return $this->details;
    }

    public function getIcon(): string
    {
        return 'o-sparkles';
    }

    public function getColor(): string
    {
        return 'accent';
    }

    // Optional: Make it a priority notification
    public function isPriority(): bool
    {
        return false;
    }

    // Optional: Add action URL
    public function getActionUrl(): ?string
    {
        return route('custom.show');
    }

    // Email template
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->greeting("Hello {$notifiable->name}!")
            ->line($this->details)
            ->action('View Details', $this->getActionUrl());
    }
}
```

2. **Add to Settings Page**

Edit `resources/views/livewire/settings/notifications.blade.php`:

```php
public array $notificationTypes = [
    // ... existing types ...
    'my_custom_notification' => [
        'label' => 'Custom Notifications',
        'description' => 'Notify when something custom happens'
    ],
];
```

3. **Send the Notification**

```php
use App\Notifications\MyCustomNotification;

$user->notify(new MyCustomNotification(
    'Something Happened',
    'Details about what happened'
));
```

### Sending Notifications from Jobs

```php
use App\Notifications\IntegrationCompleted;

class SyncIntegrationJob implements ShouldQueue
{
    public function handle(): void
    {
        try {
            // ... do sync work ...

            $this->integration->user->notify(
                new IntegrationCompleted($this->integration, [
                    'events_synced' => $eventCount,
                    'duration' => $duration,
                ])
            );
        } catch (\Exception $e) {
            $this->integration->user->notify(
                new IntegrationFailed(
                    $this->integration,
                    $e->getMessage()
                )
            );

            throw $e;
        }
    }
}
```

### Notification Flow

1. **User triggers action** (e.g., starts integration sync)
2. **Job processes** the action
3. **Job sends notification** via `$user->notify()`
4. **SparkNotification** determines channels:
    - Always stores in database (for UI)
    - Checks if user has email enabled for this type
    - Checks priority status
    - Checks work hours if applicable
    - Adds 'mail' channel if appropriate
5. **Notification queued** (if email channel selected)
6. **Email sent** according to user's timing preferences
7. **UI updates** via Livewire polling (30s interval)

## Best Practices

### 1. Use Priority Wisely

Only mark notifications as priority if they require immediate attention:

- System failures
- Security issues
- Critical errors
- Scheduled maintenance

### 2. Provide Meaningful Details

Include relevant details in the notification:

```php
$user->notify(new IntegrationCompleted($integration, [
    'events_synced' => 150,
    'blocks_created' => 75,
    'duration' => '2m 30s',
    'next_sync' => '15 minutes',
]));
```

### 3. Always Include Action URLs

When possible, provide a link to relevant content:

```php
public function getActionUrl(): ?string
{
    return route('integrations.show', $this->integration->id);
}
```

### 4. Test Both Channels

Test notifications with:

- Email enabled/disabled
- Different work hour configurations
- Different delayed sending modes
- Priority and non-priority scenarios

### 5. Handle Errors Gracefully

Always wrap notification sending in try-catch:

```php
try {
    $user->notify(new MyNotification(...));
} catch (\Exception $e) {
    Log::error('Failed to send notification', [
        'user_id' => $user->id,
        'type' => 'my_notification',
        'error' => $e->getMessage(),
    ]);
}
```

## Advanced Features

### Custom Channel Selection

Override the `via()` method for custom channel logic:

```php
public function via(User $notifiable): array
{
    $channels = parent::via($notifiable);

    // Add Slack for critical notifications
    if ($this->isCritical) {
        $channels[] = 'slack';
    }

    return $channels;
}
```

### Delayed Sending Implementation

For daily digest mode, create a scheduled job:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->call(function () {
        // Find users with daily_digest mode
        User::all()->each(function ($user) {
            if ($user->getDelayedSendingMode() === 'daily_digest') {
                $digestTime = $user->getDigestTime();
                $timezone = $user->getNotificationPreferences()['work_hours']['timezone'];

                // Check if it's time to send digest
                if (now()->timezone($timezone)->format('H:i') === $digestTime) {
                    SendNotificationDigest::dispatch($user);
                }
            }
        });
    })->everyMinute();
}
```

### Notification Cleanup

Add to your scheduled tasks:

```php
// Clean up old read notifications (older than 30 days)
$schedule->call(function () {
    DB::table('notifications')
        ->whereNotNull('read_at')
        ->where('read_at', '<', now()->subDays(30))
        ->delete();
})->daily();
```

## Troubleshooting

### Notifications Not Showing in UI

1. Check the notifications table has records
2. Verify `notifiable_id` matches the user's UUID
3. Check that `read_at` is NULL for unread notifications
4. Refresh the page or wait for polling cycle (30s)

### Emails Not Sending

1. Check user has email enabled: `$user->hasEmailNotificationsEnabled('type')`
2. Verify mail configuration in `.env`
3. Check queue is running: `sail artisan queue:work`
4. Check for work hours restrictions
5. Verify delayed sending mode settings

### Work Hours Not Respected

1. Confirm timezone is set correctly in preferences
2. Check time format is 'HH:mm' (24-hour)
3. Verify `work_hours.enabled` is true
4. Test `$user->isInWorkHours()` method

## Testing

See `/tests/Feature/NotificationSystemTest.php` for comprehensive test examples.

## Future Enhancements

Potential improvements to consider:

1. **Notification Grouping**
    - Group multiple similar notifications
    - "3 integrations completed" instead of 3 separate notifications

2. **Push Notifications**
    - Add web push notification support
    - Mobile app notifications

3. **Custom Notification Sounds**
    - User-configurable notification sounds
    - Different sounds for priority notifications

4. **Notification Snoozing**
    - Allow users to snooze non-priority notifications
    - Remind later functionality

5. **Notification Analytics**
    - Track open rates
    - Most clicked notifications
    - User engagement metrics

6. **Batch Operations**
    - Mark multiple notifications as read
    - Bulk delete by type
    - Filter by notification type

7. **Notification Templates**
    - Customizable email templates
    - User-editable message formats
