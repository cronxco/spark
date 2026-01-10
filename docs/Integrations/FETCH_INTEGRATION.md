# Fetch Integration

Fetch and archive web content from subscribed URLs with AI-powered summaries.

## Overview

The Fetch integration is a web content archival system that fetches URLs, extracts readable content using Readability, and generates AI-powered summaries. It supports both simple HTTP requests and Playwright-based browser automation for JavaScript-heavy sites and pages requiring authentication cookies.

## Features

- Subscribe to URLs for periodic fetching
- Automatic content extraction using Readability
- AI-generated summaries (tweet, short, paragraph, TL;DR, key takeaways)
- Playwright browser automation for JavaScript-heavy sites
- Cookie management for paywalled/authenticated content
- PDF download support
- Automatic URL discovery from other integrations
- Change detection via content hashing
- Spotlight command integration

## Setup

### Prerequisites

- PHP with Readability library (fivefilters/readability)
- Optional: Playwright service for JavaScript rendering

### Environment Variables

| Variable                         | Description                             | Required   |
| -------------------------------- | --------------------------------------- | ---------- |
| `PLAYWRIGHT_ENABLED`             | Enable Playwright engine                | No         |
| `PLAYWRIGHT_URL`                 | Playwright service URL                  | If enabled |
| `PLAYWRIGHT_STEALTH_ENABLED`     | Enable stealth mode                     | No         |
| `PLAYWRIGHT_AUTO_ESCALATE`       | Auto-escalate to Playwright on failures | No         |
| `PLAYWRIGHT_JS_REQUIRED_DOMAINS` | Comma-separated domains requiring JS    | No         |

### Configuration

Add to `config/services.php`:

```php
'playwright' => [
    'enabled' => env('PLAYWRIGHT_ENABLED', false),
    'url' => env('PLAYWRIGHT_URL', 'http://localhost:3000'),
    'stealth_enabled' => env('PLAYWRIGHT_STEALTH_ENABLED', true),
    'auto_escalate' => env('PLAYWRIGHT_AUTO_ESCALATE', true),
    'js_required_domains' => env('PLAYWRIGHT_JS_REQUIRED_DOMAINS', ''),
],
```

## Data Model

### Instance Types

| Type      | Description                   |
| --------- | ----------------------------- |
| `fetcher` | Fetch and archive web content |

### Action Types

| Action       | Description             | Hidden |
| ------------ | ----------------------- | ------ |
| `fetched`    | URL content was fetched | Yes    |
| `bookmarked` | URL was bookmarked      | No     |
| `updated`    | URL content was updated | Yes    |

### Block Types

| Block Type                | Description                         |
| ------------------------- | ----------------------------------- |
| `fetch_summary_tweet`     | Ultra-concise 280 character summary |
| `fetch_summary_short`     | 40 word summary                     |
| `fetch_summary_paragraph` | 150 word detailed summary           |
| `fetch_key_takeaways`     | 3-5 actionable bullet points        |
| `fetch_tldr`              | One sentence summary                |

### Object Types

| Object Type     | Description                |
| --------------- | -------------------------- |
| `fetch_webpage` | A fetched webpage          |
| `fetch_user`    | Fetch system user (hidden) |

## Usage

### Connecting Fetch

1. Navigate to Integrations in Spark
2. Create a new Fetch integration
3. Configure update frequency or schedule
4. Add URLs to monitor
5. Optionally configure cookie authentication

### Configuration Options

| Option                     | Type    | Default       | Description                       |
| -------------------------- | ------- | ------------- | --------------------------------- |
| `update_frequency_minutes` | integer | 180           | Fetch frequency (60-1440 min)     |
| `use_schedule`             | boolean | true          | Use specific times vs frequency   |
| `schedule_times`           | array   | Every 3 hours | Times to fetch (HH:mm)            |
| `schedule_timezone`        | string  | `UTC`         | Timezone for schedule             |
| `monitor_integrations`     | array   | `[]`          | Integration IDs for URL discovery |

### Spotlight Commands

The Fetch integration provides these Spotlight commands:

| Command                 | Description                            |
| ----------------------- | -------------------------------------- |
| Add URL to Fetch        | Subscribe to a new URL to monitor      |
| Manage Fetch Cookies    | Add/update authentication cookies      |
| Configure URL Discovery | Set up automatic URL discovery         |
| View Fetch Statistics   | See archived content and fetch history |
| View All Bookmarks      | Browse fetched content and summaries   |

### Manual Operations

```bash
# Fetch all scheduled URLs
sail artisan integrations:fetch --service=fetch

# Fetch a single URL manually
sail artisan tinker
>>> $integration = App\Models\Integration::find('uuid');
>>> $webpage = App\Models\EventObject::find('webpage-uuid');
>>> App\Jobs\Fetch\FetchSingleUrl::dispatch($integration, $webpage->id, $webpage->url, true);
```

## Fetch Engine

### Engine Selection

The FetchEngineManager automatically selects between HTTP and Playwright:

1. **Playwright** is used when:
    - User explicitly requested Playwright for the URL
    - URL's domain is in the JS-required list
    - Previous fetch learned that Playwright is needed
    - Recent errors indicate robot detection/CAPTCHA

2. **HTTP** is used when:
    - Playwright is disabled
    - User explicitly requested HTTP for the URL
    - Default for new URLs (then learns)

### Content Extraction

Content is extracted using the Readability library:

```php
use App\Integrations\Fetch\ContentExtractor;

$result = ContentExtractor::extract($html, $url);

if ($result['success']) {
    $data = $result['data'];
    // $data['title'] - Page title
    // $data['content'] - HTML content
    // $data['text_content'] - Plain text
    // $data['excerpt'] - Short excerpt
    // $data['author'] - Author name
    // $data['image'] - Featured image URL
}
```

### Validation & Detection

The extractor validates content and detects issues:

| Detection            | Description                    |
| -------------------- | ------------------------------ |
| Paywall              | Subscription-required content  |
| Robot Check          | CAPTCHA or bot detection pages |
| Insufficient Content | Less than 100 characters       |
| Missing Title        | No title or title too short    |

### Cookie Management

For authenticated/paywalled content:

1. Export cookies from your browser for the target domain
2. Add cookies via the Fetch management UI
3. Cookies are stored in `auth_metadata` and sent with requests

```php
// Cookies are stored per-domain in the integration group
$group->auth_metadata = [
    'domains' => [
        'example.com' => [
            'cookies' => [/* cookie data */],
            'expires_at' => '2024-12-31T00:00:00Z',
        ],
    ],
];
```

## AI Summary Generation

After content extraction, summaries are generated:

### Summary Types

| Type          | Length      | Use Case         |
| ------------- | ----------- | ---------------- |
| Tweet         | 280 chars   | Quick share      |
| Short         | 40 words    | Quick overview   |
| Paragraph     | 150 words   | Detailed summary |
| TL;DR         | 1 sentence  | At-a-glance      |
| Key Takeaways | 3-5 bullets | Action items     |

### Job Pipeline

```
FetchScheduledUrls
    └── FetchSingleUrl (per URL)
            └── ProcessFetchedContent
                    ├── ExtractContentJob
                    └── GenerateSummariesJob
```

## Event Structure

### Bookmarked URL

```json
{
    "source_id": "fetch_webpage_{id}",
    "time": "2024-01-15T10:30:00Z",
    "service": "fetch",
    "domain": "knowledge",
    "action": "bookmarked",
    "actor": {
        "type": "fetch_user",
        "title": "Fetch System"
    },
    "target": {
        "type": "fetch_webpage",
        "title": "Article Title",
        "url": "https://example.com/article"
    }
}
```

### Summary Block

```json
{
    "block_type": "fetch_summary_short",
    "title": "Short Summary",
    "metadata": {
        "summary": "A 40-word summary of the article content..."
    }
}
```

## Troubleshooting

### Common Issues

1. **Content Extraction Failed**
    - Check if the page requires JavaScript (enable Playwright)
    - Verify the page isn't behind a paywall
    - Check for robot detection/CAPTCHA
    - Try adding authentication cookies

2. **Playwright Unavailable**
    - Verify Playwright service is running
    - Check `PLAYWRIGHT_ENABLED=true` in environment
    - Verify `PLAYWRIGHT_URL` is correct

3. **Paywall Detected**
    - Add authentication cookies for the domain
    - Some paywalls cannot be bypassed even with cookies
    - Check cookie expiration dates

4. **Robot Check Detected**
    - Enable Playwright with stealth mode
    - Some sites have strong bot detection
    - Consider manual archiving

5. **Auto-Disabled URL**
    - URLs are disabled after 5 consecutive failures
    - Check the error message in metadata
    - Re-enable after fixing the issue

6. **Missing Summaries**
    - Verify AI service is configured
    - Check that content extraction succeeded
    - Review job queue for errors

### Error Tracking

The system tracks fetch history per webpage:

```php
// View fetch history
$webpage = EventObject::find('uuid');
$history = $webpage->metadata['playwright_history'] ?? [];

// Each entry contains:
// - timestamp
// - decision (http/playwright)
// - reason (user_preference, learned, js_domain, etc.)
// - outcome (success/failed)
// - duration_ms
// - status_code
```

### Notifications

- **Multiple Failures**: Notification sent after 3 consecutive failures
- **Auto-Disable**: URL auto-disabled after 5 consecutive failures

## Related Documentation

- [INTEGRATION_PLUGINS.md](INTEGRATION_PLUGINS.md) - Plugin architecture
- [SEMANTIC_SEARCH.md](SEMANTIC_SEARCH.md) - Search your bookmarks
- [README_JOBS.md](README_JOBS.md) - Job system overview
