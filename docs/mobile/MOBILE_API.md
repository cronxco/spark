# Mobile API

The Mobile API provides the iOS companion app with authenticated, versioned access to a user's Spark data.

## Table of Contents

- [Authentication](#authentication)
- [Base URL and Versioning](#base-url-and-versioning)
- [Common Conventions](#common-conventions)
- [Data Types](#data-types)
- [Read Endpoints](#read-endpoints)
- [Write Endpoints](#write-endpoints)
- [Response Schemas](#response-schemas)
- [Related Documentation](#related-documentation)

---

## Authentication

The Mobile API uses Laravel Sanctum personal access tokens with scoped abilities.

### Token Abilities

| Ability     | Required for                        |
| ----------- | ----------------------------------- |
| `ios:read`  | All GET endpoints                   |
| `ios:write` | All POST / PATCH / DELETE endpoints |

Tokens are obtained via OAuth PKCE (see [Token Exchange](#token-exchange)). All authenticated requests must include:

```
Authorization: Bearer <token>
```

### Token Exchange

Two unauthenticated endpoints handle the PKCE flow:

| Method | Path                 | Description                                                    |
| ------ | -------------------- | -------------------------------------------------------------- |
| `POST` | `/api/oauth/token`   | Exchange a PKCE authorisation code for access + refresh tokens |
| `POST` | `/api/oauth/refresh` | Refresh an expired access token                                |

Both endpoints are throttled at 10 requests per minute per IP.

### Feature Flag

The entire `/api/v1/mobile/*` surface is gated by `config('ios.mobile_api_enabled')`. When disabled, all endpoints return `404`. This allows staged rollout independent of backend deployment.

---

## Base URL and Versioning

All Mobile API endpoints are mounted under:

```
/api/v1/mobile/
```

The `v1` prefix is part of the URL and is not negotiated via headers.

---

## Common Conventions

### Error Format

All errors return JSON:

```json
{
    "message": "Human-readable description of the error.",
    "hint": "Optional suggestion for recovery."
}
```

### HTTP Status Codes

| Status | Meaning                                     |
| ------ | ------------------------------------------- |
| `200`  | Success                                     |
| `201`  | Resource created                            |
| `204`  | Success, no content                         |
| `304`  | Not modified (ETag match)                   |
| `401`  | Missing or invalid token                    |
| `403`  | Token lacks required ability                |
| `404`  | Resource not found or feature flag disabled |
| `422`  | Validation failure                          |
| `429`  | Rate limit exceeded                         |

### ETag Caching

All GET endpoints emit a weak `ETag` header (`W/"..."`) computed from the response body. Clients can send `If-None-Match: <etag>` on subsequent requests; the server returns `304 Not Modified` with an empty body when the content is unchanged.

```
GET /api/v1/mobile/ping
If-None-Match: W/"abc123"

HTTP/1.1 304 Not Modified
```

Bypass caching for a single request with `Cache-Control: no-cache`.

### Cursor Pagination

Endpoints that return collections use opaque cursor pagination:

```json
{
    "data": [...],
    "next_cursor": "2025-01-15T09:30:00+00:00|550e8400-e29b-41d4-a716-446655440000",
    "has_more": true
}
```

Pass the `next_cursor` value as the `cursor` query parameter on the next request. Cursors are valid indefinitely. When `has_more` is `false`, no further pages exist.

### Response Headers

| Header          | Endpoints  | Description                        |
| --------------- | ---------- | ---------------------------------- |
| `ETag`          | All GET    | Weak ETag for conditional requests |
| `Last-Modified` | Most GET   | RFC 7231 date of the newest item   |
| `X-Cache`       | Cached GET | `HIT` or `MISS`                    |

---

## Data Types

| Type      | Format                 | Example                                                     |
| --------- | ---------------------- | ----------------------------------------------------------- |
| ID        | UUID v4 string         | `"550e8400-e29b-41d4-a716-446655440000"`                    |
| Timestamp | ISO 8601 with timezone | `"2025-01-15T09:30:00+00:00"`                               |
| Date      | `YYYY-MM-DD`           | `"2025-01-15"`                                              |
| Domain    | Enumerated string      | `"health"`, `"money"`, `"media"`, `"knowledge"`, `"online"` |

---

## Read Endpoints

### Summary

| Method | Path                        | Description                                         |
| ------ | --------------------------- | --------------------------------------------------- |
| `GET`  | `/ping`                     | Health check                                        |
| `GET`  | `/me`                       | Authenticated user profile                          |
| `GET`  | `/briefing/today`           | Daily summary across all domains                    |
| `GET`  | `/feed`                     | Cursor-paginated reverse-chronological event feed   |
| `GET`  | `/events/{id}`              | Single event                                        |
| `GET`  | `/objects/{id}`             | Single object with optional recent events           |
| `GET`  | `/blocks/{id}`              | Single block                                        |
| `GET`  | `/metrics`                  | All available metric identifiers and metadata       |
| `GET`  | `/metrics/{metric}`         | Metric trend with baseline and daily values         |
| `GET`  | `/widgets/today`            | Compact today widget payload (≤4 KB)                |
| `GET`  | `/widgets/metrics/{metric}` | Tiny sparkline widget for a single metric           |
| `GET`  | `/widgets/spend`            | Today's spend widget                                |
| `GET`  | `/search`                   | Multi-mode search                                   |
| `GET`  | `/integrations`             | List all user integrations                          |
| `GET`  | `/integrations/{id}`        | Single integration                                  |
| `GET`  | `/places/{id}`              | Single place (geo-aware EventObject)                |
| `GET`  | `/map/data`                 | Geo-located events and places within a bounding box |
| `GET`  | `/sync/delta`               | Incremental sync of changed events since a cursor   |

---

### `GET /ping`

Health check for the full middleware stack. Use after a token refresh to verify the token is valid.

**Response `200`**

```json
{
    "status": "ok",
    "user_id": "550e8400-e29b-41d4-a716-446655440000",
    "server_time": "2025-01-15T09:30:00+00:00"
}
```

---

### `GET /me`

Returns the authenticated user's profile. The `id` field is used as the Reverb WebSocket channel identifier for real-time subscriptions.

**Response `200`** — [UserProfile](#userprofile)

---

### `GET /briefing/today`

Returns a structured daily summary across all domains for a given date.

**Query Parameters**

| Parameter | Type   | Default | Description                                        |
| --------- | ------ | ------- | -------------------------------------------------- |
| `date`    | string | today   | `YYYY-MM-DD`, `today`, `yesterday`, or `tomorrow`  |
| `domains` | string | all     | Comma-separated domain filter, e.g. `health,money` |

**Response `200`**

```json
{
    "date": "2025-01-15",
    "timezone": "Europe/London",
    "sync_status": { ... },
    "sections": {
        "health": { ... },
        "activity": { ... },
        "money": { ... },
        "media": { ... },
        "knowledge": { ... }
    },
    "anomalies": [ ... ]
}
```

The shape of each section is domain-specific and driven by `DaySummaryService`.

---

### `GET /feed`

Cursor-paginated reverse-chronological feed of the user's events.

**Query Parameters**

| Parameter | Type    | Default | Description                                                            |
| --------- | ------- | ------- | ---------------------------------------------------------------------- |
| `cursor`  | string  | —       | Opaque cursor from a prior response                                    |
| `limit`   | integer | 20      | Items per page (max 100)                                               |
| `domain`  | string  | —       | Filter by domain: `health`, `money`, `media`, `knowledge`, or `online` |
| `date`    | string  | —       | Restrict to a single calendar day (`YYYY-MM-DD`); past or future       |

**Date behaviour**

- **No `date`** (default): returns events up to and including the current moment, paging backwards. Future events are excluded.
- **`date` specified**: returns only events whose `time` falls within that calendar day (midnight–23:59:59 UTC). Cursor pagination still applies within the day. Can be a past or future date.

When `domain=knowledge` the response includes compact enrichment fields on each item — see [Knowledge Enrichment](#knowledge-enrichment) below.

**Response `200`**

```json
{
    "data": [ CompactEvent, ... ],
    "next_cursor": "2025-01-15T09:30:00+00:00|<uuid>",
    "has_more": true
}
```

**Response `422`** — Invalid domain value or malformed `date` parameter.

See [CompactEvent](#compactevent) for the item schema.

#### Knowledge Enrichment

When `domain=knowledge`, each `CompactEvent` in the feed may include two additional optional fields:

| Field               | Type   | Description                                                    |
| ------------------- | ------ | -------------------------------------------------------------- |
| `tldr`              | string | Single-sentence TL;DR from the associated block (if generated) |
| `summary_paragraph` | string | Longer summary paragraph from the associated block (if exists) |
| `target.media_url`  | string | OG image URL on the target object (e.g. article hero image)    |

Both fields are omitted rather than `null` when not available.

---

### `GET /events/{id}`

Returns a single event by UUID.

**Response `200`** — [CompactEvent](#compactevent)

**Response `404`** — Event not found or belongs to another user.

---

### `GET /objects/{id}`

Returns a single EventObject, optionally including its most recent events.

**Query Parameters**

| Parameter        | Type    | Default | Description                  |
| ---------------- | ------- | ------- | ---------------------------- |
| `include_events` | boolean | `true`  | Attach `recent_events` array |
| `event_limit`    | integer | 5       | Max recent events (1–25)     |

**Response `200`**

```json
{
    "id": "uuid",
    "concept": "account",
    "type": "monzo_account",
    "title": "Personal",
    "time": "2025-01-01T00:00:00+00:00",
    "content": "Optional description",
    "url": "https://...",
    "media_url": "https://...",
    "recent_events": [ CompactEvent, ... ]
}
```

`recent_events` is omitted when `include_events=false`.

---

### `GET /blocks/{id}`

Returns a single Block by UUID.

**Response `200`** — [CompactBlock](#compactblock)

**Response `404`** — Block not found or belongs to another user.

---

### `GET /metrics`

Returns all metric identifiers and metadata for the authenticated user. Use this to build a dynamic metrics catalogue instead of maintaining a hardcoded list.

**Response `200`**

```json
[
    {
        "id": "uuid",
        "identifier": "oura.sleep_score",
        "display_name": "Sleep Score",
        "service": "oura",
        "domain": "health",
        "action": "had_sleep_score",
        "unit": "percent",
        "event_count": 180,
        "mean": 83.1,
        "last_event_at": "2025-01-15T08:00:00+00:00"
    }
]
```

Results are ordered by `service` then `action`. An empty array is returned when no metrics have been computed yet.

---

### `GET /metrics/{metric}`

Returns a metric trend with per-day values, summary statistics, and optional baseline data.

`{metric}` is a dot-separated identifier such as `oura.sleep_score` or `monzo.spend`.

**Query Parameters**

| Parameter | Type   | Default       | Description                                   |
| --------- | ------ | ------------- | --------------------------------------------- |
| `from`    | string | `30_days_ago` | Start date (`YYYY-MM-DD` or relative keyword) |
| `to`      | string | `today`       | End date (`YYYY-MM-DD` or relative keyword)   |
| `range`   | string | `null`        | Preset range: `7d`, `30d`, `90d`, or `1y`     |

**Relative Date Keywords**: `today`, `yesterday`, `7_days_ago`, `30_days_ago`, `90_days_ago`

**Response `200`**

```json
{
    "metric": "oura.sleep_score",
    "service": "oura",
    "action": "sleep_score",
    "unit": "score",
    "range": {
        "from": "2024-12-16",
        "to": "2025-01-15"
    },
    "daily_values": [
        {
            "date": "2024-12-16",
            "value": 82,
            "vs_baseline_pct": 2.5,
            "is_anomaly": false
        }
    ],
    "summary": {
        "min": 72,
        "max": 92,
        "mean": 83.1,
        "trend": "stable"
    },
    "baseline": {
        "mean": 83.1,
        "stddev": 5.2,
        "normal_lower": 72.7,
        "normal_upper": 93.5,
        "sample_days": 90
    }
}
```

`baseline` and the `vs_baseline_pct` / `is_anomaly` fields on `daily_values` are omitted when insufficient history exists.

**Response `404`** — Unknown metric identifier. The response includes a `hint` listing available identifiers for the service prefix.

---

### `GET /widgets/today`

Returns a compact today payload for WidgetKit. Payload is capped at approximately 4 KB.

**Response `200`**

```json
{
    "date": "2025-01-15",
    "headline": "Good morning",
    "metrics": [{ "label": "Sleep", "value": 82, "unit": "score" }],
    "next_event": {
        "time": "2025-01-15T14:00:00+00:00",
        "title": "Team standup"
    },
    "generated_at": "2025-01-15T06:00:00+00:00"
}
```

`next_event` is `null` when no upcoming event exists. `metrics` contains up to 4 items.

---

### `GET /widgets/metrics/{metric}`

Returns a minimal sparkline payload for a single metric widget.

**Response `200`**

```json
{
    "metric": "oura.sleep_score",
    "unit": "score",
    "current": 82.0,
    "sparkline": [82.0, 85.0, 78.0, 90.0, 83.0, 79.0, 82.0]
}
```

`sparkline` contains up to 7 values (one per day, most recent last). `current` is `null` when no data exists for today.

---

### `GET /widgets/spend`

Returns today's spend summary for the Monzo spend widget.

**Response `200`**

```json
{
    "date": "2025-01-15",
    "total": 45.2,
    "unit": "GBP",
    "currency": "GBP",
    "transaction_count": 8,
    "top_merchants": [{ "name": "Pret A Manger", "total": 12.5, "count": 2 }]
}
```

Returns zeroed values if no Monzo integration is connected.

---

### `GET /search`

Searches across events, objects, integrations, and metrics using one of five modes.

**Query Parameters**

| Parameter | Type    | Default   | Description                |
| --------- | ------- | --------- | -------------------------- |
| `q`       | string  | —         | Search query               |
| `mode`    | string  | `default` | Search mode (see below)    |
| `limit`   | integer | 10        | Max results per collection |

**Search Modes**

| Mode          | Description                                      |
| ------------- | ------------------------------------------------ |
| `default`     | Keyword match across events and objects          |
| `semantic`    | Vector similarity search using OpenAI embeddings |
| `tag`         | Match events by tag name                         |
| `metric`      | Match metric statistics by identifier or service |
| `integration` | Match integrations by service name               |

**Response `200`**

```json
{
    "mode": "default",
    "query": "sleep score",
    "events": [ CompactEvent, ... ],
    "objects": [ CompactObject, ... ],
    "integrations": [ CompactIntegration, ... ],
    "metrics": [ CompactMetric, ... ]
}
```

Empty collections are included as `[]`. An unknown `mode` returns `422`.

---

### `GET /integrations`

Returns all integrations for the authenticated user, ordered by service name.

**Response `200`**

```json
{
    "data": [ CompactIntegration, ... ]
}
```

---

### `GET /integrations/{id}`

Returns a single integration by UUID.

**Response `200`** — [CompactIntegration](#compactintegration)

**Response `404`** — Integration not found or belongs to another user.

---

### `GET /places/{id}`

Returns a single place (an EventObject with `concept = 'place'`).

**Response `200`** — [CompactPlace](#compactplace)

**Response `404`** — Place not found, not a place, or belongs to another user.

---

### `GET /map/data`

Returns geo-located events and places within a bounding box. When the result count exceeds 500, the server returns coarse clusters instead of individual markers.

**Query Parameters**

| Parameter | Type   | Required | Description                                        |
| --------- | ------ | -------- | -------------------------------------------------- |
| `bbox`    | string | Yes      | `swLat,swLng,neLat,neLng` (comma-separated floats) |

**Response `200` — Markers (≤500 items)**

```json
{
    "clusters": [],
    "markers": {
        "events": [ CompactEvent, ... ],
        "places": [ CompactPlace, ... ]
    }
}
```

**Response `200` — Clusters (>500 items)**

```json
{
    "clusters": [{ "lat": 51.5, "lng": -0.12, "count": 42 }],
    "markers": []
}
```

Clusters are rounded to 2 decimal places (~1 km grid). Anti-meridian crossings are not yet supported.

---

### `GET /sync/delta`

Returns events that have been created, updated, or deleted since a given cursor. Use this for incremental sync rather than polling the full feed.

**Query Parameters**

| Parameter | Type   | Default | Description                  |
| --------- | ------ | ------- | ---------------------------- |
| `since`   | string | epoch   | Cursor from a prior response |

**Response `200`**

```json
{
    "created": [ CompactEvent, ... ],
    "updated": [ CompactEvent, ... ],
    "deleted": [ "uuid1", "uuid2" ],
    "next_cursor": "2025-01-15T09:30:00+00:00|<uuid>"
}
```

Pass `next_cursor` as `since` on the next call. When all arrays are empty, the client is fully up-to-date. Returns up to 50 events per call.

---

## Write Endpoints

All write endpoints require `ios:write` ability.

### Summary

| Method   | Path                           | Description                       |
| -------- | ------------------------------ | --------------------------------- |
| `POST`   | `/devices`                     | Register an APNs device token     |
| `DELETE` | `/devices/{id}`                | Unregister a device               |
| `POST`   | `/health/samples`              | Ingest HealthKit samples (batch)  |
| `POST`   | `/live-activities`             | Start a Live Activity             |
| `PATCH`  | `/live-activities/{id}`        | Push a Live Activity update       |
| `DELETE` | `/live-activities/{id}`        | End a Live Activity               |
| `POST`   | `/live-activities/{id}/tokens` | Rotate a Live Activity push token |
| `POST`   | `/check-ins`                   | Submit a daily mood check-in      |
| `POST`   | `/anomalies/{id}/acknowledge`  | Acknowledge a metric anomaly      |

---

### `POST /devices`

Registers or updates an APNs device token for push notifications.

**Request Body**

```json
{
    "apns_token": "aaaa...64hexchars",
    "app_environment": "sandbox",
    "bundle_id": "co.cronx.spark",
    "app_version": "1.0.0",
    "os_version": "18.0",
    "device_name": "Will's iPhone"
}
```

| Field             | Type   | Required | Description                           |
| ----------------- | ------ | -------- | ------------------------------------- |
| `apns_token`      | string | Yes      | 64-character hex APNs device token    |
| `app_environment` | string | Yes      | `sandbox` or `production`             |
| `bundle_id`       | string | Yes      | App bundle identifier (max 100 chars) |
| `app_version`     | string | Yes      | Semver string (max 30 chars)          |
| `os_version`      | string | Yes      | iOS version string (max 30 chars)     |
| `device_name`     | string | No       | Human-readable device name            |

**Response `201`**

```json
{
    "id": 1,
    "device_type": "ios",
    "endpoint": "aaaa...64hexchars",
    "app_environment": "sandbox"
}
```

Upserts on `(user_id, apns_token)` — re-registering with the same token updates metadata.

---

### `DELETE /devices/{id}`

Unregisters a push subscription by its integer ID.

**Response `204`** — No content.

**Response `404`** — Device not found or belongs to another user.

---

### `POST /health/samples`

Ingests a batch of HealthKit samples. Each sample is processed individually — the response reports per-sample status so the client can retry failures without re-sending successes.

**Request Body**

```json
{
    "samples": [
        {
            "external_id": "ABC123",
            "type": "HKQuantityTypeIdentifierHeartRate",
            "start": "2025-01-15T09:00:00+00:00",
            "end": "2025-01-15T09:01:00+00:00",
            "value": 72,
            "unit": "bpm",
            "source": "Apple Watch",
            "metadata": {}
        }
    ]
}
```

| Field                   | Type     | Required | Description                                                            |
| ----------------------- | -------- | -------- | ---------------------------------------------------------------------- |
| `samples`               | array    | Yes      | 1–500 sample objects                                                   |
| `samples[].external_id` | string   | Yes      | Stable ID from HealthKit (max 100 chars)                               |
| `samples[].type`        | string   | Yes      | `HKQuantityTypeIdentifier*` or `HKWorkoutActivityType` (max 100 chars) |
| `samples[].start`       | datetime | Yes      | ISO 8601 start time                                                    |
| `samples[].end`         | datetime | No       | ISO 8601 end time                                                      |
| `samples[].value`       | number   | No       | Numeric quantity                                                       |
| `samples[].unit`        | string   | No       | Unit string, e.g. `bpm`, `kcal` (max 40 chars)                         |
| `samples[].source`      | string   | No       | Source device name (max 100 chars)                                     |
| `samples[].metadata`    | object   | No       | Arbitrary HealthKit metadata                                           |

**Response `200`**

```json
{
    "results": [
        {
            "external_id": "ABC123",
            "status": "created"
        }
    ]
}
```

Sample status values: `created`, `duplicate`, `skipped`, `error`.

---

### `POST /live-activities`

Starts a new iOS Live Activity and sends the initial APNs push.

**Request Body**

```json
{
    "activity_id": "uuid",
    "activity_type": "SomeActivityType",
    "push_token": "hex-encoded-push-token",
    "device_id": null,
    "content_state": {}
}
```

| Field           | Type    | Required | Description                                  |
| --------------- | ------- | -------- | -------------------------------------------- |
| `activity_id`   | UUID    | Yes      | iOS-assigned activity identifier             |
| `activity_type` | string  | Yes      | Activity type name (max 60 chars)            |
| `push_token`    | string  | Yes      | APNs Live Activity push token (min 16 chars) |
| `device_id`     | integer | No       | Optional push subscription ID for targeting  |
| `content_state` | object  | No       | Initial state payload                        |

**Response `201`** — [LiveActivityToken](#liveactivitytoken)

---

### `PATCH /live-activities/{id}`

Pushes a content state update to a running Live Activity. Rate-limited to 16 pushes per hour per activity.

**Request Body**

```json
{
    "content_state": {},
    "alert": {}
}
```

| Field           | Type   | Required | Description               |
| --------------- | ------ | -------- | ------------------------- |
| `content_state` | object | Yes      | New state payload         |
| `alert`         | object | No       | Optional alert body/title |

**Response `200`** — [LiveActivityToken](#liveactivitytoken)

**Response `429`** — Rate limit exceeded (16 pushes/hour).

---

### `DELETE /live-activities/{id}`

Ends a Live Activity and sends the final APNs push.

**Response `204`** — No content.

---

### `POST /live-activities/{id}/tokens`

Updates the push token for a running Live Activity. iOS rotates push tokens mid-activity; call this endpoint when the app receives a new token.

**Request Body**

```json
{
    "push_token": "new-hex-encoded-push-token"
}
```

**Response `200`** — [LiveActivityToken](#liveactivitytoken)

---

### `POST /check-ins`

Records a daily mood check-in for morning or afternoon.

**Request Body**

```json
{
    "period": "morning",
    "physical": 4,
    "mental": 3,
    "date": "2025-01-15",
    "latitude": 51.5074,
    "longitude": -0.1278,
    "address": "London, UK"
}
```

| Field       | Type    | Required | Description                            |
| ----------- | ------- | -------- | -------------------------------------- |
| `period`    | string  | Yes      | `morning` or `afternoon`               |
| `physical`  | integer | Yes      | Physical wellbeing score (1–5)         |
| `mental`    | integer | Yes      | Mental wellbeing score (1–5)           |
| `date`      | string  | Yes      | `YYYY-MM-DD`                           |
| `latitude`  | number  | No       | Location latitude (–90 to 90)          |
| `longitude` | number  | No       | Location longitude (–180 to 180)       |
| `address`   | string  | No       | Human-readable address (max 255 chars) |

**Response `201`** — [CompactEvent](#compactevent) representing the check-in.

Submitting a second check-in for the same `period` and `date` updates the existing record.

---

### `POST /anomalies/{id}/acknowledge`

Acknowledges a metric anomaly, optionally suppressing future alerts until a date.

`{id}` is the UUID of the anomaly event.

**Request Body**

```json
{
    "note": "Optional acknowledgement note",
    "suppress_until": "2025-02-01"
}
```

| Field            | Type   | Required | Description                             |
| ---------------- | ------ | -------- | --------------------------------------- |
| `note`           | string | No       | Free-text note (max 500 chars)          |
| `suppress_until` | date   | No       | Suppress anomaly alerts until this date |

**Response `200`**

```json
{
    "acknowledged": true
}
```

**Response `404`** — Anomaly not found or belongs to another user.

---

## Response Schemas

These schemas are stable contracts. The iOS client decodes them into Swift structs — shape changes require an explicit migration.

### UserProfile

```json
{
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "name": "Will",
    "email": "will@cronx.co",
    "timezone": "Europe/London",
    "avatar_url": null
}
```

`timezone` may be `null` when not set on the user. `avatar_url` is always `null` until a media/avatar system is introduced.

### CompactEvent

```json
{
    "id": "uuid",
    "time": "2025-01-15T09:30:00+00:00",
    "service": "oura",
    "domain": "health",
    "action": "sleep_score",
    "value": "82",
    "unit": "score",
    "url": "https://...",
    "actor": {
        "id": "uuid",
        "title": "Oura Ring",
        "concept": "device"
    },
    "target": {
        "id": "uuid",
        "title": "Sleep Session",
        "concept": "session"
    }
}
```

`value`, `unit`, `url`, `actor`, and `target` are omitted when not present. `value` is formatted according to the event's `value_multiplier` and formatter.

### CompactObject

```json
{
    "id": "uuid",
    "concept": "account",
    "type": "monzo_account",
    "title": "Personal",
    "time": "2025-01-01T00:00:00+00:00",
    "content": "Optional description",
    "url": "https://...",
    "media_url": "https://..."
}
```

`content`, `url`, and `media_url` are omitted when not present.

### CompactBlock

```json
{
    "id": "uuid",
    "block_type": "biometric",
    "title": "Heart Rate",
    "time": "2025-01-15T09:30:00+00:00",
    "content": "Optional text content (truncated at 500 chars)...",
    "value": "72",
    "unit": "bpm",
    "media_url": "https://..."
}
```

`content`, `value`, `unit`, and `media_url` are omitted when not present.

### CompactIntegration

```json
{
    "id": "uuid",
    "service": "oura",
    "name": "Oura Ring",
    "instance_type": "default",
    "status": "active"
}
```

### CompactMetric

```json
{
    "id": "uuid",
    "identifier": "oura.sleep_score",
    "display_name": "Sleep Score",
    "service": "oura",
    "domain": "health",
    "action": "sleep_score",
    "unit": "score",
    "event_count": 365,
    "mean": 83.1,
    "last_event_at": "2025-01-15T00:00:00+00:00"
}
```

`mean` is `null` when insufficient data exists.

### CompactPlace

```json
{
    "id": "uuid",
    "title": "Home",
    "type": "residential",
    "latitude": 51.5074,
    "longitude": -0.1278,
    "address": "London, UK",
    "category": "home"
}
```

`latitude`, `longitude`, `address`, and `category` are omitted when not available.

### LiveActivityToken

```json
{
    "id": 1,
    "activity_id": "uuid",
    "activity_type": "SomeActivityType",
    "starts_at": "2025-01-15T09:00:00+00:00",
    "ends_at": null,
    "last_pushed_at": "2025-01-15T09:00:00+00:00"
}
```

`ends_at` is `null` for active activities. `last_pushed_at` is `null` before the first push.

---

## Related Documentation

- [API.md](../Architecture/API.md) - Web REST API (events, search, integrations)
- [NOTIFICATIONS.md](../Architecture/NOTIFICATIONS.md) - Push notification system
- [PLACES.md](../Architecture/PLACES.md) - Geographic place tracking
- [EVENTS.md](../Architecture/EVENTS.md) - Event data model
- [OBJECTS.md](../Architecture/OBJECTS.md) - EventObject data model
