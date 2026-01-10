# Notification System

Real-time in-app notifications and email delivery with user-configurable preferences.

## Overview

The notification system extends Laravel's built-in framework with in-app notifications displayed in the global notification indicator, email notifications with user preferences, work hours scheduling, priority notifications that bypass delays, and user preferences stored in the `users->settings` JSON column.

## Architecture

### Database Schema

The `notifications` table follows Laravel's standard structure:

```sql
CREATE TABLE notifications (
    id UUID PRIMARY KEY,
    type VARCHAR(255),              -- Notification class name
    notifiable_type VARCHAR(255),   -- User
    notifiable_id UUID,             -- User ID
    data TEXT,                      -- JSON notification data
    read_at TIMESTAMP NULL,         -- When user read the notification
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX(notifiable_type, notifiable_id)
);
```

### Core Components

| Component                | Location                                                       | Purpose                                         |
| ------------------------ | -------------------------------------------------------------- | ----------------------------------------------- |
| SparkNotification        | `app/Notifications/SparkNotification.php`                      | Abstract base class for all notifications       |
| GlobalProgressIndicator  | `resources/views/livewire/global-progress-indicator.blade.php` | Navbar notification bell with dropdown          |
| Notification Preferences | `resources/views/livewire/settings/notifications.blade.php`    | User settings page at `/settings/notifications` |

### Notification Types

| Type                            | File                                                    | Priority | Description                         |
| ------------------------------- | ------------------------------------------------------- | -------- | ----------------------------------- |
| IntegrationCompleted            | `app/Notifications/IntegrationCompleted.php`            | No       | Integration sync completed          |
| IntegrationFailed               | `app/Notifications/IntegrationFailed.php`               | Yes      | Integration sync failed             |
| IntegrationAuthenticationFailed | `app/Notifications/IntegrationAuthenticationFailed.php` | Yes      | OAuth token refresh failed          |
| MigrationCompleted              | `app/Notifications/MigrationCompleted.php`              | No       | Historical data migration completed |
| MigrationFailed                 | `app/Notifications/MigrationFailed.php`                 | Yes      | Historical data migration failed    |
| DataExportReady                 | `app/Notifications/DataExportReady.php`                 | No       | Data export ready for download      |
| SystemMaintenance               | `app/Notifications/SystemMaintenance.php`               | Yes      | System maintenance announcements    |

Priority notifications always send immediately and bypass user preferences.

## Usage

### Sending Notifications

```php
use App\Notifications\IntegrationCompleted;
use App\Notifications\IntegrationFailed;

// Success notification
$user->notify(new IntegrationCompleted($integration, [
    'events_synced' => 150,
    'duration' => '2m 30s',
]));

// Error notification (priority - always sends)
$user->notify(new IntegrationFailed(
    $integration,
    'API rate limit exceeded',
    ['retry_after' => 3600]
));
```

### Creating Custom Notifications

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

    public function isPriority(): bool
    {
        return false;
    }

    public function getActionUrl(): ?string
    {
        return route('custom.show');
    }

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

Register new notification types in `resources/views/livewire/settings/notifications.blade.php`:

```php
public array $notificationTypes = [
    'my_custom_notification' => [
        'label' => 'Custom Notifications',
        'description' => 'Notify when something custom happens'
    ],
];
```

### User Model Helper Methods

```php
// Get all notification preferences
$preferences = $user->getNotificationPreferences();

// Check if email is enabled for a specific type
$user->hasEmailNotificationsEnabled('integration_completed');

// Enable/disable email notifications
$user->enableEmailNotifications('data_export_ready');
$user->disableEmailNotifications('data_export_ready');

// Update multiple preferences at once
$user->updateNotificationPreferences([
    'email_enabled' => ['integration_completed' => false],
    'work_hours' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
]);

// Check if user is currently in work hours
$user->isInWorkHours();

// Get delayed sending mode: 'immediate', 'work_hours', or 'daily_digest'
$user->getDelayedSendingMode();

// Get daily digest time
$user->getDigestTime();
```

## Configuration

### User Preferences Structure

Preferences are stored in `users->settings->notifications`:

```php
'notifications' => [
    'email_enabled' => [
        'integration_completed' => true,
        'integration_failed' => true,               // Priority - always sends
        'integration_authentication_failed' => true, // Priority - always sends
        'migration_completed' => true,
        'migration_failed' => true,                 // Priority - always sends
        'data_export_ready' => true,
        'system_maintenance' => false,              // Priority - always sends
    ],
    'work_hours' => [
        'enabled' => true,
        'timezone' => 'Europe/London',
        'start' => '09:00',
        'end' => '17:00',
    ],
    'delayed_sending' => [
        'mode' => 'immediate',      // 'immediate', 'work_hours', 'daily_digest'
        'digest_time' => '09:00',
    ],
]
```

### Delivery Timing Options

| Mode         | Behavior                                 |
| ------------ | ---------------------------------------- |
| immediate    | Send all emails right away               |
| work_hours   | Delay non-urgent emails until work hours |
| daily_digest | Group notifications into one daily email |

### UI Indicator Features

The global notification indicator provides:

- Active operations with spinner and count
- Unread notifications with badge count
- Auto-polling every 30 seconds
- Mark as read/unread actions
- Delete notifications
- Action buttons linking to relevant pages

## Troubleshooting

| Issue                           | Solution                                                                                                       |
| ------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| Notifications not showing in UI | Check notifications table has records, verify `notifiable_id` matches user UUID, confirm `read_at` is NULL     |
| Emails not sending              | Check `$user->hasEmailNotificationsEnabled('type')`, verify mail config, ensure queue is running               |
| Work hours not respected        | Confirm timezone is set correctly, check time format is 'HH:mm' (24-hour), verify `work_hours.enabled` is true |

## Related Documentation

- `CLAUDE.md` - Main codebase documentation
- `app/Notifications/SparkNotification.php` - Base notification class
- `tests/Feature/NotificationSystemTest.php` - Test examples
