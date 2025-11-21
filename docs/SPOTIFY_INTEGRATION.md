# Spotify Integration

Sync listening history from Spotify.

## Overview

The Spotify integration connects to your Spotify account and automatically creates events whenever you listen to tracks or podcast episodes. It tracks your recently played content, creates rich metadata blocks with album artwork and track details, and auto-tags events by artist, album, and context.

## Features

- Real-time listening activity tracking via API polling
- Recently played tracks (up to 50 most recent)
- Podcast episode listening with progress tracking
- Album artwork and track details blocks
- Auto-tagging by artist, album, and listening context
- PKCE-based OAuth authentication
- Historical data migration support
- Spotlight command integration

## Setup

### Prerequisites

- Spotify Developer account
- OAuth application configured in Spotify Developer Dashboard

### Configuration

1. Go to [Spotify Developer Dashboard](https://developer.spotify.com/dashboard)
2. Create a new application
3. Add redirect URI: `https://yourdomain.com/integrations/spotify/callback`
4. Note your Client ID and Client Secret

### Environment Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `SPOTIFY_CLIENT_ID` | OAuth client ID from Spotify | Yes |
| `SPOTIFY_CLIENT_SECRET` | OAuth client secret from Spotify | Yes |
| `SPOTIFY_REDIRECT_URI` | OAuth callback URL | Yes |

Add to your `.env` file:

```env
SPOTIFY_CLIENT_ID=your_spotify_client_id
SPOTIFY_CLIENT_SECRET=your_spotify_client_secret
SPOTIFY_REDIRECT_URI=https://yourdomain.com/integrations/spotify/callback
```

### OAuth Scopes

The integration requests these scopes:

- `user-read-currently-playing` - Currently playing track
- `user-read-recently-played` - Recently played tracks
- `user-read-email` - User email address
- `user-read-private` - User profile information

## Data Model

### Instance Types

| Type | Description |
|------|-------------|
| `listening` | Listening activity tracking |

### Action Types

| Action | Description | Value Unit |
|--------|-------------|------------|
| `listened_to` | A track or episode was listened to | `minutes` (podcasts) or null (tracks) |

### Block Types

| Block Type | Description |
|------------|-------------|
| `album_art` | Album cover artwork |
| `track_details` | Track metadata (name, artists, album, duration, popularity) |
| `artist` | Artist information and Spotify link |
| `track_info` | Detailed track metadata |
| `episode_art` | Podcast episode cover art |
| `episode_details` | Episode metadata (show, publisher, duration, release date) |

### Object Types

| Object Type | Description |
|-------------|-------------|
| `spotify_user` | Spotify user account |
| `spotify_track` | Spotify track |
| `spotify_podcast_episode` | Podcast episode on Spotify |

## Usage

### Connecting the Integration

1. Navigate to Integrations in Spark
2. Click "Connect" on Spotify integration
3. Authorize with Spotify (grants required permissions)
4. Configure settings (update frequency, auto-tagging options)

### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `update_frequency_minutes` | integer | 15 | Fetch interval (minimum 5 minutes) |
| `auto_tag_genres` | boolean | false | Auto-tag events with track genres |
| `auto_tag_artists` | boolean | false | Auto-tag events with artist names |
| `include_album_art` | boolean | true | Create blocks with album artwork |
| `track_podcasts` | boolean | true | Track podcast episode listening |
| `podcast_min_listen_minutes` | integer | 5 | Minimum minutes before creating podcast event |
| `podcast_session_timeout_hours` | integer | 4 | Hours of inactivity before new session |

### Podcast Tracking

When enabled, podcast listening creates events with:

- Progress tracking (updates as you listen)
- Minimum listen threshold (default 5 minutes)
- Session-based deduplication
- Show and publisher tags

### Manual Operations

```bash
# Fetch data for all Spotify integrations
sail artisan integrations:fetch --service=spotify

# Run scheduled fetch via scheduler
sail artisan spotify:schedule
```

### Spotlight Commands

| Command | Description |
|---------|-------------|
| Sync Recent Spotify Plays | Fetch latest listening history |
| View Spotify Listening Stats | See music trends and top artists |

## API Reference

### Endpoints Used

| Endpoint | Purpose |
|----------|---------|
| `GET /me/player/currently-playing` | Current playback state |
| `GET /me/player/recently-played` | Last 50 played tracks |
| `GET /me` | User profile information |
| `POST /api/token` | OAuth token exchange/refresh |

### Rate Limits

Spotify API has rate limits:

- Approximately 450 requests per 15 minutes per endpoint
- The integration respects these limits with minimum 5-minute fetch intervals
- Exponential backoff on rate limit errors

## Event Structure

### Track Listen Event

```json
{
  "source_id": "spotify_{track_id}_{played_at}",
  "time": "2024-01-15T10:30:00Z",
  "service": "spotify",
  "domain": "media",
  "action": "listened_to",
  "event_metadata": {
    "source": "recently_played",
    "progress_ms": 90000,
    "is_playing": false,
    "context_type": "playlist",
    "track_id": "track_123",
    "album_id": "album_123",
    "artist_ids": ["artist_123"]
  },
  "actor": {
    "concept": "user",
    "type": "spotify_user",
    "title": "User Name"
  },
  "target": {
    "concept": "track",
    "type": "spotify_track",
    "title": "Track Name"
  }
}
```

### Podcast Episode Event

```json
{
  "source_id": "spotify_podcast_{episode_id}_{date}",
  "time": "2024-01-15T10:30:00Z",
  "service": "spotify",
  "domain": "media",
  "action": "listened_to",
  "value": 25,
  "value_unit": "minutes",
  "event_metadata": {
    "media_type": "episode",
    "episode_id": "episode_123",
    "show_id": "show_123",
    "show_name": "Podcast Name",
    "duration_ms": 3600000,
    "progress_ms": 1500000,
    "max_progress_ms": 1500000
  }
}
```

## Auto-Tagging

Events are automatically tagged based on content:

### Track Tags

| Tag Type | Source |
|----------|--------|
| `music_artist` | Artist names |
| `music_album` | Album name |
| `spotify_context` | Listening context (album, playlist, etc.) |

### Podcast Tags

| Tag Type | Source |
|----------|--------|
| `podcast_show` | Podcast/show name |
| `podcast_publisher` | Publisher name |
| `spotify_context` | Always "podcast" |

## Error Handling

### Token Refresh

- Automatically refreshes expired access tokens using refresh token
- Handles token rotation when Spotify issues new refresh token
- Logs authentication failures for debugging

### API Failures

- Graceful handling of API errors
- Retry logic with exponential backoff
- Comprehensive error logging with sanitized data

### Duplicate Prevention

- Unique `source_id` prevents duplicate events (track_id + played_at timestamp)
- Race condition protection using `updateOrCreate`
- Podcast session-based deduplication

## Troubleshooting

### Common Issues

1. **OAuth Errors**
   - Verify redirect URI matches exactly: `https://yourdomain.com/integrations/spotify/callback`
   - Check client ID and secret are correct
   - Ensure all required scopes are configured

2. **No Events Created**
   - Check if user has played tracks recently
   - Verify access token is valid (check `expiry` in IntegrationGroup)
   - Confirm polling schedule is running

3. **Rate Limiting**
   - Increase `update_frequency_minutes` (minimum 5 minutes)
   - Check API usage in Spotify Developer Dashboard
   - Monitor error logs for 429 responses

4. **Missing Podcast Events**
   - Verify `track_podcasts` is enabled
   - Check `podcast_min_listen_minutes` threshold
   - Ensure currently-playing endpoint is being called

### Debug Commands

```bash
# Test Spotify connection
sail artisan tinker
>>> $integration = App\Models\Integration::where('service', 'spotify')->first();
>>> $plugin = new App\Integrations\Spotify\SpotifyPlugin();
>>> $plugin->fetchData($integration);

# Check recent events
>>> App\Models\Event::where('service', 'spotify')->latest()->take(5)->get();

# View integration status
>>> $integration->group->expiry;
>>> $integration->last_triggered_at;
```

## Migration Support

The Spotify integration supports historical data migration:

```php
// Process historical recently played items
$plugin = new SpotifyPlugin();
$plugin->processRecentlyPlayedMigrationItem($integration, $playedItem);

// Ensure token is fresh before migration
$plugin->ensureFreshToken($group);
```

> **Note**: Spotify API only provides the last 50 recently played tracks. Historical data beyond this is not available via the API.

## Related Documentation

- [INTEGRATION_PLUGINS.md](INTEGRATION_PLUGINS.md) - Plugin architecture
- [README_JOBS.md](README_JOBS.md) - Job system overview
- [API_DOCUMENTATION.md](API_DOCUMENTATION.md) - Spark API reference
