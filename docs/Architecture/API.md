# Spark API

## Canonical v1 API

`/api/v1` is Spark's client-neutral, capability-scoped API. It is the REST
counterpart to the Spark MCP server; `/api` remains supported as a legacy
surface and `/api/v1/mobile` remains an iOS-specific adapter.

Use least-privilege Sanctum abilities per operation: `data:read`,
`data:write`, `insights:read`, `insights:write`, `integrations:read`,
`integrations:sync`, `flint:read`, `flint:write`, `finance:read`, and
`finance:write`. `web:fetch` is a separate, MCP-only capability because it can
use saved browser cookies. Existing `mcp:read` tokens are accepted for read
operations during migration. Public API and MCP calls require a Sanctum bearer
token; cookie sessions are not capability credentials. Collection reads use a
weak representation ETag for cache revalidation. Detail reads of mutable
entities emit a strong opaque resource ETag: clients must send it in
`If-Match` when changing an event, object, block, its relationships, an event
note, tag assignment, or a manual finance account/balance. Missing preconditions receive `428`, stale tokens receive `412`,
and both include the current `ETag`. Digest creation remains deliberately
non-idempotent and does not use `If-Match`.

The initial v1 surface exposes event/object/block/feed/search/tag/map/place
data; day summaries, metrics, check-ins, health dashboard, anomalies, and Up
to Speed; integration inspection and sync; Flint digest/question workflows;
and manual finance account/balance management, including archival.

### Surface matrix

| Feature                                                          | General REST               | Mobile adapter                | MCP                           | Boundary                        |
| ---------------------------------------------------------------- | -------------------------- | ----------------------------- | ----------------------------- | ------------------------------- |
| User data, insights, integrations, Flint and finance             | Yes, granular capabilities | Yes, `ios:read` / `ios:write` | Yes, granular capabilities    | Shared services where available |
| Entity edits, relationships and locations                        | Yes where listed           | Yes                           | Entity/relationship MCP tools | Owned resources only            |
| Device/APNs, HealthKit ingestion, Live Activities, OAuth handoff | No                         | Yes                           | No                            | iOS lifecycle transport only    |
| API-token administration                                         | No                         | No                            | No                            | Web settings only               |
| Browser HTML fetch with saved cookies                            | No                         | No                            | Yes, `web:fetch`              | MCP-only                        |
| Admin and task-pipeline operations                               | No                         | No                            | No                            | Internal/web administration     |

### MCP capability mapping

The MCP transport only authenticates the caller. Each tool independently
enforces its capability, so a narrowly scoped integration token cannot read
events or metrics. Existing `mcp:read` tokens remain a read-only compatibility
alias while clients migrate.

| Capability          | MCP tools                                                                              |
| ------------------- | -------------------------------------------------------------------------------------- |
| `data:read`         | event/object/block reads, exact filtering, semantic search                             |
| `insights:read`     | day summaries/context, baselines, metric trends, service status, check-ins             |
| `integrations:read` | list integrations                                                                      |
| `flint:read`        | retrieve Flint digests                                                                 |
| `insights:write`    | acknowledge anomaly                                                                    |
| `integrations:sync` | trigger integration update                                                             |
| `flint:write`       | create digest and answer Flint question                                                |
| `data:write`        | set an event note, update owned events/objects/blocks, and create/delete relationships |
| `web:fetch`         | fetch webpage HTML using saved Fetch cookies (MCP only)                                |

`PATCH /api/v1/events/{id}`, `/objects/{id}`, and `/blocks/{id}` provide the
same non-destructive edits as MCP's `update-entity`. Relationship list/create/
delete is available at the corresponding entity `/relationships` routes and
through `manage-relationship`. Both surfaces scope every entity through the
caller before loading it; cross-user identifiers are therefore treated as
missing. Deletion of events, objects, and blocks is intentionally not exposed.
Manual finance accounts may be archived with `DELETE /api/v1/finance/accounts/{id}`.

### Transport parity and intentional boundaries

The REST API and MCP share the same ownership-scoped entity mutations and
Flint digest creation command. REST additionally exposes client-oriented tag,
bookmark, map/place, finance-account, check-in, and Up to Speed endpoints.
MCP additionally exposes semantic per-entity search and browser HTML fetching.
The mobile adapter additionally exposes day context, service status, exact
event filtering, typed event/object/block semantic or keyword search, and
explicit location operations. Service-wide integration
sync is available through both MCP and `POST /api/v1/integrations/sync`.

MCP resources enforce the same capability as their equivalent tools. MCP
browser fetching is intentionally MCP-only under `web:fetch`; it is never
available through REST or mobile because it can use saved browser cookies.

### Mobile API parity

The `/api/v1/mobile` adapter retains `ios:read` and `ios:write` scopes for
iOS-client compatibility. It includes the shared event/object/block edits,
relationship management, Flint digest creation, integration sync by instance
or service, and metric baseline discovery, alongside its existing user profile,
feed, widgets, notifications, check-ins, finance, map, and delta-sync APIs.

Device registration, APNs/Live Activities, HealthKit ingestion, and OAuth
handoff are deliberately mobile-only: they remain necessary for the iOS app
but are not general REST or MCP capabilities. API-token management is web
settings-only. Browser HTML fetching stays MCP-only under `web:fetch`.

REST API for managing events, objects, and blocks with secure authentication using Laravel Sanctum.

---

## Overview

The Spark API provides programmatic access to event data generated by integrations such as GitHub and other external services. It follows RESTful conventions, uses standard HTTP status codes, and returns JSON responses.

**Base URL**

```
https://yourdomain.com/api
```

All endpoints require Bearer token authentication unless otherwise stated.

---

## Authentication

Spark uses **Laravel Sanctum** for API authentication.

### Creating API Tokens

API tokens are created via the web UI:

1. Navigate to `/settings/api-tokens`
2. Create a token with a descriptive name
3. Copy the generated token (it is only shown once)

### Using API Tokens

Include the token in the `Authorization` header:

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     https://yourdomain.com/api/events
```

### Token Management Endpoints

| Method | Endpoint             | Description                            |
| ------ | -------------------- | -------------------------------------- |
| POST   | `/api/tokens/create` | Create a new API token                 |
| GET    | `/api/tokens`        | List tokens for the authenticated user |
| DELETE | `/api/tokens/{id}`   | Revoke a specific token                |

**Create Token Request**

```json
{
    "token_name": "My API Token"
}
```

**Create Token Response**

```json
{
    "token": "1|abc123def456...",
    "token_name": "My API Token",
    "created_at": "2025-07-27T17:00:00.000000Z"
}
```

---

## Events API

Events represent actions that occur within an integration (for example, a GitHub pull request being opened).

### Endpoints

| Method | Endpoint           | Description                           |
| ------ | ------------------ | ------------------------------------- |
| GET    | `/api/events`      | List events (with optional filtering) |
| GET    | `/api/events/{id}` | Retrieve a single event               |
| POST   | `/api/events`      | Create a new event                    |
| PUT    | `/api/events/{id}` | Update an existing event              |
| DELETE | `/api/events/{id}` | Delete an event                       |

---

## Listing Events

### Query Parameters

| Parameter        | Type     | Description                            |
| ---------------- | -------- | -------------------------------------- |
| `integration_id` | UUID     | Filter by integration                  |
| `service`        | string   | Filter by service (e.g. `github`)      |
| `domain`         | string   | Filter by domain (e.g. `pull_request`) |
| `action`         | string   | Filter by action (e.g. `opened`)       |
| `from_date`      | ISO 8601 | Filter events from this date           |
| `to_date`        | ISO 8601 | Filter events to this date             |
| `per_page`       | integer  | Items per page (default: 15, max: 100) |

### Example

```bash
curl -X GET "https://yourdomain.com/api/events?service=github&per_page=10" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## Creating Events

Creating an event allows you to submit a complete activity consisting of:

- **Actor** – who performed the action
- **Target** – what the action was performed on (optional)
- **Event** – the action itself
- **Blocks** – additional structured content (optional)

### Create Event Request

```json
{
    "actor": {
        "time": "2025-07-27T17:00:00.000000Z",
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
            "title": "Code Changes",
            "content": "+100 lines added",
            "value": 100,
            "value_unit": "lines"
        }
    ]
}
```

### Ownership and linkage

`event.integration_id` is the only client-supplied field that selects an
integration. It must belong to the authenticated user; unknown or unowned IDs
return `404`.

The server derives `actor.user_id` and `target.user_id` from the authenticated
user, derives the event's actor and target IDs from the newly created objects,
and derives each block's event ID from the newly created event. Supplied values
for those fields cannot override these bindings. Actor, target, and block
`integration_id` values are ignored for backward compatibility and do not
select or link an integration.

---

## Updating Events

All fields are optional when updating an event.

### Example

```bash
curl -X PUT https://yourdomain.com/api/events/event-uuid \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "service": "github",
    "action": "merged",
    "value": 2000
  }'
```

---

## Deleting Events

```bash
curl -X DELETE https://yourdomain.com/api/events/event-uuid \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## Response Formats

### Event

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

### Event Object (Actor / Target)

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

### Block

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

### Paginated Responses

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

---

## Error Handling

### HTTP Status Codes

| Code | Description         |
| ---- | ------------------- |
| 200  | Success             |
| 201  | Created             |
| 401  | Unauthenticated     |
| 404  | Resource not found  |
| 422  | Validation error    |
| 429  | Rate limit exceeded |

### Error Response Format

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "field": ["Validation message"]
    }
}
```

---

## Rate Limiting

| Limit   | Value                   |
| ------- | ----------------------- |
| Default | 60 requests per minute  |
| Burst   | 120 requests per minute |

Rate limit headers:

- `X-RateLimit-Limit`
- `X-RateLimit-Remaining`
- `X-RateLimit-Reset`

Clients should apply exponential backoff when rate limited.

---

## Data Types

| Type     | Format   | Example                                |
| -------- | -------- | -------------------------------------- |
| UUID     | UUID v4  | `550e8400-e29b-41d4-a716-446655440000` |
| DateTime | ISO 8601 | `2025-07-27T17:00:00.000000Z`          |

---

## Related Documentation

- `CLAUDE.md` – Architecture and plugin system overview
- `docs/SPOTLIGHT.md` – Command palette documentation
