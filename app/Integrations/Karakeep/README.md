# Karakeep Integration

The Karakeep integration allows you to sync bookmarks from your self-hosted Karakeep instance into Spark, complete with AI-generated summaries, tags, highlights, and rich metadata.

## Overview

Karakeep is an open-source "Bookmark Everything" app that uses AI for automatic content tagging and organization. This integration fetches your bookmarks, organizes them into events and objects, and preserves all metadata including tags, lists, highlights, and AI summaries.

**Website:** https://karakeep.app
**Documentation:** https://docs.karakeep.app
**Version Supported:** v0.27.0+

## Features

- **Bookmark Syncing**: Automatically sync bookmarks with full content and metadata
- **AI Summaries**: Store AI-generated summaries in both object metadata and dedicated blocks
- **Content Storage**: Store AI summary + first 150 words of content for quick access
- **Tagging**: Sync Karakeep tags to Spark's built-in tagging system
- **Lists/Collections**: Track bookmark organization via list memberships
- **Highlights**: Import text highlights as blocks
- **Rich Metadata**: Preview images, descriptions, author info, and more
- **Helper Function**: Programmatically add bookmarks from other integrations

## Installation

### 1. Configure Environment Variables

Add your Karakeep instance URL and API token to your `.env` file:

```env
KARAKEEP_URL=https://your-karakeep-instance.com
KARAKEEP_ACCESS_TOKEN=your_jwt_token_here
```

**Getting your API token:**

1. Log into your Karakeep instance
2. Go to Settings → API
3. Generate a new API token (JWT)
4. Copy the token to your `.env` file

### 2. Register the Plugin

The plugin is automatically registered in `app/Providers/IntegrationServiceProvider.php`:

```php
PluginRegistry::register(KarakeepPlugin::class);
```

### 3. Create Integration Instance

Via the UI:

1. Navigate to Integrations
2. Select "Karakeep" from the available plugins
3. Choose the "Bookmarks" instance type
4. Configure your settings:
    - **Update Frequency**: 30 minutes (default)
    - **Fetch Limit**: 50 bookmarks per sync
    - **Sync Highlights**: Enable/disable highlight syncing

## Data Model

### Object Types

| Type                | Description                                       | Hidden |
| ------------------- | ------------------------------------------------- | ------ |
| `karakeep_bookmark` | Individual bookmarks (links, notes, PDFs, images) | No     |
| `karakeep_list`     | Collections/lists organizing bookmarks            | No     |
| `karakeep_user`     | Karakeep user account                             | Yes    |

### Action Types

| Action           | Description                         | Actor    | Target   |
| ---------------- | ----------------------------------- | -------- | -------- |
| `saved_bookmark` | When a bookmark was saved/created   | User     | Bookmark |
| `added_to_list`  | When a bookmark was added to a list | Bookmark | List     |

### Block Types

| Type                 | Description                          | Icon         |
| -------------------- | ------------------------------------ | ------------ |
| `bookmark_summary`   | AI-generated summary of the bookmark | `o-sparkles` |
| `bookmark_metadata`  | Rich preview card (OpenGraph data)   | `o-photo`    |
| `bookmark_highlight` | Text highlights from the bookmark    | `o-pencil`   |

## Content Storage Strategy

### EventObject (karakeep_bookmark)

- **title**: Bookmark title
- **content**: AI summary + first 150 words of extracted content
- **url**: Bookmark URL
- **media_url**: Preview/OpenGraph image
- **metadata**:
    - `karakeep_id`: Original bookmark ID
    - `summary`: Full AI-generated summary
    - `description`: User/auto-generated description
    - `author`: Article author
    - `site_name`: Source website
    - `content_type`: article, note, pdf, image
    - `read_status`: unread, reading, read
    - `is_archived`: Boolean
    - `is_favorited`: Boolean
    - `word_count`: Article word count
    - `preview_image`: OpenGraph image URL
    - `created_at`: Karakeep creation timestamp
    - `updated_at`: Karakeep update timestamp

### Tagging

Tags from Karakeep are automatically synced to Spark's built-in tagging system using the `HasTags` trait:

- Applied to both **Events** and **Objects**
- Tag type: `'karakeep'`
- Tags are bidirectionally searchable and filterable

## Helper Functions

### `karakeep_add_bookmark()`

Add bookmarks to Karakeep programmatically from other integrations or commands.

**Signature:**

```php
function karakeep_add_bookmark(
    string $url,
    ?string $title = null,
    array $tags = []
): ?array
```

**Parameters:**

- `$url` (required): The URL to bookmark
- `$title` (optional): Title for the bookmark
- `$tags` (optional): Array of tag names to apply

**Returns:**

- `array` on success (bookmark data from API)
- `null` on failure

**Example Usage:**

```php
// Minimal usage
karakeep_add_bookmark('https://example.com/article');

// With title and tags
karakeep_add_bookmark(
    'https://laravel.com/docs',
    'Laravel Documentation',
    ['laravel', 'php', 'framework']
);

// From another integration
class SpotifyPlugin {
    public function onTrackSaved($track) {
        if ($track['type'] === 'podcast') {
            karakeep_add_bookmark(
                $track['external_urls']['spotify'],
                $track['name'],
                ['spotify', 'podcast']
            );
        }
    }
}
```

**Behavior:**

- Posts directly to Karakeep API
- Returns immediately without triggering a sync
- Bookmark will be synced on next scheduled pull (default: 30 minutes)
- Karakeep will process content extraction and AI summarization asynchronously
- Logs errors if API call fails

### `truncate_to_words()`

Truncate text to a specified number of words (used internally).

**Signature:**

```php
function truncate_to_words(string $text, int $wordLimit = 150): string
```

## Configuration

### Instance Configuration

| Option                     | Type    | Default | Description                     |
| -------------------------- | ------- | ------- | ------------------------------- |
| `update_frequency_minutes` | integer | 30      | How often to sync (15-1440 min) |
| `fetch_limit`              | integer | 50      | Bookmarks per sync (10-100)     |
| `sync_highlights`          | boolean | true    | Include highlights as blocks    |
| `paused`                   | boolean | false   | Pause syncing                   |

### Group Configuration

| Option         | Type   | Required | Description           |
| -------------- | ------ | -------- | --------------------- |
| `api_url`      | string | Yes      | Karakeep instance URL |
| `access_token` | string | Yes      | JWT access token      |

## Jobs

### KarakeepBookmarksPull

**File:** `app/Jobs/OAuth/Karakeep/KarakeepBookmarksPull.php`

Fetch job that pulls data from Karakeep API:

- Fetches user info (`/api/v1/users/me`)
- Fetches bookmarks (`/api/v1/bookmarks`)
- Fetches tags (`/api/v1/tags`)
- Fetches lists (`/api/v1/lists`)
- Fetches highlights (`/api/v1/highlights`) if enabled

**Configuration:**

- Timeout: 120 seconds
- Retries: 3 attempts
- Backoff: 60s, 300s, 600s

### KarakeepBookmarksData

**File:** `app/Jobs/Data/Karakeep/KarakeepBookmarksData.php`

Processing job that transforms API data into Spark entities:

- Creates user, bookmark, and list objects
- Applies tags to objects and events
- Creates `saved_bookmark` events with blocks
- Creates `added_to_list` events for list memberships
- Handles content truncation (summary + 150 words)

**Configuration:**

- Timeout: 300 seconds
- Retries: 2 attempts
- Backoff: 120s, 300s

## API Endpoints Used

| Endpoint             | Method | Purpose                       |
| -------------------- | ------ | ----------------------------- |
| `/api/v1/users/me`   | GET    | Fetch authenticated user info |
| `/api/v1/bookmarks`  | GET    | Fetch bookmarks (paginated)   |
| `/api/v1/bookmarks`  | POST   | Create new bookmark (helper)  |
| `/api/v1/tags`       | GET    | Fetch all tags                |
| `/api/v1/lists`      | GET    | Fetch all lists/collections   |
| `/api/v1/highlights` | GET    | Fetch bookmark highlights     |

## Migration Support

The plugin supports historical data migration for backfilling existing bookmarks:

```php
$plugin = new KarakeepPlugin;
$integration = $plugin->createInstance($group, 'bookmarks', [], true); // withMigration = true

// Integration will start paused
// Run migration job to fetch historical data
```

## Troubleshooting

### No bookmarks syncing

1. Check environment variables are set correctly
2. Verify API token is valid (test in Karakeep UI)
3. Check integration logs: `storage/logs/api_karakeep_{uuid}.log`
4. Ensure integration is not paused
5. Verify Karakeep instance is accessible from your server

### Duplicate events

The integration uses idempotent processing based on `source_id`. Each bookmark gets a unique ID: `karakeep_bookmark_{bookmark_id}`. Duplicates should not occur unless the database is corrupted.

### Tags not syncing

1. Verify Karakeep tags API is working: `/api/v1/tags`
2. Check that bookmarks have tags assigned in Karakeep
3. Review processing job logs for errors

### Content truncation issues

Content is deliberately truncated to summary + first 150 words to keep database size manageable. To view full content:

- Click bookmark URL to visit original source
- Future enhancement: Lazy-load full content via Karakeep API in UI

## Future Enhancements

- **Lazy Content Loading**: Fetch full content on-demand in UI
- **Read Status Sync**: Bidirectional sync of read/unread status
- **Archive Sync**: Sync archived bookmarks
- **Advanced Filtering**: Filter by read status, favorites, date ranges
- **Collections View**: Dedicated UI for browsing lists/collections
- **Embedding Sync**: Sync vector embeddings for similarity search

## Testing

Run the test suite:

```bash
sail artisan test --filter Karakeep
```

Test files:

- `tests/Feature/Integrations/Karakeep/KarakeepPluginTest.php`
- `tests/Unit/Jobs/KarakeepBookmarksPullTest.php`
- `tests/Unit/Jobs/KarakeepBookmarksDataTest.php`
- `tests/Unit/Helpers/KarakeepHelpersTest.php`

## Contributing

When contributing to this integration:

1. Follow the existing plugin architecture patterns
2. Use the `BaseFetchJob` and `BaseProcessingJob` classes
3. Add tests for new features
4. Update this README with new functionality
5. Use proper error handling and logging
6. Follow PSR-12 coding standards (enforced by Duster)

## License

This integration follows the same license as the parent Spark application.

## Support

For Karakeep-specific issues:

- GitHub: https://github.com/hoarder-app/hoarder (Note: Karakeep was formerly "Hoarder")
- Documentation: https://docs.karakeep.app

For Spark integration issues:

- Create an issue in the Spark repository
- Include relevant logs from `storage/logs/api_karakeep_*.log`
