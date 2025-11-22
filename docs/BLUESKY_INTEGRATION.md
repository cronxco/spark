# BlueSky Integration

Track your BlueSky bookmarks, likes, and reposts with rich post data extraction.

## Overview

The BlueSky integration connects to your BlueSky account via OAuth and tracks your social activity. It monitors bookmarks, likes, and reposts, extracting rich post content including text, media, quoted posts, and engagement metrics.

## Features

- OAuth authentication using DPoP (Demonstrating Proof-of-Possession)
- Track bookmarked posts
- Track liked posts
- Track reposts
- Extract quoted post content
- Extract thread context for replies
- Post metrics (likes, reposts, replies)
- Link preview extraction
- Historical data migration support

## Setup

### Prerequisites

- A BlueSky account
- BlueSky OAuth private key

### Generate OAuth Private Key

The BlueSky integration requires a private key for DPoP authentication:

```bash
sail artisan bluesky:new-private-key
```

This generates a key and adds it to your `.env` file.

### Environment Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `BLUESKY_OAUTH_PRIVATE_KEY` | DPoP private key | Yes |

### Configuration

The private key is automatically added to your `.env` file by the artisan command. Alternatively, add manually:

```env
BLUESKY_OAUTH_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----
...your key...
-----END PRIVATE KEY-----"
```

## Data Model

### Instance Types

| Type | Description |
|------|-------------|
| `activity` | Activity tracking (bookmarks, likes, reposts) |

### Action Types

| Action | Description | Value Unit |
|--------|-------------|------------|
| `bookmarked_post` | Post you bookmarked | - |
| `liked_post` | Post you liked | - |
| `reposted` | Post you reposted | - |

### Block Types

| Block Type | Description |
|------------|-------------|
| `post_content` | Text content of the post |
| `post_media` | Images or videos in the post |
| `quoted_post_content` | Content of quoted posts |
| `thread_parent` | Parent post in a thread |
| `post_metrics` | Engagement metrics |
| `link_preview` | Extracted URLs |

### Object Types

| Object Type | Description |
|-------------|-------------|
| `bluesky_user` | BlueSky user account |
| `bluesky_post` | A post on BlueSky |

## Usage

### Connecting BlueSky

1. Navigate to Integrations in Spark
2. Click "Connect" on BlueSky
3. Enter your BlueSky handle when prompted
4. Authorize the application
5. Configure tracking options

### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `update_frequency_minutes` | integer | 15 | How often to sync (min: 5) |
| `track_bookmarks` | boolean | true | Track bookmarked posts |
| `track_likes` | boolean | true | Track liked posts |
| `track_reposts` | boolean | true | Track reposted posts |
| `include_quoted_posts` | boolean | true | Extract quoted post content |
| `include_thread_context` | boolean | true | Extract parent post for replies |

### Manual Operations

```bash
# Trigger historical data migration
sail artisan tinker
>>> $integration = App\Models\Integration::find('uuid');
>>> App\Jobs\OAuth\BlueSky\BlueSkyActivityInitialization::dispatch($integration);
```

## Technical Details

### OAuth Implementation

BlueSky uses AT Protocol OAuth with DPoP tokens:

1. User initiates connection with their handle
2. Redirect to BlueSky authorization server
3. DPoP proof is generated using the private key
4. Token exchange with proof-of-possession
5. DID (Decentralized Identifier) stored as account ID

### AT Protocol

BlueSky is built on the AT Protocol (Authenticated Transfer Protocol):

- Uses DIDs for decentralized identity
- Lexicons define data schemas
- Records are stored in personal data servers (PDS)

### Required Scopes

- `atproto` - Access to AT Protocol operations

## Event Structure

### Bookmarked Post

```json
{
  "source_id": "bluesky_bookmark_{uri}",
  "time": "2024-01-15T10:30:00Z",
  "service": "bluesky",
  "domain": "online",
  "action": "bookmarked_post",
  "actor": {
    "type": "bluesky_user",
    "title": "@your.handle"
  },
  "target": {
    "type": "bluesky_post",
    "title": "Post by @author.handle"
  }
}
```

### Post Content Block

```json
{
  "block_type": "post_content",
  "title": "Post Text",
  "metadata": {
    "text": "The full post content...",
    "created_at": "2024-01-15T10:00:00Z"
  }
}
```

## Troubleshooting

### Common Issues

1. **OAuth Private Key Not Configured**
   - Run `sail artisan bluesky:new-private-key`
   - Ensure the key is in your `.env` file

2. **OAuth Authorization Failed**
   - Verify your BlueSky handle is correct
   - Check that the private key is valid
   - Try regenerating the private key

3. **Missing Activity**
   - Check that the relevant tracking options are enabled
   - Verify the integration has recent `last_successful_update_at`
   - Run historical migration if needed

4. **Rate Limiting**
   - BlueSky has rate limits on API calls
   - Increase `update_frequency_minutes` if hitting limits

## Related Documentation

- [INTEGRATION_PLUGINS.md](INTEGRATION_PLUGINS.md) - Plugin architecture
- [README_JOBS.md](README_JOBS.md) - Job system overview
