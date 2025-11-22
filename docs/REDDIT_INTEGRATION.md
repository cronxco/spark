# Reddit Integration

Sync saved posts and comments from Reddit.

## Overview

The Reddit integration connects via OAuth to sync your saved posts and comments from Reddit. It tracks bookmarked content with full post details, images, and links, allowing you to search and organize your Reddit saves within Spark.

## Features

- OAuth authentication with PKCE security
- Sync saved posts and comments
- Extract images and links from posts
- Support for pagination through saved items
- Automatic token refresh

## Setup

### Prerequisites

- A Reddit account
- A Reddit OAuth application

### Reddit App Configuration

1. Go to [Reddit App Preferences](https://www.reddit.com/prefs/apps)
2. Click "create another app..."
3. Select "web app"
4. Set redirect URI: `https://yourdomain.com/integrations/reddit/callback`
5. Note the client ID (under the app name) and secret

### Environment Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `REDDIT_CLIENT_ID` | OAuth application client ID | Yes |
| `REDDIT_CLIENT_SECRET` | OAuth application client secret | Yes |
| `REDDIT_REDIRECT_URI` | OAuth callback URL (optional) | No |
| `REDDIT_USERAGENT` | API user agent string | Recommended |

### Configuration

Add to your `.env` file:

```env
REDDIT_CLIENT_ID=your_client_id
REDDIT_CLIENT_SECRET=your_client_secret
REDDIT_USERAGENT=SparkApp/1.0 by /u/yourusername
```

Add to `config/services.php`:

```php
'reddit' => [
    'client_id' => env('REDDIT_CLIENT_ID'),
    'client_secret' => env('REDDIT_CLIENT_SECRET'),
    'redirect' => env('REDDIT_REDIRECT_URI'),
    'useragent' => env('REDDIT_USERAGENT', 'SparkApp/1.0'),
],
```

## Data Model

### Instance Types

| Type | Description |
|------|-------------|
| `saved` | Sync saved posts and comments |

### Action Types

| Action | Description | Value Unit |
|--------|-------------|------------|
| `bookmarked` | A saved post or comment | - |

### Block Types

| Block Type | Description |
|------------|-------------|
| `image` | Image from the post |
| `url` | Link referenced in content |

### Object Types

| Object Type | Description |
|-------------|-------------|
| `reddit_account` | Reddit user account |
| `reddit_post` | A Reddit post |
| `reddit_comment` | A Reddit comment |
| `reddit_image` | Image from a post |

## Usage

### Connecting Reddit

1. Navigate to Integrations in Spark
2. Click "Connect" on Reddit
3. Authorize the application on Reddit
4. Configure update frequency

### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `update_frequency_minutes` | integer | 15 | How often to sync (min: 1) |

### Manual Operations

```bash
# Fetch saved items for Reddit integrations
sail artisan integrations:fetch --service=reddit
```

## API Reference

### External APIs Used

| Endpoint | Purpose |
|----------|---------|
| `GET /user/{username}/saved` | Fetch saved posts/comments |
| `GET /api/v1/me` | Get authenticated user info |

### OAuth Scopes

Required scopes:

- `identity` - Read user identity
- `history` - Access saved items history
- `read` - Read content
- `save` - Access saved items

### Rate Limits

Reddit API has rate limits:

- **OAuth clients**: 60 requests per minute
- Burst requests may trigger temporary blocks

The integration includes appropriate delays and respects rate limits.

## Event Structure

### Saved Post

```json
{
  "source_id": "reddit_saved_{post_id}",
  "time": "2024-01-15T10:30:00Z",
  "service": "reddit",
  "domain": "online",
  "action": "bookmarked",
  "actor": {
    "type": "reddit_account",
    "title": "your_username"
  },
  "target": {
    "type": "reddit_post",
    "title": "Post title...",
    "url": "https://reddit.com/r/..."
  }
}
```

## Troubleshooting

### Common Issues

1. **OAuth Authorization Failed**
   - Verify client ID and secret are correct
   - Check redirect URI matches exactly
   - Ensure the app type is "web app"

2. **User-Agent Errors**
   - Reddit requires a descriptive user-agent
   - Set `REDDIT_USERAGENT` to include your username
   - Format: `platform:appname:version (by /u/username)`

3. **Rate Limiting**
   - Increase `update_frequency_minutes`
   - Reddit rate limits are strict for OAuth apps

4. **Token Expiry**
   - Tokens expire after 1 hour
   - Integration automatically refreshes using the refresh token
   - If refresh fails, user needs to re-authorize

## Related Documentation

- [INTEGRATION_PLUGINS.md](INTEGRATION_PLUGINS.md) - Plugin architecture
- [README_JOBS.md](README_JOBS.md) - Job system overview
