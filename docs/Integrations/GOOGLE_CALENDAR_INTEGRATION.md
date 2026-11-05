# Google Calendar Integration

Sync events from your Google Calendar with filtering support.

## Overview

The Google Calendar integration connects via OAuth to sync calendar events to Spark. It supports multiple calendars, regex-based event filtering, incremental sync using Google's sync tokens, and automatic handling of event updates and deletions.

## Features

- OAuth authentication with PKCE security
- Sync from any Google Calendar (primary or secondary)
- Regex-based title filtering (include/exclude patterns)
- Incremental sync for efficiency
- Support for timed and all-day events
- Automatic event deletion tracking
- Attendee and location information
- Historical data migration support

## Setup

### Prerequisites

- A Google account
- A Google Cloud project with Calendar API enabled

### Google Cloud Configuration

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing
3. Enable the Google Calendar API
4. Go to Credentials > Create Credentials > OAuth client ID
5. Select "Web application"
6. Add authorized redirect URI: `https://yourdomain.com/integrations/google-calendar/callback`
7. Note the Client ID and Client Secret

### Environment Variables

| Variable                        | Description                   | Required |
| ------------------------------- | ----------------------------- | -------- |
| `GOOGLE_CALENDAR_CLIENT_ID`     | OAuth client ID               | Yes      |
| `GOOGLE_CALENDAR_CLIENT_SECRET` | OAuth client secret           | Yes      |
| `GOOGLE_CALENDAR_REDIRECT_URI`  | OAuth callback URL (optional) | No       |

### Configuration

Add to your `.env` file:

```env
GOOGLE_CALENDAR_CLIENT_ID=your_client_id.apps.googleusercontent.com
GOOGLE_CALENDAR_CLIENT_SECRET=your_client_secret
```

Add to `config/services.php`:

```php
'google-calendar' => [
    'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
    'redirect' => env('GOOGLE_CALENDAR_REDIRECT_URI'),
],
```

## Data Model

### Instance Types

| Type     | Description          |
| -------- | -------------------- |
| `events` | Calendar events sync |

### Action Types

| Action              | Description            | Value Unit |
| ------------------- | ---------------------- | ---------- |
| `had_event`         | Timed calendar event   | minutes    |
| `had_all_day_event` | All-day calendar event | minutes    |

### Block Types

| Block Type        | Description                                      |
| ----------------- | ------------------------------------------------ |
| `event_details`   | Event description, status, visibility, organizer |
| `event_attendees` | List of attendees with response status           |
| `event_location`  | Physical location or meeting link                |
| `event_time`      | Start and end times with timezone                |

### Object Types

| Object Type       | Description               |
| ----------------- | ------------------------- |
| `google_calendar` | A Google Calendar (actor) |
| `calendar_event`  | A calendar event (target) |

## Usage

### Connecting Google Calendar

1. Navigate to Integrations in Spark
2. Click "Connect" on Google Calendar
3. Authorize the OAuth application on Google
4. Select which calendar to sync
5. Configure sync settings and filters

### Configuration Options

| Option                     | Type    | Default | Description                           |
| -------------------------- | ------- | ------- | ------------------------------------- |
| `calendar_id`              | string  | -       | Calendar ID to sync (e.g., "primary") |
| `calendar_name`            | string  | -       | Display name for the calendar         |
| `update_frequency_minutes` | integer | 15      | How often to sync (min: 5)            |
| `sync_days_past`           | integer | 7       | Days in past to sync                  |
| `sync_days_future`         | integer | 30      | Days in future to sync                |
| `title_include_patterns`   | array   | []      | Regex patterns to include             |
| `title_exclude_patterns`   | array   | []      | Regex patterns to exclude             |

### Event Filtering

Use regex patterns to filter which events are synced:

**Include only work meetings:**

```json
{
    "title_include_patterns": ["/meeting/i", "/standup/i", "/1:1/i"]
}
```

**Exclude personal events:**

```json
{
    "title_exclude_patterns": ["/personal/i", "/private/i"]
}
```

Filter logic:

1. Exclude patterns are checked first (any match excludes)
2. If include patterns exist, at least one must match
3. If no include patterns, all non-excluded events are included

### Manual Operations

```bash
# Fetch data for Google Calendar integrations
sail artisan integrations:fetch --service=google-calendar

# List available calendars for a user
sail artisan tinker
>>> $plugin = new App\Integrations\GoogleCalendar\GoogleCalendarPlugin();
>>> $group = App\Models\IntegrationGroup::find('uuid');
>>> $plugin->fetchAvailableCalendars($group);
```

## API Reference

### External APIs Used

| Endpoint                     | Purpose                   |
| ---------------------------- | ------------------------- |
| `GET /calendars/primary`     | Get primary calendar info |
| `GET /users/me/calendarList` | List available calendars  |
| `GET /calendars/{id}/events` | Fetch calendar events     |

### OAuth Scopes

Required scope:

- `https://www.googleapis.com/auth/calendar` - Full calendar access

### Incremental Sync

The integration uses Google's sync tokens for efficient incremental syncs:

1. First sync fetches all events in the configured time window
2. Subsequent syncs use the sync token to get only changes
3. If sync token expires (410 response), a full sync is performed
4. Deleted events are automatically removed from Spark

## Event Structure

### Timed Event

```json
{
    "source_id": "google_calendar_{cal_id}_{event_id}_{timestamp}",
    "time": "2024-01-15T10:00:00Z",
    "service": "google_calendar",
    "domain": "knowledge",
    "action": "had_event",
    "value": 60,
    "value_unit": "minutes",
    "event_metadata": {
        "google_event_id": "event123",
        "description": "Weekly team sync",
        "location": "Conference Room A",
        "hangout_link": "https://meet.google.com/...",
        "status": "confirmed"
    }
}
```

## Troubleshooting

### Common Issues

1. **OAuth Authorization Failed**
    - Verify Calendar API is enabled in Google Cloud Console
    - Check Client ID and Secret are correct
    - Ensure redirect URI matches exactly (including https)

2. **Token Refresh Failed**
    - User may need to re-authorize the application
    - Check if refresh token is stored (request `access_type=offline`)
    - Verify application is not in testing mode with token expiry

3. **Missing Events**
    - Check sync window configuration (days past/future)
    - Verify include/exclude patterns are correct regex
    - Ensure calendar ID is correct (use "primary" for main calendar)

4. **Duplicate Events for Recurring Series**
    - Each occurrence gets a unique source ID including timestamp
    - This is expected behavior for tracking individual occurrences

## Related Documentation

- [INTEGRATION_PLUGINS.md](INTEGRATION_PLUGINS.md) - Plugin architecture
- [SCHEDULED_INTEGRATION_UPDATES.md](SCHEDULED_INTEGRATION_UPDATES.md) - Update scheduling
