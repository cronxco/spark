# Spotify Integration

The Spotify integration allows users to connect their Spotify accounts and automatically create events whenever they listen to tracks. This integration leverages Spark's event system, blocks, and tags to provide rich, structured data about listening activity.

## Features

### 🎵 **Real-time Track Monitoring**

- Polls Spotify API every 30 seconds for currently playing tracks
- Tracks recently played songs (last 50 tracks)
- Creates events for each unique track listening
- Prevents duplicate events for the same listen

### 🏷️ **Automatic Tagging**

- **Artist tags** (type `music_artist`): Performing artist names
- **Album tag** (type `music_album`): Album name
- **Listening context** (type `spotify_context`): Source context such as `album`, `playlist`, etc.

### 📦 **Rich Content Blocks**

- **Album Art**: High-quality album artwork with metadata
- **Track Details**: Comprehensive track information including duration, popularity
- **Artist Info**: Artist details and Spotify links

### ⚙️ **Configurable Settings**

- **Polling Interval**: Adjustable from 30 seconds to 5 minutes
- **Auto-tagging**: Enable/disable automatic tagging features
- **Album Art**: Toggle album artwork inclusion

## Architecture

### OAuth Callback URL Consistency

The Spotify integration uses the same OAuth callback URL pattern as other integrations in the application:

- **URL Pattern**: `/integrations/{service}/callback`
- **Spotify URL**: `/integrations/spotify/callback`
- **GitHub URL**: `/integrations/github/callback`

This ensures:

- **Consistent routing** across all OAuth providers
- **Simplified configuration** in external service dashboards
- **Easier maintenance** and debugging
- **Unified authentication flow** for all integrations

### Event Structure

Each track listen creates a structured event with:

- **Actor**: Spotify User (the listener)
- **Target**: Track being listened to
- **Event**: Listening event with timestamp
- **Blocks**: Rich content blocks (album art, track details, artist info)
- **Tags**: Simplified typed tags (artist, album, context)

### Data Flow

1. **OAuth Authentication**: User connects Spotify account
2. **Background Polling**: System polls Spotify API every 30 seconds
3. **Event Creation**: New track plays create events with rich metadata
4. **Block Generation**: Album art and track details create content blocks
5. **Auto-tagging**: Events are automatically tagged for categorization

## Setup Instructions

### 1. Spotify Developer Setup

1. Go to [Spotify Developer Dashboard](https://developer.spotify.com/dashboard)
2. Create a new application
3. Add redirect URI: `https://yourdomain.com/integrations/spotify/callback`
4. Note your Client ID and Client Secret

### 2. Environment Configuration

Add these variables to your `.env` file:

```env
SPOTIFY_CLIENT_ID=your_spotify_client_id
SPOTIFY_CLIENT_SECRET=your_spotify_client_secret
SPOTIFY_REDIRECT_URI=https://yourdomain.com/integrations/spotify/callback
```

**Note**: The callback URL follows the same pattern as other integrations: `/integrations/{service}/callback`. This ensures consistency across all OAuth providers in the application.

### 3. Database Migration

The integration uses existing database tables:

- `integrations` - Stores OAuth tokens and configuration
- `events` - Stores track play events
- `objects` - Stores user and track objects
- `blocks` - Stores album art and track details
- `tags` - Stores automatic categorization tags

### 4. Queue Configuration

For optimal performance, configure your queue system:

```env
QUEUE_CONNECTION=redis
```

## Usage

### Connecting Spotify Account

1. Navigate to Integrations page
2. Click "Connect" on Spotify integration
3. Authorize with Spotify (grants required permissions)
4. Configure settings (polling interval, auto-tagging, etc.)

### Manual Data Fetching

```bash
# Fetch data for all Spotify integrations
php artisan spotify:fetch

# Fetch data for specific user
php artisan spotify:fetch --user=123

# Force fetch regardless of timing
php artisan spotify:fetch --force
```

### Scheduled Fetching

Add to your `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    // Run every 30 seconds for real-time updates
    $schedule->command('spotify:schedule')->everyThirtySeconds();

    // Or use the general integration command
    $schedule->command('integrations:fetch --service=spotify')->everyMinute();
}
```

### Background Processing

The integration supports background job processing:

```php
// Dispatch a job to process Spotify data
ProcessSpotifyData::dispatch($integration);
```

## API Endpoints

### Event Data

All Spotify events are available through the standard Event API:

```bash
# Get all Spotify events
GET /api/events?service=spotify

# Get events for specific user
GET /api/events?integration_id=uuid

# Filter by tags
GET /api/events?tags[]=artist_name&tags[]=album_name
```

### Event Structure

```json
{
    "id": "event-uuid",
    "source_id": "spotify_track_123_2023-12-01T10:30:00Z",
    "time": "2023-12-01T10:30:00Z",
    "service": "spotify",
    "domain": "music",
    "action": "listened_to",
    "event_metadata": {
        "source": "currently_playing",
        "progress_ms": 90000,
        "is_playing": true,
        "track_id": "track_123",
        "album_id": "album_123",
        "artist_ids": ["artist_123"]
    },
    "actor": {
        "id": "user-uuid",
        "concept": "user",
        "type": "spotify_user",
        "title": "John Doe",
        "content": "Spotify user account"
    },
    "target": {
        "id": "track-uuid",
        "concept": "track",
        "type": "spotify_track",
        "title": "Bohemian Rhapsody",
        "content": "Track: Bohemian Rhapsody\nArtist: Queen\nAlbum: A Night at the Opera"
    },
    "blocks": [
        {
            "title": "Album Art",
            "content": "Album artwork for A Night at the Opera",
            "media_url": "https://i.scdn.co/image/ab67616d0000b273...",
            "value": 300,
            "value_unit": "pixels"
        },
        {
            "title": "Track Details",
            "content": "**Track:** Bohemian Rhapsody\n**Artist:** Queen\n**Album:** A Night at the Opera\n**Duration:** 05:55\n**Popularity:** 95/100",
            "value": 95,
            "value_unit": "popularity"
        }
    ],
    "tags": [
        { "name": "Queen", "slug": "queen", "type": "music_artist" },
        {
            "name": "A Night at the Opera",
            "slug": "a-night-at-the-opera",
            "type": "music_album"
        },
        { "name": "playlist", "slug": "playlist", "type": "spotify_context" }
    ]
}
```

## Configuration Options

### Update Frequency

- **1 minute**: Real-time updates (default, minimum)
- **5 minutes**: Balanced performance
- **15 minutes**: Conservative rate limiting
- **30 minutes**: Minimal API usage

### Auto-tagging Features

- **Artist tags**: Tag events with artist names (configurable)
- **Album tags**: Tag events with album names (always enabled)
- **Year/Decade tags**: Tag events with release year and decade
- **Popularity tags**: Tag events based on track popularity
- **Explicit content**: Tag explicit tracks

### Content Options

- **Include Album Art**: Create blocks with album artwork (configurable)
- **Track Details**: Include comprehensive track information (always included)
- **Artist Info**: Include artist details and links (always included)

### Configuration Interface

The integration provides a user-friendly configuration interface where you can:

- Set the update frequency in minutes
- Enable/disable artist tagging
- Enable/disable album art inclusion
- All settings are saved automatically and applied immediately

## Rate Limiting

Spotify API has rate limits:

- **Currently Playing**: 450 requests per 15 minutes
- **Recently Played**: 450 requests per 15 minutes
- **User Profile**: 450 requests per 15 minutes

The integration respects these limits and includes exponential backoff for retries.

## Error Handling

### Token Refresh

- Automatically refreshes expired access tokens
- Handles refresh token rotation
- Logs authentication failures

### API Failures

- Graceful handling of API errors
- Retry logic with exponential backoff
- Comprehensive error logging

### Duplicate Prevention

- Unique source IDs prevent duplicate events
- Checks existing events before creation
- Handles edge cases in timing

## Monitoring

### Logs

The integration logs all activities:

- Authentication events
- API requests and responses
- Event creation
- Error conditions

### Metrics

Track integration health:

- Events created per day
- API request success rate
- Token refresh frequency
- Error rates

## Troubleshooting

### Common Issues

1. **OAuth Errors**
    - Verify redirect URI matches exactly: `https://yourdomain.com/integrations/spotify/callback`
    - Check client ID and secret
    - Ensure scopes are properly configured
    - Confirm the callback URL follows the consistent pattern: `/integrations/spotify/callback`

2. **No Events Created**
    - Check if user has played tracks recently
    - Verify access token is valid
    - Check polling schedule is running

3. **Rate Limiting**
    - Reduce polling frequency
    - Check API usage in Spotify dashboard
    - Monitor error logs

### Debug Commands

```bash
# Test Spotify connection
php artisan spotify:fetch --user=123 --force

# Check integration status
php artisan tinker
>>> App\Models\Integration::where('service', 'spotify')->get()

# View recent events
php artisan tinker
>>> App\Models\Event::where('service', 'spotify')->latest()->take(5)->get()
```

## Future Enhancements

### Planned Features

- **Genre Analysis**: Fetch and tag by track genres
- **Playlist Integration**: Track playlist additions
- **Listening Analytics**: Generate listening insights
- **Social Features**: Share listening activity
- **Recommendations**: Suggest similar tracks

### API Improvements

- **Webhook Support**: Real-time notifications (if Spotify adds support)
- **Batch Processing**: Process multiple tracks efficiently
- **Caching**: Cache track metadata to reduce API calls

## Contributing

To contribute to the Spotify integration:

1. Fork the repository
2. Create a feature branch
3. Add tests for new functionality
4. Ensure all tests pass
5. Submit a pull request

## Support

For issues with the Spotify integration:

1. Check the troubleshooting section
2. Review error logs
3. Verify Spotify API status
4. Create an issue with detailed information
