# Spark API Documentation

REST API for managing events, objects, and blocks with secure authentication using Laravel Sanctum.

## Overview

The Spark API provides comprehensive event management capabilities for tracking activities from various integrations. All endpoints require Bearer token authentication and return JSON responses. The API follows RESTful conventions with standard HTTP methods and status codes.

**Base URL**: `https://yourdomain.com/api`

## Authentication

All API requests require Bearer token authentication via Laravel Sanctum.

### Creating Tokens

1. Navigate to `/settings/api-tokens` in the web interface
2. Create a new token with a descriptive name
3. Copy the generated token (displayed only once)

### Using Tokens

Include the token in the `Authorization` header:

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     https://yourdomain.com/api/events
```

### Token Management Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/tokens/create` | Create a new API token |
| `GET` | `/api/tokens` | List all tokens for authenticated user |
| `DELETE` | `/api/tokens/{id}` | Revoke a specific token |

**Create Token Request:**

```json
{
  "token_name": "My API Token"
}
```

**Create Token Response:**

```json
{
  "token": "1|abc123def456...",
  "token_name": "My API Token",
  "created_at": "2025-07-27T17:00:00.000000Z"
}
```

## Endpoints

### Events

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/events` | List events with optional filtering |
| `GET` | `/api/events/{id}` | Get a specific event |
| `POST` | `/api/events` | Create a new event |
| `PUT` | `/api/events/{id}` | Update an existing event |
| `DELETE` | `/api/events/{id}` | Delete an event |

**List Events Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `integration_id` | uuid | Filter by integration |
| `service` | string | Filter by service name |
| `domain` | string | Filter by domain |
| `action` | string | Filter by action |
| `from_date` | ISO 8601 | Filter events from this date |
| `to_date` | ISO 8601 | Filter events to this date |
| `per_page` | integer | Items per page (default: 15, max: 100) |

**Create Event Request:**

```json
{
  "actor": {
    "time": "2025-07-27T17:00:00.000000Z",
    "integration_id": "integration-uuid",
    "concept": "user",
    "type": "github_user",
    "title": "John Doe",
    "content": "Software Developer",
    "metadata": {},
    "url": "https://github.com/johndoe",
    "image_url": "https://github.com/johndoe.png"
  },
  "target": {
    "time": "2025-07-27T17:00:00.000000Z",
    "integration_id": "integration-uuid",
    "concept": "pull_request",
    "type": "github_pr",
    "title": "Add new feature",
    "content": "This PR adds a new feature",
    "metadata": {},
    "url": "https://github.com/user/repo/pull/123"
  },
  "event": {
    "source_id": "github-12345",
    "time": "2025-07-27T17:00:00.000000Z",
    "integration_id": "integration-uuid",
    "service": "github",
    "domain": "pull_request",
    "action": "opened",
    "value": 1000,
    "value_multiplier": 1,
    "value_unit": "lines",
    "event_metadata": {}
  },
  "blocks": [
    {
      "time": "2025-07-27T17:00:00.000000Z",
      "integration_id": "integration-uuid",
      "title": "Code Changes",
      "content": "+100 lines added",
      "value": 100,
      "value_unit": "lines"
    }
  ]
}
```

**Update Event Request (all fields optional):**

```json
{
  "source_id": "updated-source-id",
  "time": "2025-07-27T18:00:00.000000Z",
  "service": "github",
  "domain": "pull_request",
  "action": "merged",
  "value": 2000,
  "event_metadata": {}
}
```

## Response Format

### Event Object

```json
{
  "id": "uuid",
  "source_id": "string",
  "time": "datetime",
  "integration_id": "uuid",
  "actor_id": "uuid",
  "service": "string",
  "domain": "string",
  "action": "string",
  "value": "integer",
  "value_multiplier": "integer",
  "value_unit": "string",
  "event_metadata": "object",
  "target_id": "uuid",
  "created_at": "datetime",
  "updated_at": "datetime"
}
```

### Actor/Target Object (EventObject)

```json
{
  "id": "uuid",
  "time": "datetime",
  "integration_id": "uuid",
  "concept": "string",
  "type": "string",
  "title": "string",
  "content": "string",
  "metadata": "object",
  "url": "string",
  "image_url": "string",
  "created_at": "datetime",
  "updated_at": "datetime"
}
```

### Block Object

```json
{
  "id": "uuid",
  "event_id": "uuid",
  "time": "datetime",
  "integration_id": "uuid",
  "title": "string",
  "content": "string",
  "url": "string",
  "media_url": "string",
  "value": "integer",
  "value_multiplier": "integer",
  "value_unit": "string",
  "created_at": "datetime",
  "updated_at": "datetime"
}
```

### Paginated Response

```json
{
  "data": [],
  "current_page": 1,
  "per_page": 15,
  "total": 150,
  "last_page": 10,
  "from": 1,
  "to": 15
}
```

## Error Handling

### HTTP Status Codes

| Code | Description |
|------|-------------|
| `200` | Success |
| `201` | Created |
| `401` | Unauthenticated |
| `404` | Resource not found |
| `422` | Validation error |
| `429` | Rate limit exceeded |

### Error Response Format

**Authentication Error (401):**

```json
{
  "message": "Unauthenticated."
}
```

**Not Found Error (404):**

```json
{
  "message": "No query results for model [App\\Models\\Event]."
}
```

**Validation Error (422):**

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": [
      "The field name field is required."
    ]
  }
}
```

## Rate Limits

The API implements rate limiting to prevent abuse:

| Limit | Value |
|-------|-------|
| Default | 60 requests per minute |
| Burst | 120 requests per minute (short periods) |

**Response Headers:**

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1640995200
```

When rate limited, implement exponential backoff before retrying requests.

## Related Documentation

- [CLAUDE.md](/CLAUDE.md) - Architecture overview and plugin system
- [SPOTLIGHT.md](/docs/SPOTLIGHT.md) - Command palette documentation
- Web UI token management: `/settings/api-tokens`
