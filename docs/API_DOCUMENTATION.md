# Spark API Documentation

## Overview

The Spark API provides comprehensive event management capabilities with secure authentication using Laravel Sanctum. All endpoints require authentication unless otherwise specified.

**Base URL**: `https://yourdomain.com/api`

**Authentication**: Bearer token authentication using Laravel Sanctum

## Authentication
### OAuth/Webhook Integration Flow (Web UI)

- OAuth: `/integrations/{service}/oauth` -> provider -> `/integrations/{service}/callback`
  - Creates an `IntegrationGroup` and stores tokens
  - Redirects to `/integrations/groups/{group}/onboarding` to create one or more instances
  - Each instance can later be configured via `/integrations/{integration}/configure`


### Getting Started

1. **Create an API Token** (via web interface):
   - Navigate to `/settings/api-tokens` in your browser
   - Create a new token with a descriptive name
   - Copy the generated token (it won't be shown again)

2. **Use the Token**:
   ```bash
   curl -H "Authorization: Bearer YOUR_TOKEN" \
        -H "Content-Type: application/json" \
        https://yourdomain.com/api/events
   ```

## API Token Management

### Create API Token

**Endpoint**: `POST /api/tokens/create`

**Description**: Generate a new API token for the authenticated user.

**Headers**:
- `Authorization: Bearer {token}` (required)
- `Content-Type: application/json`

**Request Body**:
```json
{
  "token_name": "My API Token"
}
```

**Response** (200):
```json
{
  "token": "1|abc123def456...",
  "token_name": "My API Token",
  "created_at": "2025-07-27T17:00:00.000000Z"
}
```

**Example**:
```bash
curl -X POST https://yourdomain.com/api/tokens/create \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"token_name": "GitHub Integration Token"}'
```

### List API Tokens

**Endpoint**: `GET /api/tokens`

**Description**: Retrieve all API tokens for the authenticated user.

**Headers**:
- `Authorization: Bearer {token}` (required)

**Response** (200):
```json
{
  "tokens": [
    {
      "id": 1,
      "name": "GitHub Integration Token",
      "created_at": "2025-07-27T17:00:00.000000Z",
      "last_used_at": "2025-07-27T18:30:00.000000Z"
    },
    {
      "id": 2,
      "name": "Mobile App Token",
      "created_at": "2025-07-26T10:00:00.000000Z",
      "last_used_at": null
    }
  ]
}
```

**Example**:
```bash
curl -X GET https://yourdomain.com/api/tokens \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Revoke API Token

**Endpoint**: `DELETE /api/tokens/{token_id}`

**Description**: Permanently delete an API token.

**Headers**:
- `Authorization: Bearer {token}` (required)

**Response** (200):
```json
{
  "message": "Token revoked successfully"
}
```

**Response** (404):
```json
{
  "error": "Token not found"
}
```

**Example**:
```bash
curl -X DELETE https://yourdomain.com/api/tokens/1 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## Events API

### List Events

**Endpoint**: `GET /api/events`

**Description**: Retrieve a paginated list of events with optional filtering.

**Headers**:
- `Authorization: Bearer {token}` (required)

**Query Parameters**:
- `integration_id` (optional): Filter by integration ID
- `service` (optional): Filter by service name
- `domain` (optional): Filter by domain
- `action` (optional): Filter by action
- `from_date` (optional): Filter events from this date (ISO 8601 format)
- `to_date` (optional): Filter events to this date (ISO 8601 format)
- `per_page` (optional): Number of items per page (default: 15, max: 100)

**Response** (200):
```json
{
  "data": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "source_id": "github-12345",
      "time": "2025-07-27T17:00:00.000000Z",
      "integration_id": "integration-uuid",
      "actor_id": "actor-uuid",
      "actor_metadata": {},
      "service": "github",
      "domain": "pull_request",
      "action": "opened",
      "value": 1000,
      "value_multiplier": 1,
      "value_unit": "lines",
      "event_metadata": {
        "repository": "user/repo",
        "branch": "main"
      },
      "target_id": "target-uuid",
      "target_metadata": {},
      "embeddings": "vector-data",
      "created_at": "2025-07-27T17:00:00.000000Z",
      "updated_at": "2025-07-27T17:00:00.000000Z",
      "actor": {
        "id": "actor-uuid",
        "time": "2025-07-27T17:00:00.000000Z",
        "integration_id": "integration-uuid",
        "concept": "user",
        "type": "github_user",
        "title": "John Doe",
        "content": "Software Developer",
        "metadata": {},
        "url": "https://github.com/johndoe",
        "image_url": "https://github.com/johndoe.png",
        "embeddings": [0.1, 0.2, 0.3]
      },
      "target": {
        "id": "target-uuid",
        "time": "2025-07-27T17:00:00.000000Z",
        "integration_id": "integration-uuid",
        "concept": "pull_request",
        "type": "github_pr",
        "title": "Add new feature",
        "content": "This PR adds a new feature...",
        "metadata": {},
        "url": "https://github.com/user/repo/pull/123",
        "image_url": null,
        "embeddings": [0.4, 0.5, 0.6]
      },
      "blocks": [
        {
          "id": "block-uuid",
          "event_id": "550e8400-e29b-41d4-a716-446655440000",
          "time": "2025-07-27T17:00:00.000000Z",
          "integration_id": "integration-uuid",
          "title": "Code Changes",
          "content": "+100 lines added, -50 lines removed",
          "url": "https://github.com/user/repo/pull/123/files",
          "media_url": null,
          "value": 100,
          "value_multiplier": 1,
          "value_unit": "lines",
          "embeddings": "vector-data"
        }
      ],
      "integration": {
        "id": "integration-uuid",
        "user_id": "user-uuid",
        "service": "github",
        "name": "GitHub Integration",
        "account_id": "github-account",
        "access_token": "ghp_...",
        "refresh_token": "ghr_...",
        "expiry": "2025-08-27T17:00:00.000000Z",
        "refresh_expiry": "2025-09-27T17:00:00.000000Z"
      }
    }
  ],
  "current_page": 1,
  "per_page": 15,
  "total": 150,
  "last_page": 10,
  "from": 1,
  "to": 15
}
```

**Examples**:

List all events:
```bash
curl -X GET https://yourdomain.com/api/events \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Filter by service:
```bash
curl -X GET "https://yourdomain.com/api/events?service=github" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Filter by date range:
```bash
curl -X GET "https://yourdomain.com/api/events?from_date=2025-07-01&to_date=2025-07-31" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Filter by multiple criteria:
```bash
curl -X GET "https://yourdomain.com/api/events?service=github&domain=pull_request&action=opened&per_page=10" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Get Specific Event

**Endpoint**: `GET /api/events/{event_id}`

**Description**: Retrieve a specific event by ID.

**Headers**:
- `Authorization: Bearer {token}` (required)

**Response** (200):
```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "source_id": "github-12345",
  "time": "2025-07-27T17:00:00.000000Z",
  "integration_id": "integration-uuid",
  "actor_id": "actor-uuid",
  "actor_metadata": {},
  "service": "github",
  "domain": "pull_request",
  "action": "opened",
  "value": 1000,
  "value_multiplier": 1,
  "value_unit": "lines",
  "event_metadata": {
    "repository": "user/repo",
    "branch": "main"
  },
  "target_id": "target-uuid",
  "target_metadata": {},
  "embeddings": "vector-data",
  "created_at": "2025-07-27T17:00:00.000000Z",
  "updated_at": "2025-07-27T17:00:00.000000Z",
  "actor": {
    "id": "actor-uuid",
    "time": "2025-07-27T17:00:00.000000Z",
    "integration_id": "integration-uuid",
    "concept": "user",
    "type": "github_user",
    "title": "John Doe",
    "content": "Software Developer",
    "metadata": {},
    "url": "https://github.com/johndoe",
    "image_url": "https://github.com/johndoe.png",
    "embeddings": [0.1, 0.2, 0.3]
  },
  "target": {
    "id": "target-uuid",
    "time": "2025-07-27T17:00:00.000000Z",
    "integration_id": "integration-uuid",
    "concept": "pull_request",
    "type": "github_pr",
    "title": "Add new feature",
    "content": "This PR adds a new feature...",
    "metadata": {},
    "url": "https://github.com/user/repo/pull/123",
    "image_url": null,
    "embeddings": [0.4, 0.5, 0.6]
  },
  "blocks": [
    {
      "id": "block-uuid",
      "event_id": "550e8400-e29b-41d4-a716-446655440000",
      "time": "2025-07-27T17:00:00.000000Z",
      "integration_id": "integration-uuid",
      "title": "Code Changes",
      "content": "+100 lines added, -50 lines removed",
      "url": "https://github.com/user/repo/pull/123/files",
      "media_url": null,
      "value": 100,
      "value_multiplier": 1,
      "value_unit": "lines",
      "embeddings": "vector-data"
    }
  ],
  "integration": {
    "id": "integration-uuid",
    "user_id": "user-uuid",
    "service": "github",
    "name": "GitHub Integration",
    "account_id": "github-account",
    "access_token": "ghp_...",
    "refresh_token": "ghr_...",
    "expiry": "2025-08-27T17:00:00.000000Z",
    "refresh_expiry": "2025-09-27T17:00:00.000000Z"
  }
}
```

**Response** (404):
```json
{
  "message": "No query results for model [App\\Models\\Event]."
}
```

**Example**:
```bash
curl -X GET https://yourdomain.com/api/events/550e8400-e29b-41d4-a716-446655440000 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Create Event

**Endpoint**: `POST /api/events`

**Description**: Create a new event with associated actor, target, and optional blocks.

**Headers**:
- `Authorization: Bearer {token}` (required)
- `Content-Type: application/json`

**Request Body**:
```json
{
  "actor": {
    "time": "2025-07-27T17:00:00.000000Z",
    "integration_id": "integration-uuid",
    "concept": "user",
    "type": "github_user",
    "title": "John Doe",
    "content": "Software Developer",
    "metadata": {
      "github_id": 12345,
      "username": "johndoe"
    },
    "url": "https://github.com/johndoe",
    "image_url": "https://github.com/johndoe.png",
    "embeddings": [0.1, 0.2, 0.3]
  },
  "target": {
    "time": "2025-07-27T17:00:00.000000Z",
    "integration_id": "integration-uuid",
    "concept": "pull_request",
    "type": "github_pr",
    "title": "Add new feature",
    "content": "This PR adds a new feature to improve performance",
    "metadata": {
      "repository": "user/repo",
      "branch": "main",
      "pr_number": 123
    },
    "url": "https://github.com/user/repo/pull/123",
    "image_url": null,
    "embeddings": [0.4, 0.5, 0.6]
  },
  "event": {
    "source_id": "github-12345",
    "time": "2025-07-27T17:00:00.000000Z",
    "integration_id": "integration-uuid",
    "actor_metadata": {
      "github_id": 12345,
      "username": "johndoe"
    },
    "service": "github",
    "domain": "pull_request",
    "action": "opened",
    "value": 1000,
    "value_multiplier": 1,
    "value_unit": "lines",
    "event_metadata": {
      "repository": "user/repo",
      "branch": "main",
      "commit_sha": "abc123"
    },
    "target_metadata": {
      "repository": "user/repo",
      "branch": "main",
      "pr_number": 123
    },
    "embeddings": "vector-data"
  },
  "blocks": [
    {
      "time": "2025-07-27T17:00:00.000000Z",
      "integration_id": "integration-uuid",
      "title": "Code Changes",
      "content": "+100 lines added, -50 lines removed",
      "url": "https://github.com/user/repo/pull/123/files",
      "media_url": null,
      "value": 100,
      "value_multiplier": 1,
      "value_unit": "lines",
      "embeddings": "vector-data"
    },
    {
      "time": "2025-07-27T17:00:00.000000Z",
      "integration_id": "integration-uuid",
      "title": "Review Comments",
      "content": "3 comments added",
      "url": "https://github.com/user/repo/pull/123",
      "media_url": null,
      "value": 3,
      "value_multiplier": 1,
      "value_unit": "comments",
      "embeddings": "vector-data"
    }
  ]
}
```

**Response** (201):
```json
{
  "event": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "source_id": "github-12345",
    "time": "2025-07-27T17:00:00.000000Z",
    "integration_id": "integration-uuid",
    "actor_id": "actor-uuid",
    "actor_metadata": {
      "github_id": 12345,
      "username": "johndoe"
    },
    "service": "github",
    "domain": "pull_request",
    "action": "opened",
    "value": 1000,
    "value_multiplier": 1,
    "value_unit": "lines",
    "event_metadata": {
      "repository": "user/repo",
      "branch": "main",
      "commit_sha": "abc123"
    },
    "target_id": "target-uuid",
    "target_metadata": {
      "repository": "user/repo",
      "branch": "main",
      "pr_number": 123
    },
    "embeddings": "vector-data",
    "created_at": "2025-07-27T17:00:00.000000Z",
    "updated_at": "2025-07-27T17:00:00.000000Z"
  },
  "actor": {
    "id": "actor-uuid",
    "time": "2025-07-27T17:00:00.000000Z",
    "integration_id": "integration-uuid",
    "concept": "user",
    "type": "github_user",
    "title": "John Doe",
    "content": "Software Developer",
    "metadata": {
      "github_id": 12345,
      "username": "johndoe"
    },
    "url": "https://github.com/johndoe",
    "image_url": "https://github.com/johndoe.png",
    "embeddings": [0.1, 0.2, 0.3],
    "created_at": "2025-07-27T17:00:00.000000Z",
    "updated_at": "2025-07-27T17:00:00.000000Z"
  },
  "target": {
    "id": "target-uuid",
    "time": "2025-07-27T17:00:00.000000Z",
    "integration_id": "integration-uuid",
    "concept": "pull_request",
    "type": "github_pr",
    "title": "Add new feature",
    "content": "This PR adds a new feature to improve performance",
    "metadata": {
      "repository": "user/repo",
      "branch": "main",
      "pr_number": 123
    },
    "url": "https://github.com/user/repo/pull/123",
    "image_url": null,
    "embeddings": [0.4, 0.5, 0.6],
    "created_at": "2025-07-27T17:00:00.000000Z",
    "updated_at": "2025-07-27T17:00:00.000000Z"
  },
  "blocks": [
    {
      "id": "block-uuid-1",
      "event_id": "550e8400-e29b-41d4-a716-446655440000",
      "time": "2025-07-27T17:00:00.000000Z",
      "integration_id": "integration-uuid",
      "title": "Code Changes",
      "content": "+100 lines added, -50 lines removed",
      "url": "https://github.com/user/repo/pull/123/files",
      "media_url": null,
      "value": 100,
      "value_multiplier": 1,
      "value_unit": "lines",
      "embeddings": "vector-data",
      "created_at": "2025-07-27T17:00:00.000000Z",
      "updated_at": "2025-07-27T17:00:00.000000Z"
    },
    {
      "id": "block-uuid-2",
      "event_id": "550e8400-e29b-41d4-a716-446655440000",
      "time": "2025-07-27T17:00:00.000000Z",
      "integration_id": "integration-uuid",
      "title": "Review Comments",
      "content": "3 comments added",
      "url": "https://github.com/user/repo/pull/123",
      "media_url": null,
      "value": 3,
      "value_multiplier": 1,
      "value_unit": "comments",
      "embeddings": "vector-data",
      "created_at": "2025-07-27T17:00:00.000000Z",
      "updated_at": "2025-07-27T17:00:00.000000Z"
    }
  ]
}
```

**Response** (422) - Validation Error:
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "actor": ["The actor field is required."],
    "target": ["The target field is required."],
    "event": ["The event field is required."]
  }
}
```

**Example**:
```bash
curl -X POST https://yourdomain.com/api/events \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "actor": {
      "time": "2025-07-27T17:00:00.000000Z",
      "integration_id": "integration-uuid",
      "concept": "user",
      "type": "github_user",
      "title": "John Doe",
      "content": "Software Developer",
      "metadata": {},
      "url": "https://github.com/johndoe",
      "image_url": "https://github.com/johndoe.png",
      "embeddings": [0.1, 0.2, 0.3]
    },
    "target": {
      "time": "2025-07-27T17:00:00.000000Z",
      "integration_id": "integration-uuid",
      "concept": "pull_request",
      "type": "github_pr",
      "title": "Add new feature",
      "content": "This PR adds a new feature",
      "metadata": {},
      "url": "https://github.com/user/repo/pull/123",
      "image_url": null,
      "embeddings": [0.4, 0.5, 0.6]
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
      "event_metadata": {},
      "target_metadata": {},
      "embeddings": "vector-data"
    }
  }'
```

### Update Event

**Endpoint**: `PUT /api/events/{event_id}`

**Description**: Update specific fields of an existing event.

**Headers**:
- `Authorization: Bearer {token}` (required)
- `Content-Type: application/json`

**Request Body** (all fields optional):
```json
{
  "source_id": "updated-source-id",
  "time": "2025-07-27T18:00:00.000000Z",
  "service": "updated-service",
  "domain": "updated-domain",
  "action": "updated-action",
  "value": 2000,
  "value_multiplier": 2,
  "value_unit": "characters",
  "event_metadata": {
    "updated": true,
    "reason": "data correction"
  },
  "embeddings": "updated-vector-data"
}
```

**Response** (200):
```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "source_id": "updated-source-id",
  "time": "2025-07-27T18:00:00.000000Z",
  "integration_id": "integration-uuid",
  "actor_id": "actor-uuid",
  "actor_metadata": {},
  "service": "updated-service",
  "domain": "updated-domain",
  "action": "updated-action",
  "value": 2000,
  "value_multiplier": 2,
  "value_unit": "characters",
  "event_metadata": {
    "updated": true,
    "reason": "data correction"
  },
  "target_id": "target-uuid",
  "target_metadata": {},
  "embeddings": "updated-vector-data",
  "created_at": "2025-07-27T17:00:00.000000Z",
  "updated_at": "2025-07-27T18:00:00.000000Z",
  "actor": {
    "id": "actor-uuid",
    "time": "2025-07-27T17:00:00.000000Z",
    "integration_id": "integration-uuid",
    "concept": "user",
    "type": "github_user",
    "title": "John Doe",
    "content": "Software Developer",
    "metadata": {},
    "url": "https://github.com/johndoe",
    "image_url": "https://github.com/johndoe.png",
    "embeddings": [0.1, 0.2, 0.3]
  },
  "target": {
    "id": "target-uuid",
    "time": "2025-07-27T17:00:00.000000Z",
    "integration_id": "integration-uuid",
    "concept": "pull_request",
    "type": "github_pr",
    "title": "Add new feature",
    "content": "This PR adds a new feature",
    "metadata": {},
    "url": "https://github.com/user/repo/pull/123",
    "image_url": null,
    "embeddings": [0.4, 0.5, 0.6]
  },
  "blocks": [
    {
      "id": "block-uuid",
      "event_id": "550e8400-e29b-41d4-a716-446655440000",
      "time": "2025-07-27T17:00:00.000000Z",
      "integration_id": "integration-uuid",
      "title": "Code Changes",
      "content": "+100 lines added, -50 lines removed",
      "url": "https://github.com/user/repo/pull/123/files",
      "media_url": null,
      "value": 100,
      "value_multiplier": 1,
      "value_unit": "lines",
      "embeddings": "vector-data"
    }
  ],
  "integration": {
    "id": "integration-uuid",
    "user_id": "user-uuid",
    "service": "github",
    "name": "GitHub Integration",
    "account_id": "github-account",
    "access_token": "ghp_...",
    "refresh_token": "ghr_...",
    "expiry": "2025-08-27T17:00:00.000000Z",
    "refresh_expiry": "2025-09-27T17:00:00.000000Z"
  }
}
```

**Response** (404):
```json
{
  "message": "No query results for model [App\\Models\\Event]."
}
```

**Example**:
```bash
curl -X PUT https://yourdomain.com/api/events/550e8400-e29b-41d4-a716-446655440000 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "service": "updated-service",
    "action": "updated-action",
    "value": 2000
  }'
```

### Delete Event

**Endpoint**: `DELETE /api/events/{event_id}`

**Description**: Permanently delete an event and its associated blocks.

**Headers**:
- `Authorization: Bearer {token}` (required)

**Response** (200):
```json
{
  "message": "Event deleted successfully"
}
```

**Response** (404):
```json
{
  "message": "No query results for model [App\\Models\\Event]."
}
```

**Example**:
```bash
curl -X DELETE https://yourdomain.com/api/events/550e8400-e29b-41d4-a716-446655440000 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## Error Responses

### Authentication Errors

**401 Unauthorized**:
```json
{
  "message": "Unauthenticated."
}
```

### Validation Errors

**422 Unprocessable Entity**:
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": [
      "The field name field is required.",
      "The field name must be a string."
    ]
  }
}
```

### Not Found Errors

**404 Not Found**:
```json
{
  "message": "No query results for model [App\\Models\\Event]."
}
```

## Rate Limiting

The API implements rate limiting to prevent abuse:

- **Default**: 60 requests per minute per user
- **Burst**: Up to 120 requests per minute for short periods
- **Headers**: Rate limit information is included in response headers

**Rate Limit Headers**:
```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1640995200
```

## Data Models

### Event Object

```json
{
  "id": "uuid",
  "source_id": "string",
  "time": "datetime",
  "integration_id": "uuid",
  "actor_id": "uuid",
  "actor_metadata": "object",
  "service": "string",
  "domain": "string",
  "action": "string",
  "value": "integer",
  "value_multiplier": "integer",
  "value_unit": "string",
  "event_metadata": "object",
  "target_id": "uuid",
  "target_metadata": "object",
  "embeddings": "string",
  "created_at": "datetime",
  "updated_at": "datetime"
}
```

### Actor/Target Object

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
  "embeddings": "array",
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
  "embeddings": "string",
  "created_at": "datetime",
  "updated_at": "datetime"
}
```

## Best Practices

### Authentication
- Always use HTTPS in production
- Store tokens securely
- Rotate tokens regularly
- Use descriptive token names

### Error Handling
- Always check response status codes
- Handle rate limiting gracefully
- Implement exponential backoff for retries
- Log errors for debugging

### Data Validation
- Validate all input data before sending
- Use appropriate data types
- Handle optional fields properly
- Sanitize user input

### Performance
- Use pagination for large datasets
- Implement caching where appropriate
- Minimize request payload size
- Use appropriate HTTP methods

## SDK Examples

### JavaScript/Node.js

```javascript
const axios = require('axios');

class SparkAPI {
  constructor(baseURL, token) {
    this.client = axios.create({
      baseURL,
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json'
      }
    });
  }

  async listEvents(params = {}) {
    const response = await this.client.get('/api/events', { params });
    return response.data;
  }

  async getEvent(eventId) {
    const response = await this.client.get(`/api/events/${eventId}`);
    return response.data;
  }

  async createEvent(eventData) {
    const response = await this.client.post('/api/events', eventData);
    return response.data;
  }

  async updateEvent(eventId, updates) {
    const response = await this.client.put(`/api/events/${eventId}`, updates);
    return response.data;
  }

  async deleteEvent(eventId) {
    const response = await this.client.delete(`/api/events/${eventId}`);
    return response.data;
  }

  async createToken(tokenName) {
    const response = await this.client.post('/api/tokens/create', { token_name: tokenName });
    return response.data;
  }

  async listTokens() {
    const response = await this.client.get('/api/tokens');
    return response.data;
  }

  async revokeToken(tokenId) {
    const response = await this.client.delete(`/api/tokens/${tokenId}`);
    return response.data;
  }
}

// Usage
const api = new SparkAPI('https://yourdomain.com/api', 'your-token');

// List events
const events = await api.listEvents({ service: 'github', per_page: 10 });

// Create event
const newEvent = await api.createEvent({
  actor: { /* actor data */ },
  target: { /* target data */ },
  event: { /* event data */ },
  blocks: [ /* optional blocks */ ]
});
```

### Python

```python
import requests
from typing import Dict, List, Optional

class SparkAPI:
    def __init__(self, base_url: str, token: str):
        self.base_url = base_url
        self.headers = {
            'Authorization': f'Bearer {token}',
            'Content-Type': 'application/json'
        }

    def list_events(self, params: Optional[Dict] = None) -> Dict:
        response = requests.get(f'{self.base_url}/events', 
                              headers=self.headers, params=params)
        response.raise_for_status()
        return response.json()

    def get_event(self, event_id: str) -> Dict:
        response = requests.get(f'{self.base_url}/events/{event_id}', 
                              headers=self.headers)
        response.raise_for_status()
        return response.json()

    def create_event(self, event_data: Dict) -> Dict:
        response = requests.post(f'{self.base_url}/events', 
                               headers=self.headers, json=event_data)
        response.raise_for_status()
        return response.json()

    def update_event(self, event_id: str, updates: Dict) -> Dict:
        response = requests.put(f'{self.base_url}/events/{event_id}', 
                              headers=self.headers, json=updates)
        response.raise_for_status()
        return response.json()

    def delete_event(self, event_id: str) -> Dict:
        response = requests.delete(f'{self.base_url}/events/{event_id}', 
                                 headers=self.headers)
        response.raise_for_status()
        return response.json()

    def create_token(self, token_name: str) -> Dict:
        response = requests.post(f'{self.base_url}/tokens/create', 
                               headers=self.headers, 
                               json={'token_name': token_name})
        response.raise_for_status()
        return response.json()

    def list_tokens(self) -> Dict:
        response = requests.get(f'{self.base_url}/tokens', 
                              headers=self.headers)
        response.raise_for_status()
        return response.json()

    def revoke_token(self, token_id: int) -> Dict:
        response = requests.delete(f'{self.base_url}/tokens/{token_id}', 
                                 headers=self.headers)
        response.raise_for_status()
        return response.json()

# Usage
api = SparkAPI('https://yourdomain.com/api', 'your-token')

# List events
events = api.list_events({'service': 'github', 'per_page': 10})

# Create event
new_event = api.create_event({
    'actor': {'time': '2025-07-27T17:00:00Z', 'integration_id': 'uuid', ...},
    'target': {'time': '2025-07-27T17:00:00Z', 'integration_id': 'uuid', ...},
    'event': {'source_id': 'github-123', 'service': 'github', ...}
})
```

## Support

For API support, please contact:
- **Email**: api-support@yourdomain.com
- **Documentation**: https://docs.yourdomain.com/api
- **Status Page**: https://status.yourdomain.com

---

*Last updated: July 27, 2025* 