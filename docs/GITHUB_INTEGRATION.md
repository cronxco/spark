# GitHub Integration

Track GitHub repository activity including pushes, pull requests, and issues.

## Overview

The GitHub integration connects to your GitHub account via OAuth and tracks repository events. It monitors configured repositories for push events, pull request activity, and issue changes, creating events with detailed commit and PR information.

## Features

- OAuth authentication with PKCE security
- Track push events with commit details
- Monitor pull request creation, updates, and merges
- Track issue creation and updates
- Configurable repository selection
- Event filtering by type
- Support for historical data migration

## Setup

### Prerequisites

- A GitHub account
- A GitHub OAuth application (for self-hosted)

### GitHub OAuth App Configuration

1. Go to GitHub Settings > Developer Settings > OAuth Apps
2. Create a new OAuth application
3. Set the callback URL: `https://yourdomain.com/integrations/github/callback`
4. Note the Client ID and Client Secret

### Environment Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `GITHUB_CLIENT_ID` | OAuth application client ID | Yes |
| `GITHUB_CLIENT_SECRET` | OAuth application client secret | Yes |
| `GITHUB_REDIRECT_URI` | OAuth callback URL (optional, auto-generated) | No |

### Configuration

Add to your `.env` file:

```env
GITHUB_CLIENT_ID=your_client_id
GITHUB_CLIENT_SECRET=your_client_secret
```

## Data Model

### Instance Types

| Type | Description |
|------|-------------|
| `activity` | Repository activity tracking |

### Action Types

| Action | Description | Value Unit |
|--------|-------------|------------|
| `push` | Code pushed to repository | commits |

### Block Types

Blocks are created for individual commits in push events:

| Block Type | Description |
|------------|-------------|
| Commit block | Contains commit SHA (short), message, and URL |

### Object Types

| Object Type | Description |
|-------------|-------------|
| `github_user` | GitHub user account |
| `github_repo` | GitHub repository |
| `github_pr` | Pull request |
| `github_issue` | Issue |

## Usage

### Connecting GitHub

1. Navigate to Integrations in Spark
2. Click "Connect" on GitHub
3. Authorize the OAuth application on GitHub
4. Configure which repositories to track
5. Select event types to monitor

### Configuration Options

| Option | Type | Description |
|--------|------|-------------|
| `repositories` | array | List of repositories to track (format: `owner/repo`) |
| `events` | array | Event types to track: `push`, `pull_request`, `issue`, `commit_comment` |
| `update_frequency_minutes` | integer | How often to fetch new data (default: 15) |

### Manual Data Fetching

```bash
# Fetch data for all GitHub integrations
sail artisan integrations:fetch --service=github

# Trigger data pull for specific integration
sail artisan tinker
>>> App\Models\Integration::find('uuid')->fetchData()
```

## API Reference

### External APIs Used

| Endpoint | Purpose |
|----------|---------|
| `GET /repos/{owner}/{repo}/events` | Fetch repository events |
| `GET /user` | Get authenticated user info |

### Rate Limits

GitHub API has rate limits:

- **Authenticated requests**: 5,000 per hour
- **Events API**: Returns up to 300 events, last 90 days

The integration respects these limits and uses the configured update frequency to avoid excessive requests.

### OAuth Scopes

Required OAuth scopes:

- `repo` - Full control of private repositories
- `read:user` - Read user profile data

## Event Structure

### Push Event

```json
{
  "source_id": "github_event_id",
  "time": "2024-01-15T10:30:00Z",
  "service": "github",
  "domain": "online",
  "action": "push",
  "value": 3,
  "value_unit": "commits",
  "actor": {
    "type": "github_user",
    "title": "username"
  },
  "target": {
    "type": "github_repo",
    "title": "owner/repo"
  },
  "event_metadata": {
    "ref": "refs/heads/main",
    "before": "abc123...",
    "after": "def456..."
  }
}
```

## Troubleshooting

### Common Issues

1. **OAuth Authorization Failed**
   - Verify Client ID and Secret are correct
   - Check that the callback URL matches exactly
   - Ensure the OAuth app has the required scopes

2. **No Events Appearing**
   - Confirm repositories are correctly configured (use `owner/repo` format)
   - Check that there has been recent activity
   - Verify the event types are selected in configuration

3. **Rate Limiting**
   - Reduce update frequency
   - Check remaining rate limit in GitHub API response headers

## Related Documentation

- [INTEGRATION_PLUGINS.md](INTEGRATION_PLUGINS.md) - Plugin architecture
- [API_DOCUMENTATION.md](API_DOCUMENTATION.md) - Spark API reference
