# Karakeep Integration

Sync bookmarks from Karakeep.

## Overview

The Karakeep integration connects to your self-hosted Karakeep instance via API key authentication. It syncs bookmarks with AI-generated summaries, metadata previews, and text highlights, providing a searchable archive of your saved content.

## Features

- API key authentication
- Sync bookmarks with full metadata
- AI-generated summaries (if enabled in Karakeep)
- Rich preview cards with images
- Text highlights extraction
- List/collection tracking
- Historical data migration support

## Setup

### Prerequisites

- A Karakeep instance (self-hosted)
- A Karakeep API access token

### Obtaining API Token

1. Log into your Karakeep instance
2. Go to Settings > API
3. Generate a new access token
4. Copy the JWT token

### Environment Variables

| Variable                | Description                        | Required |
| ----------------------- | ---------------------------------- | -------- |
| `KARAKEEP_URL`          | Base URL of your Karakeep instance | Yes      |
| `KARAKEEP_ACCESS_TOKEN` | API access token (JWT)             | Yes      |

### Configuration

Add to your `.env` file:

```env
KARAKEEP_URL=https://karakeep.example.com
KARAKEEP_ACCESS_TOKEN=your_jwt_token
```

Add to `config/services.php`:

```php
'karakeep' => [
    'url' => env('KARAKEEP_URL'),
    'access_token' => env('KARAKEEP_ACCESS_TOKEN'),
],
```

## Data Model

### Instance Types

| Type        | Description        |
| ----------- | ------------------ |
| `bookmarks` | Sync all bookmarks |

### Action Types

| Action          | Description              | Value Unit |
| --------------- | ------------------------ | ---------- |
| `bookmarked`    | A bookmark was saved     | -          |
| `added_to_list` | Bookmark added to a list | -          |

### Block Types

| Block Type           | Description                                   |
| -------------------- | --------------------------------------------- |
| `bookmark_summary`   | AI-generated summary                          |
| `bookmark_metadata`  | Rich preview card (title, description, image) |
| `bookmark_highlight` | Text highlight from the bookmark              |

### Object Types

| Object Type         | Description              |
| ------------------- | ------------------------ |
| `karakeep_bookmark` | A bookmark from Karakeep |
| `karakeep_list`     | A list/collection        |
| `karakeep_user`     | Karakeep user (hidden)   |

## Usage

### Connecting Karakeep

1. Navigate to Integrations in Spark
2. Click "Connect" on Karakeep
3. Enter your Karakeep API URL and access token
4. Configure sync settings
5. Optionally run historical migration

### Configuration Options

| Option                     | Type    | Default | Description                   |
| -------------------------- | ------- | ------- | ----------------------------- |
| `api_url`                  | string  | -       | Base URL of Karakeep instance |
| `access_token`             | string  | -       | JWT access token              |
| `update_frequency_minutes` | integer | 30      | How often to sync (15-1440)   |
| `fetch_limit`              | integer | 50      | Bookmarks per sync (10-100)   |

### Manual Operations

```bash
# Fetch bookmarks for Karakeep integrations
sail artisan integrations:fetch --service=karakeep

# Trigger historical migration
sail artisan tinker
>>> $integration = App\Models\Integration::find('uuid');
>>> App\Jobs\OAuth\Karakeep\KarakeepBookmarksInitialization::dispatch($integration);
```

## API Reference

### External APIs Used

| Endpoint                | Purpose                 |
| ----------------------- | ----------------------- |
| `GET /api/v1/bookmarks` | Fetch bookmarks         |
| `GET /api/v1/lists`     | Fetch lists/collections |

### Authentication

Karakeep uses JWT bearer token authentication:

```
Authorization: Bearer {access_token}
```

## Event Structure

### Bookmarked

```json
{
    "source_id": "karakeep_bookmark_{id}",
    "time": "2024-01-15T10:30:00Z",
    "service": "karakeep",
    "domain": "knowledge",
    "action": "bookmarked",
    "actor": {
        "type": "karakeep_user",
        "title": "User"
    },
    "target": {
        "type": "karakeep_bookmark",
        "title": "Article Title",
        "url": "https://example.com/article"
    }
}
```

### Block: AI Summary

```json
{
    "block_type": "bookmark_summary",
    "title": "AI Summary",
    "metadata": {
        "summary": "This article discusses..."
    }
}
```

### Block: Preview Card

```json
{
    "block_type": "bookmark_metadata",
    "title": "Preview Card",
    "media_url": "https://example.com/og-image.jpg",
    "metadata": {
        "title": "Article Title",
        "description": "Article description...",
        "site_name": "Example.com"
    }
}
```

## Troubleshooting

### Common Issues

1. **Authentication Failed**
    - Verify the API URL is correct (include https://)
    - Check the access token is valid and not expired
    - Ensure the token has appropriate permissions

2. **Connection Refused**
    - Verify your Karakeep instance is accessible
    - Check firewall rules allow connections from Spark
    - Confirm the URL doesn't have a trailing slash

3. **Missing Summaries**
    - AI summaries are only available if enabled in Karakeep
    - Some bookmarks may not have summaries yet

4. **Sync Not Running**
    - Check update frequency is set (minimum 15 minutes)
    - Verify the integration is not paused
    - Check Horizon is running for queue processing

## Related Documentation

- [INTEGRATION_PLUGINS.md](INTEGRATION_PLUGINS.md) - Plugin architecture
- [SEMANTIC_SEARCH.md](SEMANTIC_SEARCH.md) - Search your bookmarks
