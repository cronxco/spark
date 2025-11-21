# Slack Integration

Sync events from Slack via webhook.

## Overview

The Slack integration receives events from Slack via webhook. It tracks messages, reactions, and file shares in your configured Slack workspace, creating events with user, channel, and content information.

## Features

- Webhook-based real-time event sync
- Track messages sent in channels
- Track emoji reactions added
- Track file shares
- Signature verification for security
- Configurable event types

## Setup

### Prerequisites

- A Slack workspace with admin access
- A Slack app with Event Subscriptions enabled

### Slack App Configuration

1. Go to [Slack API Apps](https://api.slack.com/apps)
2. Create a new app or select existing
3. Go to "Event Subscriptions"
4. Enable events
5. Set Request URL to your webhook endpoint
6. Subscribe to bot events:
   - `message.channels` - Messages in public channels
   - `reaction_added` - Emoji reactions
   - `file_shared` - File uploads

### Environment Variables

No environment variables required. Each integration generates its own signing secret.

### Webhook URL Format

```
POST /api/webhooks/slack/{secret}
```

The secret is generated when creating the integration and used for signature verification.

## Data Model

### Instance Types

| Type | Description |
|------|-------------|
| `events` | Slack event tracking |

### Action Types

| Action | Description | Value Unit |
|--------|-------------|------------|
| `sent` | Message sent in Slack | message |
| `added` | Reaction added to message | reaction |
| `shared` | File shared in Slack | bytes |

### Block Types

No custom block types. Event metadata contains all details.

### Object Types

| Object Type | Description |
|-------------|-------------|
| `slack_user` | A Slack user account |
| `slack_message` | A Slack message |
| `slack_reaction` | An emoji reaction |
| `slack_file` | A shared file |

## Usage

### Connecting Slack

1. Navigate to Integrations in Spark
2. Create a new Slack integration
3. Copy the webhook URL and secret
4. Configure your Slack app with the webhook URL
5. Set the signing secret in your Slack app settings

### Configuration Options

| Option | Type | Description |
|--------|------|-------------|
| `events` | array | Event types to track: `message`, `reaction_added`, `file_shared` |

### URL Verification

When Slack sends a URL verification challenge:

```json
{
  "type": "url_verification",
  "challenge": "challenge_token"
}
```

The integration handles this automatically.

## Webhook Payload Examples

### Message Event

```json
{
  "type": "event_callback",
  "event_id": "Ev12345",
  "team_id": "T12345",
  "event": {
    "type": "message",
    "user": "U12345",
    "text": "Hello world!",
    "channel": "C12345",
    "ts": "1234567890.123456"
  }
}
```

### Reaction Event

```json
{
  "type": "event_callback",
  "event_id": "Ev12345",
  "event": {
    "type": "reaction_added",
    "user": "U12345",
    "reaction": "thumbsup",
    "item": {
      "type": "message",
      "channel": "C12345",
      "ts": "1234567890.123456"
    },
    "event_ts": "1234567890.654321"
  }
}
```

### File Shared Event

```json
{
  "type": "event_callback",
  "event_id": "Ev12345",
  "event": {
    "type": "file_shared",
    "user_id": "U12345",
    "channel_id": "C12345",
    "file": {
      "id": "F12345",
      "name": "document.pdf",
      "title": "Important Document",
      "filetype": "pdf",
      "size": 123456,
      "permalink": "https://..."
    },
    "event_ts": "1234567890.123456"
  }
}
```

## Security

### Signature Verification

All webhook requests are verified using Slack's signing secret:

1. Slack sends `X-Slack-Signature` and `X-Slack-Request-Timestamp` headers
2. Signature is computed: `v0=HMAC-SHA256(v0:timestamp:body, signing_secret)`
3. Signatures must match using constant-time comparison
4. Timestamps older than 5 minutes are rejected

### Signing Secret

The integration's `account_id` stores the signing secret. This should match the signing secret from your Slack app settings.

## Event Structure

### Message Sent

```json
{
  "source_id": "slack_event_id",
  "time": "2024-01-15T10:30:00Z",
  "service": "slack",
  "domain": "online",
  "action": "sent",
  "value": 1,
  "value_unit": "message",
  "actor": {
    "type": "slack_user",
    "title": "U12345"
  },
  "target": {
    "type": "slack_message",
    "title": "Message in C12345"
  },
  "event_metadata": {
    "channel": "C12345",
    "subtype": null
  }
}
```

## Troubleshooting

### Common Issues

1. **URL Verification Failed**
   - Ensure your endpoint returns the challenge token
   - Check that the endpoint is publicly accessible
   - Verify SSL certificate is valid

2. **Invalid Signature**
   - Verify the signing secret matches your Slack app
   - Check that the request body hasn't been modified
   - Ensure timestamp is recent (within 5 minutes)

3. **Missing Events**
   - Verify event types are enabled in Slack app settings
   - Check that the bot is invited to relevant channels
   - Confirm event types are configured in integration

4. **Duplicate Events**
   - Slack may retry webhooks if no response received
   - Events are deduplicated by `event_id`

## Related Documentation

- [INTEGRATION_PLUGINS.md](INTEGRATION_PLUGINS.md) - Plugin architecture
- [README_JOBS.md](README_JOBS.md) - Job system overview
