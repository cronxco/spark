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

Unmatched `/api/*` routes return the same sanitized JSON shape in production:

```json
{
    "message": "Not found."
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
| `GET`  | `/health/dashboard`         | Fitness-first Health tab dashboard                  |
| `GET`  | `/feed`                     | Cursor-paginated reverse-chronological event feed   |
| `GET`  | `/notifications`            | Cursor-paginated notifications inbox                |
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
| `GET`  | `/events/filter`            | Exact service/action/date-range event filtering, matching MCP |
| `GET`  | `/context/day`               | Full raw day context (events, metrics, relationships) |
| `GET`  | `/context/service-status`   | Sync coverage/freshness per service for a date       |
| `GET`  | `/metrics/baselines`        | Baseline statistics for every computed metric         |
| `GET`  | `/search/{type}`            | Typed semantic/keyword search (`events`, `objects`, or `blocks`) |
| `GET`  | `/tags`                      | Cursor-paginated list of the user's tags              |
| `GET`  | `/tags/suggest`              | Autocomplete tag suggestions                          |
| `GET`  | `/tags/{id}`                 | A single tag plus the items tagged with it            |
| `GET`  | `/{kind}/{id}/relationships` | List relationships on an owned event, object, or block |
| `GET`  | `/settings/notifications`   | Current notification preferences                       |
| `GET`  | `/check-ins`                 | Morning/afternoon check-in status for a date          |
| `GET`  | `/check-ins/history`        | Check-in history for a date range (max 90 days)       |
| `GET`  | `/check-ins/timezone`       | Effective timezone state (profile or time-travel override) |
| `GET`  | `/up-to-speed`               | Ordered catch-up queue (Flint digests, check-ins, anomalies, news) |
| `GET`  | `/flint/digests`             | Flint digest(s) for a date                            |
| `GET`  | `/flint/digests/{id}`       | A single Flint digest                                  |
| `GET`  | `/money/accounts`            | All non-archived manual/synced finance accounts        |
| `GET`  | `/money/accounts/{id}`      | A single finance account                               |
| `GET`  | `/money/accounts/{id}/balances` | Cursor-paginated balance history                   |
| `GET`  | `/devices`                   | List registered push subscriptions                     |
| `GET`  | `/api-tokens`                | List the user's personal access tokens (excluding the app's own session tokens) |

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

### `GET /health/dashboard`

Returns a curated, mobile-ready Health dashboard for the Explore -> Health tab. This endpoint does not replace `/briefing/today` or `/metrics/{metric}`; it aggregates selected Oura, Apple Health, Hevy, metric baseline/trend, and Flint health insight data into a stable dashboard shape.

**Query Parameters**

| Parameter | Type   | Default | Description                                       |
| --------- | ------ | ------- | ------------------------------------------------- |
| `date`    | string | today   | `YYYY-MM-DD`, `today`, `yesterday`, or `tomorrow` |
| `range`   | string | `7d`    | Trend range: `7d`, `30d`, or `90d`                |

Array query parameters and sloppy dates such as `2026-5-18` return `422`.

**Response `200`**

```json
{
    "date": "2026-05-18",
    "timezone": "Europe/London",
    "range": "7d",
    "generated_at": "2026-05-18T19:30:00+00:00",
    "sync_status": {
        "apple_health": {
            "event_count": 28,
            "last_event_time": "2026-05-18T16:39:00+00:00",
            "coverage": "partial"
        }
    },
    "hero": {
        "score": 58,
        "kind": "readiness",
        "status": "critical",
        "title": "Take a lighter day",
        "subtitle": "Readiness is 27.5% below baseline.",
        "primary_event_id": "uuid",
        "factors": [
            {
                "label": "Resting Heart Rate",
                "value": -13,
                "unit": "percent",
                "status": "low"
            }
        ]
    },
    "fitness": {
        "today": {
            "steps": {
                "value": 7411,
                "unit": "steps",
                "vs_baseline_pct": -14.4
            },
            "distance": {
                "value": 6.119,
                "unit": "km",
                "vs_baseline_pct": 6.8
            },
            "active_energy": {
                "value": 606.878,
                "unit": "kcal",
                "vs_baseline_pct": 1.2
            },
            "exercise": { "value": 68, "unit": "min", "vs_baseline_pct": -2.2 },
            "stand": { "value": 8, "unit": "hours", "vs_baseline_pct": -8.5 },
            "workout_count": 5,
            "workout_duration_seconds": 3218,
            "workout_energy_kcal": 365,
            "strength_volume": { "value": 5330, "unit": "kg" }
        },
        "workouts": [
            {
                "event_id": "uuid",
                "source": "apple_health",
                "kind": "cardio",
                "type": "Run",
                "title": "Run",
                "start": "2026-05-18T10:22:54+00:00",
                "end": "2026-05-18T10:37:01+00:00",
                "duration_seconds": 846.921,
                "energy_kcal": 135.695,
                "distance": { "value": 1.976, "unit": "km" },
                "intensity": { "value": 9.498, "unit": "kcal/hr·kg" },
                "route_available": true
            },
            {
                "event_id": "uuid",
                "source": "hevy",
                "kind": "strength",
                "title": "Legs",
                "start": "2026-05-18T09:37:49+00:00",
                "duration_seconds": 0,
                "volume": { "value": 5330, "unit": "kg" },
                "exercises": [
                    {
                        "name": "Leg Press (Machine)",
                        "sets": 4,
                        "volume": { "value": 4200, "unit": "kg" }
                    }
                ]
            }
        ]
    },
    "body_metrics": [
        {
            "id": "apple_health.had_heart_rate_variability.ms",
            "event_id": "uuid",
            "label": "HRV",
            "value": 44.503,
            "unit": "ms",
            "vs_baseline_pct": -16,
            "is_anomaly": false,
            "status": "low"
        }
    ],
    "trends": [
        {
            "metric": "apple_health.had_step_count.steps",
            "label": "Steps",
            "service": "apple_health",
            "action": "had_step_count",
            "unit": "steps",
            "range": { "from": "2026-05-12", "to": "2026-05-18" },
            "daily_values": [
                {
                    "date": "2026-05-18",
                    "value": 7411,
                    "vs_baseline_pct": -14.4,
                    "is_anomaly": false
                }
            ],
            "summary": {
                "min": 7411,
                "max": 7411,
                "mean": 7411,
                "data_points": 1,
                "trend_direction": "up"
            },
            "baseline": {
                "mean": 8658,
                "stddev": 1200,
                "normal_lower": 6258,
                "normal_upper": 11058,
                "sample_days": 60
            }
        }
    ],
    "insights": [
        {
            "block_id": "uuid",
            "event_id": "uuid",
            "title": "Recovery note",
            "content": "Prioritise recovery today.",
            "time": "2026-05-18T12:01:00+00:00"
        }
    ]
}
```

`hero` is `null` when no suitable current-day health metric exists. `fitness.workouts`, `body_metrics`, `trends`, and `insights` are always present arrays. Apple Health workouts are preferred over duplicate Oura workouts when they start within 10 minutes and energy differs by less than 15%; Hevy workouts are always retained.

Status labels are deterministic: `critical`, `low`, `normal`, or `high`. Lower-is-better comparisons are used for resting heart rate, stress, cardiovascular age, and temperature deviation.

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

**Response `200`**

```json
{
    "data": [ CompactEvent, ... ],
    "next_cursor": "2025-01-15T09:30:00+00:00|<uuid>",
    "has_more": true
}
```

**Response `422`** — Invalid domain value or malformed `date` parameter.

See [CompactEvent](#compactevent) for the item schema. Feed items include `tags`, `blocks_count`, and `tldr` (when a `*_tldr` block exists), but do **not** embed the full `blocks` array — tap through to `GET /events/{id}` to retrieve that.

---

### `GET /notifications`

Cursor-paginated reverse-chronological inbox of the user's database notifications.

**Query Parameters**

| Parameter | Type    | Default | Description                         |
| --------- | ------- | ------- | ----------------------------------- |
| `cursor`  | string  | —       | Opaque cursor from a prior response |
| `limit`   | integer | 50      | Items per page (max 200)            |

**Response `200`**

```json
{
    "data": [
        {
            "id": "uuid",
            "title": "Integration Completed",
            "body": "Your Monzo integration completed successfully.",
            "domain": "money",
            "is_read": false,
            "received_at": "2025-01-15T09:30:00.000000Z",
            "entity": {
                "kind": "integration",
                "id": "uuid"
            }
        }
    ],
    "next_cursor": "opaque-cursor",
    "has_more": true
}
```

See [CompactNotification](#compactnotification) for the item schema.

---

### `GET /events/{id}`

Returns a single event by UUID. The response includes the full embedded `blocks` array (not present in feed items).

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

**Response `200`** — flat array (not wrapped in `data`)

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

The `identifier` is formatted as `{service}.{action_without_had_prefix}` (e.g. `oura.sleep_score`). Results are ordered by `service` then `action`. An empty array is returned when no metrics have been computed yet.

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

When `range` is provided it takes precedence over `from`/`to`. Preset mappings: `7d` → last 7 days, `30d` → last 30 days, `90d` → last 90 days, `1y` → last 365 days.

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
        "events": [
            {
                "id": "uuid",
                "kind": "transaction",
                "lat": 51.5225,
                "lng": -0.0745,
                "title": "Craft Metropolis",
                "subtitle": "£30.00",
                "time": "2026-04-25T14:27:02+00:00",
                "service": "monzo"
            }
        ],
        "places": [
            {
                "id": "uuid",
                "kind": "place",
                "lat": 51.52,
                "lng": -0.08,
                "title": "Home",
                "subtitle": null,
                "time": null,
                "service": null
            }
        ]
    }
}
```

`markers.events` and `markers.places` use compact map pin objects, not feed resources. `kind` is one of `place`, `transaction`, `workout`, or `event`. Events without event-level coordinates or a located target object are omitted.

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

Pass `next_cursor` as `since` on the next call. When all arrays are empty, the client is fully up-to-date. Returns up to 200 events per call (`App\Services\Mobile\DeltaSync::DEFAULT_LIMIT`).

---

### `GET /events/filter`

Exact service/action/date-range filtering — the mobile equivalent of MCP's
`get-events-by-filter-tool`, for precise queries that don't suit search.

**Query Parameters**

| Parameter   | Type    | Required | Description                                        |
| ----------- | ------- | -------- | --------------------------------------------------- |
| `service`   | string  | Yes      | e.g. `monzo`, `oura`, `spotify` (max 100)            |
| `action`    | string  | No       | Filter by action (max 255)                          |
| `from_date` | string  | No       | ISO date or relative keyword (max 50)                |
| `to_date`   | string  | No       | ISO date or relative keyword (max 50)                |
| `limit`     | integer | No       | Max results (1–100, default 50)                    |

**Response `200`**

```json
{
    "service": "monzo",
    "action": null,
    "total_count": 128,
    "returned_count": 50,
    "events": [ CompactEvent, ... ]
}
```

---

### `GET /context/day`

Full raw day context — events, metrics, and relationships for a date,
grouped by service/action/hour. This is the larger, unaggregated sibling of
`/briefing/today`; prefer the briefing endpoint unless you need the raw
detail. Mirrors MCP's `get-day-context-tool` and the `day-context-resource`
MCP resource.

**Query Parameters**

| Parameter | Type  | Default | Description                                   |
| --------- | ----- | ------- | ---------------------------------------------- |
| `date`    | string | today  | `YYYY-MM-DD`                                   |
| `domains` | array  | all    | Up to 10 domain strings                        |

**Response `200`**: large structured payload — see
[MCP.md](MCP.md#get-day-context-tool) for the shared shape.

---

### `GET /context/service-status`

Sync coverage and data freshness per service for a date. Mirrors MCP's
`get-service-status-tool`.

**Query Parameters**: `date` (string, default today, `YYYY-MM-DD`).

**Response `200`**: per-service `{event_count, last_event_time, distinct_actions, coverage}` map.

---

### `GET /metrics/baselines`

Baseline statistics (mean, stddev, bounds) for every metric the user has
computed data for — a read-only, agent-friendly discovery payload. Mirrors
MCP's `get-baselines-tool`.

**Response `200`**

```json
{
    "data": [
        {
            "identifier": "oura.sleep_score",
            "display_name": "Sleep Score",
            "mean": 83.1,
            "stddev": 5.2,
            "lower_bound": 72.7,
            "upper_bound": 93.5,
            "window_days": 90,
            "updated_at": "2026-05-10T00:00:00+00:00"
        }
    ]
}
```

---

### `GET /search/{type}`

Typed semantic or keyword search, scoped to one entity kind. `{type}` ∈
`events`, `objects`, `blocks`.

**Query Parameters**

| Parameter     | Type    | Required | Description                                          |
| ------------- | ------- | -------- | ------------------------------------------------------ |
| `query`       | string  | Yes      | Search text (max 500)                                 |
| `semantic`    | boolean | No       | Default `true`                                         |
| `limit`       | integer | No       | 1–50, default 20                                       |
| `service`     | string  | No       | Events only (max 100)                                  |
| `domain`      | string  | No       | Events only (max 100)                                  |
| `concept`     | string  | No       | Objects only (max 100)                                 |
| `object_type` | string  | No       | Objects only (max 100)                                 |
| `block_type`  | string  | No       | Blocks only (max 100)                                  |
| `from_date` / `to_date` | date | No  | Restrict by date                                        |

**Response `200`**

```json
{
    "events": [ { "id": "uuid", "similarity": 0.0842, "...": "full EventResource fields" } ],
    "meta": { "query": "sleep score", "semantic": true, "count": 8, "limit": 20 }
}
```

The result key matches `{type}` (`events`, `objects`, or `blocks`). Note this
endpoint uses the full `EventResource`/`EventObjectResource`/`BlockResource`
shapes (the web API's resources), not the Compact mobile shapes — it's a
richer payload for the typed detail views. `similarity` is only present in
semantic mode.

---

### `GET /tags`

Cursor-paginated list of the user's tags with usage counts, ordered by
total usage then ID.

**Query Parameters**: `q` (string, optional filter, max 255), `cursor`,
`limit` (default 30, max 100).

**Response `200`**

```json
{
    "data": [
        { "id": "12", "name": "running", "type": "spark", "events_count": 42, "objects_count": 3, "total_count": 45 }
    ],
    "next_cursor": "MjA=",
    "has_more": false
}
```

---

### `GET /tags/suggest`

Autocomplete tag suggestions — exact match first, then prefix match, then
by usage.

**Query Parameters**: `q` (string, optional), `limit` (default 10, max 25).

**Response `200`**: `{"data": [Tag, ...]}` — same shape as `GET /tags`.

---

### `GET /tags/{id}`

A single tag (numeric ID) plus a cursor-paginated feed of the events,
objects, and blocks tagged with it, newest first.

**Query Parameters**: `cursor`, `limit` (default 30, max 100).

**Response `200`**

```json
{
    "tag": { "id": "12", "name": "running", "type": "spark", "events_count": 42, "objects_count": 3, "total_count": 45 },
    "data": [
        { "kind": "event", "id": "uuid", "title": "5K Run", "subtitle": "2026-05-10T07:02:00+00:00", "domain": "health" }
    ],
    "next_cursor": null,
    "has_more": false
}
```

**Response `404`** — Tag not found or not associated with the user.

---

### `GET /{kind}/{id}/relationships`

Lists relationships attached to an owned event, object, or block. `{kind}` ∈
`events`, `objects`, `blocks`.

**Response `200`**: `{"data": [Relationship, ...]}` — see
[API_v1.md](API_v1.md#relationship) for the shape (identical on both
surfaces).

**Response `404`** — Entity not found or not owned by the caller.

---

### `GET /settings/notifications`

Current notification preferences.

**Response `200`**

```json
{
    "categories": {
        "anomaly": true,
        "digest": true,
        "integration_failed": true,
        "new_bookmark": true,
        "calendar_event": true
    },
    "delivery_mode": "immediate",
    "digest_time": "08:00"
}
```

The write counterpart, `PATCH /settings/notifications`, is handled by a
separate controller (`NotificationSettingsController`, not
`NotificationPreferencesController`) — see [Write Endpoints](#write-endpoints).

---

### `GET /check-ins`

Morning/afternoon check-in completion status for a date.

**Query Parameters**: `date` (required, `YYYY-MM-DD`).

**Response `200`**

```json
{
    "date": "2026-05-10",
    "morning": { "completed": true, "event": CompactEvent },
    "afternoon": { "completed": false, "event": null }
}
```

---

### `GET /check-ins/history`

Day-by-day check-in summary for a date range.

**Query Parameters**: `from`, `to` (both required, `YYYY-MM-DD`, `to` ≥ `from`).

**Response `422`** — Range exceeds 90 days: `{"message": "Date range may not exceed 90 days."}`

**Response `200`**

```json
{
    "from": "2026-05-01",
    "to": "2026-05-10",
    "days": [
        {
            "date": "2026-05-01",
            "morning": { "completed": true, "physical": 4, "mental": 3, "combined": 3.5, "notes": null, "event_id": "uuid" },
            "afternoon": { "completed": false }
        }
    ]
}
```

---

### `GET /check-ins/timezone`

Returns the user's effective timezone state. When a `time_travel`
acknowledgement exists (see `POST /check-ins/timezone`), `source` is
`time_travel`; otherwise it falls back to the profile timezone.

**Response `200`**: `{"timezone": "Europe/London", "source": "profile"}` (shape from `DailyCheckinPlugin::resolveEffectiveTimezone`).

---

### `GET /up-to-speed`

Ordered, typed catch-up queue: `flint_digest` → `check_in` → `anomaly` →
`news_summary` items, each with a `caught_up_at` timestamp.

**Response `200`**: see [API_v1.md](API_v1.md#get-apiv1up-to-speed) for the
full shape (identical on both surfaces).

---

### `GET /flint/digests`

Flint digest(s) for a date. Defaults to today's most recent; `all=true`
returns every digest created that day.

**Query Parameters**: `date` (default today), `period` (`morning`/`afternoon`/`evening`), `all` (boolean).

**Response `200`**: [FlintDigest](API_v1.md#flintdigest) — see API_v1.md for the full shape (identical on both surfaces).

**Response `404`** — No digest found for that date/period.

---

### `GET /flint/digests/{id}`

A single digest by event UUID. Same [FlintDigest](API_v1.md#flintdigest) shape.

---

### `GET /money/accounts`

All non-archived finance accounts (manual and synced) with their latest
balance.

**Response `200`**: `{"data": [MoneyAccount, ...]}` — see
[MoneyAccount](API_v1.md#moneyaccount).

---

### `GET /money/accounts/{id}`

A single account. Same [MoneyAccount](API_v1.md#moneyaccount) shape, wrapped
in `{"data": ...}`.

---

### `GET /money/accounts/{id}/balances`

Cursor-paginated balance history, newest first (25 per page).

**Response `200`**: `{"data": [BalanceEntry, ...], "next_cursor": "...", "has_more": false}` — see [BalanceEntry](API_v1.md#balanceentry).

---

## Write Endpoints

All write endpoints require `ios:write` ability.

### Summary

| Method   | Path                               | Description                       |
| -------- | ---------------------------------- | --------------------------------- |
| `POST`   | `/devices`                         | Register an APNs device token     |
| `DELETE` | `/devices/{id}`                    | Unregister a device               |
| `POST`   | `/health/samples`                  | Ingest HealthKit samples (batch)  |
| `POST`   | `/live-activities`                 | Start a Live Activity             |
| `PATCH`  | `/live-activities/{id}`            | Push a Live Activity update       |
| `DELETE` | `/live-activities/{id}`            | End a Live Activity               |
| `POST`   | `/live-activities/{id}/tokens`     | Rotate a Live Activity push token |
| `POST`   | `/check-ins`                       | Submit a daily mood check-in      |
| `POST`   | `/anomalies/{id}/acknowledge`      | Acknowledge a metric anomaly      |
| `POST`   | `/knowledge/events/{id}/reprocess` | Queue knowledge AI reprocessing   |
| `POST`   | `/notifications/{id}/read`         | Mark one notification as read     |
| `POST`   | `/notifications/read-all`          | Mark all notifications as read    |
| `DELETE` | `/notifications/{id}`              | Delete one notification           |
| `PATCH`  | `/{kind}/{id}`                      | Non-destructive update of an owned event/object/block |
| `PATCH`  | `/events/{id}/note`                | Set or clear an event's note       |
| `PATCH`  | `/{kind}/{id}/location`            | Set a location on an owned event/object |
| `DELETE` | `/{kind}/{id}/location`            | Clear a location                   |
| `POST`   | `/{kind}/{id}/location/geocode`    | Geocode an address and set it as the location |
| `POST`   | `/events/{id}/tags`                | Attach a tag to an event            |
| `DELETE` | `/events/{id}/tags/{tagId}`        | Detach a tag from an event          |
| `POST`   | `/objects/{id}/tags`               | Attach a tag to an object           |
| `DELETE` | `/objects/{id}/tags/{tagId}`       | Detach a tag from an object         |
| `POST`   | `/integrations/{id}/sync`          | Trigger an immediate fetch for one integration |
| `POST`   | `/integrations/sync`               | Trigger an immediate fetch for all instances of a service |
| `POST`   | `/integrations/{id}/oauth/start`   | Start a PKCE re-authentication flow (mobile-only) |
| `POST`   | `/{kind}/{id}/relationships`       | Create a relationship from an owned entity |
| `DELETE` | `/relationships/{relationship}`    | Delete an owned relationship        |
| `PATCH`  | `/settings/notifications`         | Update notification preferences     |
| `POST`   | `/check-ins/timezone`              | Record an acknowledged timezone change |
| `POST`   | `/check-ins/media`                 | Upload a check-in photo (raw binary body) |
| `POST`   | `/up-to-speed/read`                | Mark Up to Speed items as caught up  |
| `POST`   | `/flint/digests`                    | Create a Flint digest               |
| `POST`   | `/flint/questions/{block}/answer`  | Answer a Flint user-question block  |
| `POST`   | `/bookmarks`                        | Bookmark a URL                      |
| `POST`   | `/money/accounts`                   | Create a manual finance account      |
| `PATCH`  | `/money/accounts/{id}`             | Update a manual finance account      |
| `DELETE` | `/money/accounts/{id}`             | Archive a manual finance account     |
| `POST`   | `/money/accounts/{id}/balances`    | Add a balance entry                  |
| `POST`   | `/devices/test`                     | Send a test push notification        |
| `POST`   | `/api-tokens`                        | Create a personal access token         |
| `DELETE` | `/api-tokens/{id}`                   | Revoke a personal access token         |

---

### `PATCH /{kind}/{id}`

Non-destructive update of an owned event, object, or block. `{kind}` ∈
`events`, `objects`, `blocks`. Requires `If-Match` with the entity's current
`ETag`. Same `EntityMutationService` and allow-list MCP's `update-entity`
tool uses — see [MCP.md](MCP.md#update-entity) for the exact allowed fields
per kind.

**Response `200`**: the updated entity in its Compact resource shape.

**Response `404`** — Not found or not owned. **Response `422`** — Disallowed
field or invalid value. **Response `428`/`412`** — Missing/stale `If-Match`.

---

### `PATCH /events/{id}/note`

Sets or clears the user-authored note block on an event. Requires
`If-Match`. Same behavior as MCP's `set-event-note`.

---

### `PATCH /{kind}/{id}/location`

Sets a location on an owned event or object (`{kind}` ∈ `events`, `objects`
— not `blocks`). Requires `If-Match`. Mobile-only — not exposed on
`/api/v1` or MCP.

**Request Body**: `{"latitude": 51.5074, "longitude": -0.1278, "address": "London, UK"}` (`latitude`/`longitude` required, `address` optional, max 500 chars).

**Response `200`**: the updated entity (`CompactEvent` or `CompactObject`).

---

### `DELETE /{kind}/{id}/location`

Clears a location from an owned event or object. Requires `If-Match`.

**Response `200`**: the updated entity.

---

### `POST /{kind}/{id}/location/geocode`

Geocodes an address and sets it as the entity's location. Requires
`If-Match`.

**Request Body**: `{"address": "10 Downing Street, London"}` (required, max 500 chars).

**Response `200`**: the updated entity.

**Response `422`** — Entity not found or the address could not be geocoded.

---

### `POST /events/{id}/tags` / `DELETE /events/{id}/tags/{tagId}`

Attach or detach a tag on an owned event. Requires `If-Match`.

**Request Body (`POST`)**: either `{"tag_id": 12}` to attach an existing tag,
or `{"name": "running", "type": "spark"}` to find-or-create one. A
`type_name` or `type:name` prefix in `name` is parsed into `type`
automatically when `type` is omitted; a single-emoji name is typed `emoji`;
otherwise it defaults to `spark`.

**Response `201`**: `{"tag": Tag, "tags": [Tag, ...]}` (the entity's full tag
list), with a fresh `ETag`.

**Response `404`** — Entity or `tag_id` not found. **Response `422`** —
Neither `tag_id` nor a resolvable `name` supplied.

---

### `POST /objects/{id}/tags` / `DELETE /objects/{id}/tags/{tagId}`

Same as the event variants, scoped to an owned object.

---

### `POST /integrations/{id}/sync`

Triggers an immediate fetch for one integration instance.

**Response `200`**: `{"message": "Integration update triggered.", "jobs_dispatched": 2}`

**Response `422`** — Integration is paused.

---

### `POST /integrations/sync`

Triggers an immediate fetch for every non-paused integration matching a
service name.

**Request Body**: `{"service": "oura"}` (required, max 100 chars).

**Response `200`**: `{"service": "oura", "integrations": [{"integration_id": "uuid", "status": "triggered", "jobs_dispatched": 1}], "total_jobs_dispatched": 1}`

**Response `404`** — No integrations found for that service.

---

### `POST /integrations/{id}/oauth/start`

Starts a PKCE re-authentication flow for an OAuth-backed integration.
**Mobile-only** — flags the integration's group as a mobile-initiated
reauth so the shared web OAuth callback redirects back to the `spark://`
custom scheme instead of the web session flow.

**Response `200`**: `{"url": "https://provider.example/oauth/authorize?..."}` — open in `ASWebAuthenticationSession`.

**Response `422`** — Integration has no connected group, an unknown
service, a non-OAuth plugin, or the provider URL could not be built.

---

### `POST /{kind}/{id}/relationships`

Creates a relationship from an owned event/object/block to another entity.
Requires `If-Match`. Prevents self-links and enforces registered
relationship-type directionality — same rules as MCP's
`manage-relationship` create operation.

**Request Body**: `{"to_kind": "objects", "to_id": "uuid", "type": "linked_to", "value": null, "value_multiplier": null, "value_unit": null, "metadata": {}}`

**Response `201`**: [Relationship](API_v1.md#relationship).

**Response `422`** — Invalid endpoints, unregistered type, or ownership mismatch.

---

### `DELETE /relationships/{relationship}`

Deletes an owned relationship by UUID. Requires `If-Match`.

**Response `204`** — No content. **Response `404`** — Not found or not owned.

---

### `PATCH /settings/notifications`

Updates notification preferences. Requires `If-Match`. Handled by
`NotificationSettingsController` — a separate class from the
`NotificationPreferencesController` that serves the `GET`, kept distinct
because the read and write payload/validation shapes evolved independently.

**Request Body**

```json
{
    "categories": { "anomaly": true, "digest": false },
    "delivery_mode": "work_hours",
    "digest_time": "08:00"
}
```

`delivery_mode` is required (`immediate`, `work_hours`, `daily_digest`).
Each `categories.*` key is required unless `delivery_mode` is `work_hours`.

**Response `204`** when `delivery_mode` is `work_hours`; otherwise
**Response `200`** with the updated preferences (same shape as `GET`).

---

### `POST /check-ins/timezone`

Atomically records a user-acknowledged change to the effective timezone as
a `time_travel` check-in event. Idempotent: resubmitting the
already-effective timezone is a no-op (`200`, not `201`). The prior
timezone is derived server-side — a contradictory client
`previous_timezone` is ignored. The user's profile timezone is never
mutated.

**Request Body**: `{"timezone": "Europe/Paris", "previous_timezone": "Europe/London", "device_id": "optional"}` (`timezone` required, valid IANA identifier; `previous_timezone` optional and informational only).

**Response `200`** (no-op, already effective) or **Response `201`** (new
acknowledgement recorded): the resulting `{"timezone": "...", "source": "time_travel", ...}` state.

---

### `POST /check-ins/media`

Uploads a check-in photo from the iOS share extension's background upload
task. **Not multipart form data** — the request body is the raw image
bytes, with `Content-Type` set to the image's MIME type.

**Headers**: `Content-Type` must be one of `image/jpeg`, `image/png`,
`image/heic`, `image/heif`, `image/webp`.

**Query Parameters**: `date` (`YYYY-MM-DD`, optional, defaults to today).

**Response `201`**: [CompactEvent](#compactevent) for the day's check-in,
with the photo attached.

**Response `415`** — Unsupported `Content-Type`. **Response `422`** — Empty
body or over the 25 MB cap.

---

### `POST /up-to-speed/read`

Marks one or more Up to Speed items as caught up. Idempotent — reposting
the same items is a no-op.

**Request Body**: `{"items": [{"type": "flint_digest", "id": "uuid"}, ...]}` (1–50 items; `type` ∈ `flint_digest`, `anomaly`, `news_summary`).

**Response `200`**: `{"marked": 2}` — count of newly-marked items.

---

### `POST /flint/digests`

Creates a Flint digest event with attached blocks. **Non-idempotent** — do
not blindly retry after an unknown outcome. Same request shape as MCP's
`create-flint-digest` tool — see
[MCP.md](MCP.md#create-flint-digest) for the full block-type reference.

**Response `201`**: `{"event_id": "uuid", "block_ids": ["uuid", ...]}`

---

### `POST /flint/questions/{block}/answer`

Records the user's answer to a `flint_user_question` block.

**Request Body**: `{"answer": "Yes", "answer_note": "optional"}` (`answer` required, max 1000 chars; `answer_note` optional, max 1000 chars).

**Response `200`**: `{"block_id": "uuid", "answer": "Yes", "answer_note": null, "answered_at": "..."}`

**Response `403`** — Block's digest doesn't belong to the caller.
**Response `422`** — Block is not a `flint_user_question`.

---

### `POST /bookmarks`

Bookmarks a URL shared from the iOS share extension. Delegates to the same
service as the legacy `POST /api/fetch/bookmarks` endpoint.

**Request Body**: `{"url": "https://example.com/article"}` (required, valid URL, max 2048 chars).

**Response `201`/`200`**: `{"state": "...", "bookmark": {"id": "uuid", "url": "..."}}` (`201` when newly created, `200` when it already existed).

**Response `422`** — URL fails the safety validator.

---

### `POST /money/accounts`

Creates a manual finance account.

**Request Body**

```json
{
    "name": "Joint Savings",
    "account_type": "savings_account",
    "currency": "GBP",
    "provider": "Monzo",
    "interest_rate": 4.1,
    "start_date": "2024-01-01"
}
```

`account_type` ∈ `current_account`, `savings_account`, `mortgage`,
`investment_account`, `credit_card`, `loan`, `pension`, `other`; `currency`
∈ `GBP`, `USD`, `EUR`. `is_negative_balance` is forced `true` for
`credit_card`/`loan`/`mortgage` regardless of what's sent.

**Response `201`**: `{"data": MoneyAccount}` (no balance yet).

---

### `PATCH /money/accounts/{id}`

Updates a manual account (partial — all fields `sometimes`, same allow-list
as `POST`). Requires `If-Match`. Fields under `metadata.integration_id` /
`account_id` / `pot_id` / `raw` are always preserved.

**Response `422`** — Account is not `manual_account` (synced accounts
can't be edited here).

---

### `DELETE /money/accounts/{id}`

Archives (never hard-deletes) a manual account: writes a final
zero-balance event with a note, then sets `metadata.deleted = true` and
`metadata.archived_at`. Requires `If-Match`.

**Response `200`**: `{"message": "Account archived."}`

**Response `422`** — Account is not `manual_account`.

---

### `POST /money/accounts/{id}/balances`

Adds a balance entry and touches the account (advancing its ETag for
subsequent `If-Match` writes). Requires `If-Match`.

**Request Body**: `{"balance": 1500.00, "date": "2026-05-10", "notes": "optional, max 1000 chars"}`

**Response `201`**: `{"data": BalanceEntry}`

---

### `GET /devices`

Lists all iOS push subscriptions registered for the authenticated user.

**Response `200`**

```json
{
    "devices": [
        {
            "id": 1,
            "name": "iPhone",
            "platform": "ios",
            "last_seen_at": "2026-05-10T09:00:00+00:00",
            "is_current_device": false,
            "device_type": "ios",
            "endpoint": "aaaa...64hexchars",
            "app_environment": "sandbox",
            "bundle_id": "co.cronx.spark",
            "app_version": "1.0.0",
            "os_version": "18.0",
            "created_at": "2026-05-01T09:00:00+00:00",
            "updated_at": "2026-05-10T09:00:00+00:00"
        }
    ]
}
```

`is_current_device` is always `false` — the server has no notion of "this
request's device" for a bearer-token API. `name` and `platform` are always
present and non-null (the iOS `RegisteredDevice` decoder requires them);
the remaining fields are retained for web/admin consumers.

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

### `POST /devices/test`

Sends a test push notification to every iOS device registered for the
authenticated user.

**Response `204`** — No content.

**Response `400`** — No iOS push subscriptions registered.

---

### `GET /api-tokens`

Lists the user's personal access tokens for use outside the app (e.g.
against the general REST API or MCP). Never returns plaintext secrets, and
never includes the app's own `ios:read`/`ios:write` session tokens — this
endpoint can't be used to inspect or revoke the mobile app's own session.
**Mobile-only** — API-token administration is otherwise web-settings-only
(see [README.md](README.md)); it is not exposed on `/api/v1` or MCP.

**Response `200`**

```json
[
    {
        "id": "3",
        "name": "Zapier integration",
        "abilities": ["data:read", "insights:read"],
        "last_used_at": "2026-05-09T12:00:00+00:00",
        "created_at": "2026-04-01T09:00:00+00:00"
    }
]
```

---

### `POST /api-tokens`

Creates a personal access token and returns its one-time plaintext secret.

**Request Body**

```json
{
    "name": "Zapier integration",
    "abilities": ["data:read", "insights:read"]
}
```

`name` is required (max 255 chars). `abilities` is optional (up to 20
distinct strings); omit it for a full-access (`*`) token. Any
`ios:read`/`ios:write` ability in the request is silently dropped — a
mobile-managed token can never grant itself the app's own session scopes.
If dropping those leaves the list empty, the token falls back to `*`.

**Response `201`**

```json
{
    "id": "3",
    "name": "Zapier integration",
    "plaintext": "1|abc123def456..."
}
```

`plaintext` is shown only in this response — it cannot be retrieved again.

---

### `DELETE /api-tokens/{id}`

Revokes a personal access token.

**Response `204`** — No content.

**Response `404`** — Token not found, or it's one of the app's own
`ios:read`/`ios:write` session tokens (not revocable through this endpoint).

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

### `POST /notifications/{id}/read`

Marks a single notification as read.

**Response `204`** — No content.

**Response `404`** — Notification not found or belongs to another user.

---

### `POST /notifications/read-all`

Marks all unread notifications for the authenticated user as read.

**Response `204`** — No content.

---

### `DELETE /notifications/{id}`

Deletes a single notification from the authenticated user's inbox.

**Response `204`** — No content.

**Response `404`** — Notification not found or belongs to another user.

### `POST /knowledge/events/{id}/reprocess`

Queues AI reprocessing for a Fetch or Newsletter knowledge event owned by the authenticated user.

`{id}` is the UUID of the event to repair.

**Request Body**

```json
{
    "mode": "auto"
}
```

| Field  | Type   | Required | Description                                               |
| ------ | ------ | -------- | --------------------------------------------------------- |
| `mode` | string | No       | `auto`, `summary_only`, or `refetch`. Defaults to `auto`. |

**Modes**

| Mode           | Description                                                                            |
| -------------- | -------------------------------------------------------------------------------------- |
| `auto`         | Prefer the earliest available pipeline step: extract from raw content, then summarize. |
| `summary_only` | Generate TLDR/summary blocks from existing extracted content.                          |
| `refetch`      | Fetch-only. Force-refresh the original URL before extraction and summaries.            |

Newsletter events cannot use `refetch`.

**Response `202`**

```json
{
    "event_id": "550e8400-e29b-41d4-a716-446655440000",
    "service": "fetch",
    "status": "queued",
    "mode": "auto"
}
```

**Response `404`** — Knowledge event not found or belongs to another user.

**Response `422`** — Unsupported event, invalid mode, missing integration, missing source content, or newsletter `refetch`.

---

## Response Schemas

These schemas are stable contracts. The iOS client decodes them into Swift structs — shape changes require an explicit migration.

`Relationship`, `FlintDigest`, `MoneyAccount`, and `BalanceEntry` are
documented once in [API_v1.md](API_v1.md#shared-response-schemas) since
both surfaces use the identical resource classes — see there for those
shapes.

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
    "action": "had_sleep_score",
    "display_name": "Sleep Score",
    "display_with_object": true,
    "hidden": false,
    "value": "82",
    "unit": "score",
    "display_value": "82 score",
    "url": "https://...",
    "actor": {
        "id": "uuid",
        "title": "Oura Ring",
        "concept": "device",
        "type": "oura_device",
        "media_url": "https://..."
    },
    "target": {
        "id": "uuid",
        "title": "Sleep Session",
        "concept": "session",
        "type": "sleep_session",
        "media_url": null
    },
    "tags": [
        { "name": "running", "type": null }
    ],
    "tldr": "Optional single-sentence summary from any *_tldr block.",
    "blocks_count": 3,
    "blocks": [ CompactBlock, ... ]
}
```

**Field notes:**

| Field                 | Always present | Description                                                                    |
| --------------------- | -------------- | ------------------------------------------------------------------------------ |
| `display_name`        | Yes            | Human-readable action label from plugin registry                               |
| `display_with_object` | Yes            | `true` if UI should include the related object title when rendering the action |
| `hidden`              | Yes            | `true` if this action should be hidden in default UI (e.g. balance updates)    |
| `value`               | No             | Formatted numeric value (applies `value_multiplier`); omitted when no value    |
| `unit`                | No             | Unit string; omitted when no value                                             |
| `display_value`       | No             | Fully formatted string, e.g. `"£10.50"`; omitted when no value                 |
| `url`                 | No             | Omitted when not set on the event                                              |
| `actor`               | No             | Omitted when not set; `media_url` within may be `null`                         |
| `target`              | No             | Omitted when not set; `media_url` within may be `null`                         |
| `tldr`                | No             | Content of the first block whose `block_type` contains `tldr`; any domain      |
| `tags`                | Yes            | Always an array (empty when no tags); each item has `name` and `type`          |
| `blocks_count`        | Feed only      | Integer count of attached blocks; present in `/feed`, absent in `/events/id`   |
| `blocks`              | Detail only    | Full block array; present in `GET /events/{id}`, absent in `/feed`             |

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
    "content": "Optional text content",
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
    "action": "had_sleep_score",
    "unit": "score",
    "event_count": 365,
    "mean": 83.1,
    "last_event_at": "2025-01-15T00:00:00+00:00"
}
```

`identifier` is `{service}.{action_without_had_prefix}`, e.g. `oura.sleep_score`. `mean` is `null` when insufficient data exists. `domain` is derived from the service/action and can be used for colour-coding (`health`, `activity`, `money`, `media`, `knowledge`, `online`).

### CompactNotification

```json
{
    "id": "uuid",
    "title": "Integration Completed",
    "body": "Your Monzo integration completed successfully.",
    "domain": "money",
    "is_read": false,
    "received_at": "2025-01-15T09:30:00.000000Z",
    "entity": {
        "kind": "integration",
        "id": "uuid"
    }
}
```

| Field         | Type    | Description                                                |
| ------------- | ------- | ---------------------------------------------------------- |
| `id`          | UUID    | Database notification ID                                   |
| `title`       | string  | Notification title, defaults to `"Notification"` if absent |
| `body`        | string  | Optional message body                                      |
| `domain`      | string  | Optional Spark domain, when the notification carries one   |
| `is_read`     | boolean | `true` when `read_at` is set                               |
| `received_at` | string  | ISO timestamp for notification creation                    |
| `entity`      | object  | Optional deep-link target with `kind` and `id`             |

`body`, `domain`, and `entity` are `null` when not present. `entity.kind` is one of `event`, `object`, `metric`, `place`, `anomaly`, or `integration`.

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

- [README.md](README.md) - Cross-surface capability model and parity matrix
- [API_v1.md](API_v1.md) - General REST API (events, search, integrations, finance)
- [MOBILE_CHECK_INS.md](MOBILE_CHECK_INS.md) - Check-in domain deep dive
- [MCP.md](MCP.md) - MCP server and tool reference
- [NOTIFICATIONS.md](../Architecture/NOTIFICATIONS.md) - Push notification system
- [PLACES.md](../Architecture/PLACES.md) - Geographic place tracking
- [EVENTS.md](../Architecture/EVENTS.md) - Event data model
- [OBJECTS.md](../Architecture/OBJECTS.md) - EventObject data model
