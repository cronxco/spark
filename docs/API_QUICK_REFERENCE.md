# Spark API Quick Reference

## Authentication
```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     https://yourdomain.com/api/endpoint
```

## API Token Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/tokens/create` | Create new API token |
| `GET` | `/api/tokens` | List user's tokens |
| `DELETE` | `/api/tokens/{id}` | Revoke token |

## Events API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/events` | List events (with filtering) |
| `GET` | `/api/events/{id}` | Get specific event |
| `POST` | `/api/events` | Create new event |
| `PUT` | `/api/events/{id}` | Update event |
| `DELETE` | `/api/events/{id}` | Delete event |

## Common Query Parameters

### Events List Filtering
- `integration_id` - Filter by integration
- `service` - Filter by service (e.g., "github")
- `domain` - Filter by domain (e.g., "pull_request")
- `action` - Filter by action (e.g., "opened")
- `from_date` - Filter from date (ISO 8601)
- `to_date` - Filter to date (ISO 8601)
- `per_page` - Items per page (default: 15)

## Response Status Codes

| Code | Meaning |
|------|---------|
| `200` | Success |
| `201` | Created |
| `401` | Unauthorized |
| `404` | Not Found |
| `422` | Validation Error |
| `429` | Rate Limited |

## Example Requests

### Create API Token
```bash
curl -X POST https://yourdomain.com/api/tokens/create \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"token_name": "My API Token"}'
```

### List Events
```bash
curl -X GET "https://yourdomain.com/api/events?service=github&per_page=10" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Create Event
```bash
curl -X POST https://yourdomain.com/api/events \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "actor": {
      "time": "2025-07-27T17:00:00Z",
      "integration_id": "uuid",
      "concept": "user",
      "type": "github_user",
      "title": "John Doe",
      "content": "Developer",
      "metadata": {},
      "url": "https://github.com/johndoe",
      "image_url": "https://github.com/johndoe.png",
      "embeddings": [0.1, 0.2, 0.3]
    },
    "target": {
      "time": "2025-07-27T17:00:00Z",
      "integration_id": "uuid",
      "concept": "pull_request",
      "type": "github_pr",
      "title": "Add feature",
      "content": "New feature",
      "metadata": {},
      "url": "https://github.com/user/repo/pull/123",
      "image_url": null,
      "embeddings": [0.4, 0.5, 0.6]
    },
    "event": {
      "source_id": "github-123",
      "time": "2025-07-27T17:00:00Z",
      "integration_id": "uuid",
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
```bash
curl -X PUT https://yourdomain.com/api/events/event-uuid \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "service": "updated-service",
    "action": "updated-action",
    "value": 2000
  }'
```

### Delete Event
```bash
curl -X DELETE https://yourdomain.com/api/events/event-uuid \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## Data Types

### UUID Format
All IDs use UUID v4 format: `550e8400-e29b-41d4-a716-446655440000`

### DateTime Format
ISO 8601 format: `2025-07-27T17:00:00.000000Z`

### Vector Format
Embeddings are stored as arrays of floats: `[0.1, 0.2, 0.3]`

## Rate Limits
- **Default**: 60 requests/minute per user
- **Burst**: Up to 120 requests/minute
- **Headers**: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`

## Error Handling
```json
{
  "message": "Error description",
  "errors": {
    "field": ["Validation message"]
  }
}
```

---

*For complete documentation, see `API_DOCUMENTATION.md`* 