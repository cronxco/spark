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

All errors return JSON with a top-level `message`:

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

**Validation failures** (`422`) use Laravel's standard field-level shape — machine-readable, one entry per invalid field:

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "date": ["The date field must match the format Y-m-d."]
    }
}
```

Hand-rolled `422`s (a small number of endpoints validate something Laravel's rule set can't express, e.g. a comma-separated `status` list) return the plain `{"message": "..."}` shape instead — there is no `errors` object in that case, since there's no single field to attach it to.

**Known inconsistency:** most endpoints use `message` as the top-level key. A handful of older Flint endpoints (`GET /flint/digests`, `GET /flint/digests/{id}`, `POST /flint/questions/{block}/answer`) instead return `{"error": "..."}`. New endpoints should use `message`; the `error` key on those three is kept for existing clients and not a pattern to copy.

**Which endpoints return which codes:** every endpoint returns `401` (missing/invalid token) and `403` (missing ability) via the shared guard middleware — those aren't called out per-endpoint below. `404` is returned by any endpoint that resolves an `{id}` the caller doesn't own or that doesn't exist. `422` is returned by any endpoint with a request body or query parameters to validate. Anything narrower than that (a specific business-rule `422`, a `409` conflict, a `428` precondition-required) is documented in that endpoint's own section below.

### HTTP Status Codes

| Status | Meaning                                             |
| ------ | --------------------------------------------------- |
| `200`  | Success                                             |
| `201`  | Resource created                                    |
| `204`  | Success, no content                                 |
| `304`  | Not modified (ETag match)                           |
| `401`  | Missing or invalid token                            |
| `403`  | Token lacks required ability                        |
| `404`  | Resource not found or feature flag disabled         |
| `422`  | Validation failure                                  |
| `428`  | Precondition required (missing `If-Match`)          |
| `409`  | Conflict (`If-Match` doesn't match current version) |
| `429`  | Rate limit exceeded                                 |

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

Every collection endpoint uses this same envelope and accepts `cursor`/`limit`, including `GET /flint/topics` and `GET /money/accounts` — both short lists today, paginated so growth never needs a breaking response-shape change later. Their cursors are an opaque offset rather than the `{time}|{id}` shape above (there's no stable per-row timestamp/id ordering to key on the way there is for events), but they follow the same contract: pass `next_cursor` back as `cursor`, stop when `has_more` is `false`.

### Timezone Contract

Every day-scoped endpoint resolves "today" — and states which zone it used — the same way: the user's **effective timezone**, which is their acknowledged time-travel zone if one is set (see `check-ins/timezone`), falling back to their profile timezone, falling back to UTC. This is `EffectiveTimezoneResolver`, used everywhere a day boundary is computed.

The field carrying that zone is named `effective_timezone`:

- `GET /briefing/today` — top-level `effective_timezone` (also keeps `timezone` for existing clients; same value).
- `GET /flint/digests` (list and `all=true`), `GET /flint/digests/{id}`, `GET /flint/digests/latest` — top-level `effective_timezone` per digest.
- `GET /flint/digests?from=&to=` (history) and `GET /flint/questions` — `meta.effective_timezone`.
- `GET /check-ins/timezone` — `timezone` (the endpoint's entire purpose is reporting this value, so the field predates the `effective_timezone` name; same resolution).

A client assembling one screen from several of these endpoints can rely on them agreeing — they resolve through the same service.

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

| Method | Path                            | Description                                                                     |
| ------ | ------------------------------- | ------------------------------------------------------------------------------- |
| `GET`  | `/ping`                         | Health check                                                                    |
| `GET`  | `/me`                           | Authenticated user profile                                                      |
| `GET`  | `/briefing/today`               | Daily summary across all domains                                                |
| `GET`  | `/health/dashboard`             | Fitness-first Health tab dashboard                                              |
| `GET`  | `/feed`                         | Cursor-paginated reverse-chronological event feed                               |
| `GET`  | `/notifications`                | Cursor-paginated notifications inbox                                            |
| `GET`  | `/events/{id}`                  | Single event                                                                    |
| `GET`  | `/objects/{id}`                 | Single object with optional recent events                                       |
| `GET`  | `/blocks/{id}`                  | Single block                                                                    |
| `GET`  | `/metrics`                      | All available metric identifiers and metadata                                   |
| `GET`  | `/metrics/{metric}`             | Metric trend with baseline and daily values                                     |
| `GET`  | `/widgets/today`                | Compact today widget payload (≤4 KB)                                            |
| `GET`  | `/widgets/metrics/{metric}`     | Tiny sparkline widget for a single metric                                       |
| `GET`  | `/widgets/spend`                | Today's spend widget                                                            |
| `GET`  | `/search`                       | Multi-mode search                                                               |
| `GET`  | `/integrations`                 | List all user integrations                                                      |
| `GET`  | `/integrations/{id}`            | Single integration                                                              |
| `GET`  | `/places/{id}`                  | Single place (geo-aware EventObject)                                            |
| `GET`  | `/map/data`                     | Geo-located events and places within a bounding box                             |
| `GET`  | `/sync/delta`                   | Incremental sync of changed events since a cursor                               |
| `GET`  | `/events/filter`                | Exact service/action/date-range event filtering, matching MCP                   |
| `GET`  | `/context/day`                  | **Deprecated** — full raw day context; superseded by `/briefing/today`          |
| `GET`  | `/context/service-status`       | **Deprecated** — sync coverage/freshness; superseded by `/briefing/today`       |
| `GET`  | `/metrics/baselines`            | Baseline statistics for every computed metric                                   |
| `GET`  | `/search/{type}`                | Typed semantic/keyword search (`events`, `objects`, or `blocks`)                |
| `GET`  | `/tags`                         | Cursor-paginated list of the user's tags                                        |
| `GET`  | `/tags/suggest`                 | Autocomplete tag suggestions                                                    |
| `GET`  | `/tags/{id}`                    | A single tag plus the items tagged with it                                      |
| `GET`  | `/{kind}/{id}/relationships`    | List relationships on an owned event, object, or block                          |
| `GET`  | `/settings/notifications`       | Current notification preferences                                                |
| `GET`  | `/check-ins`                    | Morning/afternoon check-in status for a date                                    |
| `GET`  | `/check-ins/history`            | Check-in history for a date range (max 90 days)                                 |
| `GET`  | `/check-ins/timezone`           | Effective timezone state (profile or time-travel override)                      |
| `GET`  | `/up-to-speed`                  | Ordered catch-up queue (Flint digests, check-ins, anomalies, news)              |
| `GET`  | `/flint/digests`                | Flint digest(s) for a date or cursor-paginated 30-day range                     |
| `GET`  | `/flint/digests/{id}`           | A single Flint digest                                                           |
| `GET`  | `/flint/digests/latest`         | The single most recent digest across dates                                      |
| `GET`  | `/flint/questions`              | Cursor-paginated questions across digests (`status`, `since` filters)           |
| `GET`  | `/flint/topics`                 | Flint's long-lived strategic/thematic/tactical threads (cursor-paginated)       |
| `GET`  | `/flint/topics/{id}`            | A Thread with versioned digest/block evidence                                   |
| `GET`  | `/flint/notes`                  | Cursor-paginated user-authored Flint notes                                      |
| `GET`  | `/flint/routines/health`        | Configuration, attempt, output, and scheduling health for Flint routines        |
| `GET`  | `/money/accounts`               | All non-archived manual/synced finance accounts (cursor-paginated)              |
| `GET`  | `/money/accounts/{id}`          | A single finance account                                                        |
| `GET`  | `/money/accounts/{id}/balances` | Cursor-paginated balance history                                                |
| `GET`  | `/money/net-worth`              | Current net worth and its change over a comparison window                       |
| `GET`  | `/devices`                      | List registered push subscriptions                                              |
| `GET`  | `/api-tokens`                   | List the user's personal access tokens (excluding the app's own session tokens) |

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

Carries a strong `ETag` for the user resource. This is the value to echo as
`If-Match` on routes guarded by `if-match:user`, currently
`PATCH /settings/notifications`.

---

### `POST /logout`

Ends the calling session server-side: revokes the paired OAuth refresh token
and deletes the access token presenting the request.

Requires only `ios:read`, so a read-only session can still sign itself out, and
carries **no `If-Match`** — signing out must never be blocked by a
precondition.

Scoped to the current credential alone. Other devices and any personal access
tokens the user created separately are untouched.

**Response `204`** — No content. The bearer token used for this request is now
invalid and will return `401`.

The client is responsible for the local half of sign-out — Keychain, SwiftData,
App Group defaults, Core Spotlight, widgets and delivered notifications. See
`docs/P0_CONTAINMENT_PROPOSALS.md` in `spark-ios`.

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
    "effective_timezone": "Europe/London",
    "sync_status": {
        "oura": {
            "event_count": 6,
            "last_event_time": "2025-01-15T07:12:00+00:00",
            "actions": ["had_sleep_score", "had_readiness_score"],
            "as_of": "2025-01-15T07:15:00+00:00",
            "stale": false
        },
        "apple_health": {
            "event_count": 0,
            "last_event_time": null,
            "actions": [],
            "as_of": null,
            "stale": true
        }
    },
    "sections": {
        "health": { ... },
        "activity": { ... },
        "money": {
            "transactions": [
                {
                    "event_id": "uuid",
                    "merchant": "Tesco",
                    "amount": 25.5,
                    "currency": "GBP",
                    "action": "card_payment_to",
                    "service": "monzo",
                    "time": "2025-01-15T12:00:00Z",
                    "direction": "out"
                }
            ],
            "total_spend": 25.5,
            "internal_transfers": 0,
            "total_in": 0,
            "total_spend_vs_baseline_pct": -8.2
        },
        "media": { ... },
        "knowledge": { ... }
    },
    "anomalies": [ ... ]
}
```

The shape of each section is domain-specific and driven by `DaySummaryService`.

**`sync_status`** carries one entry per service the user has connected — including a service with nothing to report today, which is different from a service that's behind and must be distinguishable from it:

| Field             | Type                                 | Description                                                                                                                |
| ----------------- | ------------------------------------ | -------------------------------------------------------------------------------------------------------------------------- |
| `event_count`     | integer                              | Events from this service on this day.                                                                                      |
| `last_event_time` | string\|null                         | ISO timestamp of the newest event that day, or `null` if none.                                                             |
| `actions`         | string[]                             | Distinct actions seen that day.                                                                                            |
| `as_of`           | string\|null                         | When the server last **successfully reached** the service — not the same as `last_event_time`. `null` if never synced.     |
| `stale`           | boolean                              | The server's own judgement that this service is behind, using the cadence it knows that integration runs at.               |
| `coverage`        | `"complete"`\|`"partial"` (optional) | Only present for services whose data can arrive partially within a day (currently `apple_health`); absent everywhere else. |

**`sections.money`** splits money movement by where it actually went, rather than reporting one `total_spend` that mixes real spend with internal transfers:

- `total_spend` — outflow to third parties. Moving money between the user's own accounts/pots is excluded.
- `internal_transfers` — movement between the user's own accounts and pots (e.g. a savings sweep, a pot withdrawal).
- `total_in` — inbound from third parties.
- Each entry in `transactions` carries its own resolved `direction` (`"in"`\|`"out"`\|`"internal"`\|`"excluded"`\|`"unknown"`) — same values as the `direction` field on [CompactEvent](#compactevent) money events in `GET /feed`.
- `total_spend_vs_baseline_pct` — the day's spend against the user's own daily-spend history (mean over the trailing 60 days, minimum 5 days with any spend). When there isn't enough history yet, `total_spend_baseline_unavailable_reason: "insufficient_history"` is present instead.

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
            },
            "version": "\"9f2c…\""
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

| Parameter   | Type    | Required | Description                               |
| ----------- | ------- | -------- | ----------------------------------------- |
| `service`   | string  | Yes      | e.g. `monzo`, `oura`, `spotify` (max 100) |
| `action`    | string  | No       | Filter by action (max 255)                |
| `from_date` | string  | No       | ISO date or relative keyword (max 50)     |
| `to_date`   | string  | No       | ISO date or relative keyword (max 50)     |
| `limit`     | integer | No       | Max results (1–100, default 50)           |

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

### `GET /context/day` — **deprecated**

> **Deprecated.** Superseded by `GET /briefing/today`'s `sections`. No
> mobile client calls this. Still returns its original shape for any
> existing caller, but responses carry `Deprecation: true`,
> `Sunset: <date, 6 months out>`, and
> `Link: </api/v1/mobile/briefing/today>; rel="successor-version"`.
> Target removal: 6 months from the `Sunset` date on the response.

Full raw day context — events, metrics, and relationships for a date,
grouped by service/action/hour. This is the larger, unaggregated sibling of
`/briefing/today`; prefer the briefing endpoint unless you need the raw
detail. Mirrors MCP's `get-day-context-tool` and the `day-context-resource`
MCP resource.

**Query Parameters**

| Parameter | Type   | Default | Description             |
| --------- | ------ | ------- | ----------------------- |
| `date`    | string | today   | `YYYY-MM-DD`            |
| `domains` | array  | all     | Up to 10 domain strings |

**Response `200`**: large structured payload — see
[MCP.md](MCP.md#get-day-context-tool) for the shared shape.

---

### `GET /context/service-status` — **deprecated**

> **Deprecated.** Superseded by `GET /briefing/today`'s `sync_status`, which
> now carries the same `stale`/`as_of` judgement this endpoint was the only
> source of. No mobile client calls this. Same `Deprecation`/
> `Sunset`/`Link` headers as `GET /context/day` above.

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

| Parameter               | Type    | Required | Description            |
| ----------------------- | ------- | -------- | ---------------------- |
| `query`                 | string  | Yes      | Search text (max 500)  |
| `semantic`              | boolean | No       | Default `true`         |
| `limit`                 | integer | No       | 1–50, default 20       |
| `service`               | string  | No       | Events only (max 100)  |
| `domain`                | string  | No       | Events only (max 100)  |
| `concept`               | string  | No       | Objects only (max 100) |
| `object_type`           | string  | No       | Objects only (max 100) |
| `block_type`            | string  | No       | Blocks only (max 100)  |
| `from_date` / `to_date` | date    | No       | Restrict by date       |

**Response `200`**

```json
{
    "events": [
        {
            "id": "uuid",
            "similarity": 0.0842,
            "...": "full EventResource fields"
        }
    ],
    "meta": {
        "query": "sleep score",
        "semantic": true,
        "count": 8,
        "limit": 20
    }
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
        {
            "id": "12",
            "name": "running",
            "type": "spark",
            "events_count": 42,
            "objects_count": 3,
            "total_count": 45
        }
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
    "tag": {
        "id": "12",
        "name": "running",
        "type": "spark",
        "events_count": 42,
        "objects_count": 3,
        "total_count": 45
    },
    "data": [
        {
            "kind": "event",
            "id": "uuid",
            "title": "5K Run",
            "subtitle": "2026-05-10T07:02:00+00:00",
            "domain": "health"
        }
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
        "integration_completed": true,
        "integration_failed": true,
        "integration_authentication_failed": true,
        "cookie_expiry_warning": true,
        "fetch_multiple_failures": true,
        "fetch_content_changed": true,
        "migration_completed": true,
        "migration_failed": true,
        "data_export_ready": true,
        "system_maintenance": true
    },
    "delivery_mode": "immediate",
    "digest_time": "08:00"
}
```

Each key is a real notification type — the string a notification's
`getNotificationType()` returns — because that is what
`SparkNotification::via()` gates delivery on. The set is derived from
`App\Notifications\NotificationCatalogue`, which is also the source for the web
settings page and for the APNs category mapping, so the three cannot drift.

The categories this endpoint returned before v0.4 (`anomaly`, `digest`,
`new_bookmark`, `calendar_event`) named notifications Spark never sends; toggling
them had no effect and they have been withdrawn. Three types that are sent —
`cookie_expiry_warning`, `fetch_content_changed`, `fetch_multiple_failures` — had
no toggle at all and now do.

Unset types default to `true`.

The write counterpart, `PATCH /settings/notifications`, is handled by a
separate controller (`NotificationSettingsController`, not
`NotificationPreferencesController`) — see [Write Endpoints](#write-endpoints).
It requires **every** key above unless `delivery_mode` is `work_hours`.

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
            "morning": {
                "completed": true,
                "physical": 4,
                "mental": 3,
                "combined": 3.5,
                "notes": null,
                "event_id": "uuid"
            },
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

Read state is reported, never enforced — already-read items are still
returned, which is what lets the client offer a recap of the day and undo an
accidental dismissal.

**Query Parameters**: `include_acknowledged` (bool, default false — also
return dismissed anomalies), `news_limit` (int, default 20, max 100).

**Response `200`**: see [API_v1.md](API_v1.md#get-apiv1up-to-speed) for the
full shape (identical on both surfaces).

---

### `GET /flint/digests`

Flint digest(s) for a date. Defaults to today's most recent; `all=true`
returns every digest created that day.

**Query Parameters**: `date` (default today), `period` (`morning`/`afternoon`/`evening`), `all` (boolean).

**Response `200`**: the mobile [FlintDigest](API_v1.md#flintdigest)
representation. Its base fields match API v1, with the mobile-only
reader/version additions described below, plus:

- `effective_timezone` — see [Timezone Contract](#timezone-contract).
- `opener` — the digest's lede sentence, published as its own field so
  the client renders it verbatim and owns no knowledge of digest prose
  structure. Written explicitly by the generating skill when it sends one;
  otherwise derived server-side from `summary` with the same heuristic the
  client previously had to run itself (drop a short greeting, drop an
  all-caps heading, strip a leading em dash and Markdown emphasis, keep to a
  sentence boundary).

**Response `404`** — No digest found for that date/period.

For History, provide both `from` and `to` instead of the date-only parameters.
Bounds are inclusive local calendar dates in the user's effective timezone and
may cover at most 30 days. Results are ordered by event time and ID descending.
List items deliberately omit blocks; use the detail route to open a digest.

**History Query Parameters**: `from`, `to` (`YYYY-MM-DD`, both required);
`limit` (1–50, default 20); `cursor` (opaque).

**History Response `200`**

```json
{
    "data": [
        {
            "id": "event-uuid",
            "local_date": "2026-09-14",
            "period": "morning",
            "kind": "briefing",
            "title": "Morning Digest",
            "summary": "A short list-safe summary.",
            "generated_at": "2026-09-14T07:12:03+01:00",
            "updated_at": "2026-09-14T07:12:03+01:00",
            "unanswered_question_count": 1,
            "version": "W/\"opaque-version\"",
            "freshness": { "state": "fresh", "age_seconds": 8280 }
        }
    ],
    "next_cursor": null,
    "has_more": false,
    "meta": {
        "from": "2026-08-16",
        "to": "2026-09-14",
        "effective_timezone": "Europe/London",
        "account_id": "user-uuid"
    }
}
```

An empty range returns `200` with `data: []`. Invalid, future-only, or
greater-than-30-day ranges return `422`.

---

### `GET /flint/digests/{id}`

A single digest by event UUID. It uses the same mobile FlintDigest shape.
Reader-facing question blocks omit the internal `priority` field and include
canonical `status`, `answer_history`, and the digest's strong `version`.

---

### `GET /flint/digests/latest`

The single most recent digest across dates — for "the newest thing Flint has
written," which isn't expressible as `GET /flint/digests` with a `date`
alone: before the morning briefing has run, the newest digest is still
yesterday evening's, and expressing that with the date-scoped endpoint takes
two round trips (ask for today, get `404`, ask for yesterday). This is the
one-request version, used on cold start.

**Query Parameters**: `kind` (optional — `briefing`/`news_roundup`/`reading_list`).

**Response `200`**: the same shape as `GET /flint/digests/{id}`, including
`local_date`/`period` (via `date`/`period` in the payload) so the client can
say which run it was.

**Response `404`** — No digest exists yet (optionally, none of the requested `kind`).

---

### `GET /flint/questions`

Returns questions across the user's recent digests, rather than deriving
them from a selected date. By default, only open questions — unanswered and
within the seven-day retirement horizon.

**Query Parameters**

| Parameter | Type   | Default | Description                                                                             |
| --------- | ------ | ------- | --------------------------------------------------------------------------------------- |
| `status`  | string | `open`  | Comma-separated: `open`, `answered`, `skipped`, `retired` — e.g. `status=open,answered` |
| `since`   | string | —       | ISO timestamp, or a relative window like `48h`/`7d`. Bounds the query server-side.      |
| `limit`   | int    | 20      | 1–50                                                                                    |
| `cursor`  | string | —       | Opaque cursor                                                                           |

Without `since`, an `open`-only request keeps the original default: bounded
by the seven-day retirement horizon so the list doesn't grow forever. Any
other status combination, or an explicit `since`, is bounded by `since` alone.

**Response `200`**

```json
{
    "data": [
        {
            "id": "question-block-uuid",
            "digest_id": "digest-event-uuid",
            "source_digest": {
                "local_date": "2026-09-14",
                "period": "morning"
            },
            "status": "open",
            "title": "The £2,508 transfer from Daniel",
            "question": "Should the review move to Friday?",
            "topic": "Quarterly planning",
            "answer_options": ["Yes", "No", "Choose another day"],
            "asked_at": "2026-09-14T07:12:03+01:00",
            "effective_answer": null,
            "answer_history": [],
            "version": "\"strong-question-version\""
        }
    ],
    "next_cursor": null,
    "has_more": false,
    "meta": {
        "effective_timezone": "Europe/London",
        "account_id": "user-uuid"
    }
}
```

`title` is the short label the digest block itself carries — the same
title the block shows inline. `question` remains the full question text.

The mobile representation never includes question priority.

**Response `422`** — Unknown `status` value, or an unparseable `since`.

---

### `GET /flint/topics`

Flint's long-lived strategic/thematic/tactical threads — the "running threads"
list on the Flint tab. Topics are created and maintained by the
`manage-flint-topic` MCP tool; this endpoint is read-only.

**Query Parameters**: `status` (`active`/`dormant`/`resolved`/`expired`),
`kind` (`strategic`/`thematic`/`tactical`) — both optional, omit either to
include every value; `limit`/`cursor` (see [Cursor Pagination](#cursor-pagination)).

**Response `200`**

```json
{
    "data": [
        {
            "id": "uuid",
            "title": "US–Iran escalation",
            "content": "Optional free-text note.",
            "kind": "strategic",
            "status": "active",
            "first_seen_at": "2026-09-05T00:00:00+00:00",
            "last_touched_at": "2026-09-10T07:01:28+00:00",
            "next_review_at": null,
            "origin": "digest_inference",
            "watching_for": "The decisive next development is a G7 decision on reserves."
        }
    ],
    "next_cursor": null,
    "has_more": false
}
```

Ordered newest-touched first (`updated_at desc`).

`watching_for` is what would move the thread on — the closing
sentence `content` conventionally ends with, published as its own field so
the client shows it directly and owns no sentence-splitting of `content`.
Written explicitly by the same routine that writes `content` when it sends
one; otherwise derived server-side from `content`'s last sentence.

---

### `GET /flint/topics/{id}`

Returns one owned Thread and the digest or block evidence linked through its
existing `discussed_in` relationships. Evidence is tenant-scoped, newest first,
deduplicated by stable source, and includes deep links back into the reader.
Deleted source content degrades to a marked evidence entry where the source row
still exists.

**Response `200`**

```json
{
    "data": {
        "id": "topic-uuid",
        "title": "Quarterly planning",
        "content": "The canonical read-only Thread summary.",
        "kind": "strategic",
        "status": "active",
        "first_seen_at": "2026-08-01T08:00:00Z",
        "last_touched_at": "2026-09-14T07:12:03Z",
        "next_review_at": "2026-09-20",
        "origin": "digest_inference",
        "watching_for": "The decisive next development is a G7 decision on reserves.",
        "version": "\"strong-topic-version\"",
        "mentions": [
            {
                "id": "relationship-uuid",
                "source_type": "digest_block",
                "source_id": "block-uuid",
                "event_id": "digest-event-uuid",
                "digest_id": "digest-event-uuid",
                "block_id": "block-uuid",
                "title": "Planning pressure",
                "excerpt": "The review date now overlaps travel.",
                "local_date": "2026-09-14",
                "period": "morning",
                "occurred_at": "2026-09-14T07:12:03+01:00",
                "deep_link": "spark://block/block-uuid",
                "source_deleted": false
            }
        ]
    }
}
```

**Response `404`** — Thread is missing or belongs to another account. Topic
editing remains web/MCP-owned.

---

### `GET /flint/notes`

Returns user-authored Flint notes newest first. Notes reuse the existing
searchable object store and are always scoped to the authenticated account.

**Query Parameters**: `limit` (1–50, default 20); `cursor` (opaque).

Each note includes `id`, derived `title`, `body`, `authored_at`, `created_at`,
`deleted_at`, validated `context_links`, consent metadata, and a strong
`version`. The envelope includes top-level `next_cursor` and `has_more`; its
`meta` object contains `effective_timezone` and `account_id`.

---

### `GET /flint/routines/health`

Returns the morning/evening digest, topics, reading-list, and news-roundup
routines. Each item reports `state` (`configured`, `unconfigured`, `disabled`,
or `unavailable`), `enabled`, effective `driver`, `next_eligible_run`,
`last_attempt`, `last_persisted_output`, and a redacted `failure`.

A provider `2xx` is reported as `accepted`, not completed. Success is recorded
only after Spark observes run-bound persisted output or the topics routine uses
its authenticated completion action. Raw provider responses, webhook URLs,
secrets, and exception messages are never returned.

Response metadata includes `effective_timezone`, `account_id`, and the web
`management_url`.

**Coverage:** no mobile client surface calls this today. Kept live
and undeprecated — unlike `context/day`/`context/service-status`, it has no
superseding endpoint; it's an operational monitoring read (the web
`management_url` it links to is the actual consumer), not a candidate for
the reader UI to wire up.

---

### `GET /money/accounts`

All non-archived finance accounts (manual and synced) with their latest
balance.

**Query Parameters**: `limit`/`cursor` (see [Cursor Pagination](#cursor-pagination)).

**Response `200`**: `{"data": [MoneyAccount, ...], "next_cursor": "...", "has_more": false}` — see
[MoneyAccount](API_v1.md#moneyaccount). Each account now carries `is_pinned`
— the account the user has chosen to see first on the Day tab and the
Explore money hero, replacing a client-side guess (first account whose type
contains "current"). At most one account is pinned at a time; see
`PATCH /money/accounts/{id}` below.

---

### `GET /money/accounts/{id}`

A single account. Same [MoneyAccount](API_v1.md#moneyaccount) shape, wrapped
in `{"data": ...}`.

---

### `GET /money/accounts/{id}/balances`

Cursor-paginated balance history, newest first (25 per page).

**Response `200`**: `{"data": [BalanceEntry, ...], "next_cursor": "...", "has_more": false}` — see [BalanceEntry](API_v1.md#balanceentry).

---

### `GET /money/net-worth`

Net worth and its change over a window, in one request. Without this, showing
net worth and its month-on-month change takes `GET /money/accounts` then
`GET /money/accounts/{id}/balances` once per account, reconciled by hand —
N+1 requests for two numbers.

**Query Parameters**

| Parameter | Type   | Default  | Description                                         |
| --------- | ------ | -------- | --------------------------------------------------- |
| `compare` | string | `1month` | `1week`, `1month`, `3months`, `6months`, or `1year` |

**Response `200`**

```json
{
    "data": {
        "total": 48210.64,
        "currency": "GBP",
        "comparison": {
            "window": "1month",
            "then": 47006.46,
            "change": 1204.18,
            "change_pct": 2.56
        },
        "excluded_accounts": 1,
        "as_of": "2026-09-20T08:00:00+00:00"
    }
}
```

- `total` is the current net worth across every account in the primary
  currency (`GBP`) — every eligible account counts, new or old.
- `comparison.then`/`change`/`change_pct` only include accounts whose balance
  history reaches back to the comparison window; a brand-new account (nothing
  `then`, something `total`) would otherwise read as pure growth, so it's
  excluded from the comparison specifically, while still counting toward `total`.
- `excluded_accounts` is the count of accounts left out of the comparison —
  for a different currency than the primary one, or for not having history
  spanning the window. Multi-currency conversion isn't implemented; accounts
  in another currency are excluded entirely (from both `total` and the
  comparison) rather than summed incorrectly.
- Debt accounts (credit cards, loans, mortgages — `is_negative_balance` on
  the account) subtract from the total rather than add to it.

**Response `422`** — Unknown `compare` value.

---

## Write Endpoints

All write endpoints require `ios:write` ability.

### Summary

| Method   | Path                               | Description                                                                                |
| -------- | ---------------------------------- | ------------------------------------------------------------------------------------------ |
| `POST`   | `/devices`                         | Register an APNs device token                                                              |
| `DELETE` | `/devices/{id}`                    | Unregister a device                                                                        |
| `POST`   | `/health/samples`                  | Ingest HealthKit samples (batch)                                                           |
| `POST`   | `/live-activities`                 | Start a Live Activity                                                                      |
| `PATCH`  | `/live-activities/{id}`            | Push a Live Activity update                                                                |
| `DELETE` | `/live-activities/{id}`            | End a Live Activity                                                                        |
| `POST`   | `/live-activities/{id}/tokens`     | Rotate a Live Activity push token                                                          |
| `POST`   | `/check-ins`                       | Submit a daily mood check-in (`Idempotency-Key` accepted)                                  |
| `POST`   | `/anomalies/{id}/acknowledge`      | Acknowledge a metric anomaly                                                               |
| `POST`   | `/knowledge/events/{id}/reprocess` | Queue knowledge AI reprocessing                                                            |
| `POST`   | `/notifications/{id}/read`         | Mark one notification as read                                                              |
| `POST`   | `/notifications/read-all`          | Mark all notifications as read                                                             |
| `DELETE` | `/notifications/{id}`              | Delete one notification                                                                    |
| `PATCH`  | `/{kind}/{id}`                     | Non-destructive update of an owned event/object/block                                      |
| `PATCH`  | `/events/{id}/note`                | Set or clear an event's note                                                               |
| `PATCH`  | `/{kind}/{id}/location`            | Set a location on an owned event/object                                                    |
| `DELETE` | `/{kind}/{id}/location`            | Clear a location                                                                           |
| `POST`   | `/{kind}/{id}/location/geocode`    | Geocode an address and set it as the location                                              |
| `POST`   | `/events/{id}/tags`                | Attach a tag to an event (`Idempotency-Key` accepted)                                      |
| `DELETE` | `/events/{id}/tags/{tagId}`        | Detach a tag from an event                                                                 |
| `POST`   | `/objects/{id}/tags`               | Attach a tag to an object (`Idempotency-Key` accepted)                                     |
| `DELETE` | `/objects/{id}/tags/{tagId}`       | Detach a tag from an object                                                                |
| `POST`   | `/integrations/{id}/sync`          | Trigger an immediate fetch for one integration                                             |
| `POST`   | `/integrations/sync`               | Trigger an immediate fetch for all instances of a service                                  |
| `POST`   | `/integrations/{id}/oauth/start`   | Start a PKCE re-authentication flow (mobile-only)                                          |
| `POST`   | `/{kind}/{id}/relationships`       | Create a relationship from an owned entity                                                 |
| `DELETE` | `/relationships/{relationship}`    | Delete an owned relationship                                                               |
| `PATCH`  | `/settings/notifications`          | Update notification preferences                                                            |
| `POST`   | `/check-ins/timezone`              | Record an acknowledged timezone change                                                     |
| `POST`   | `/check-ins/media`                 | Upload a check-in photo (raw binary body)                                                  |
| `POST`   | `/up-to-speed/read`                | Mark Up to Speed items as caught up                                                        |
| `POST`   | `/up-to-speed/unmark`              | Return Up to Speed items to the unread queue                                               |
| `POST`   | `/flint/digests`                   | Create a Flint digest                                                                      |
| `POST`   | `/flint/questions/{block}/actions` | Versioned, idempotent answer/correct/skip action                                           |
| `POST`   | `/flint/questions/{block}/answer`  | Legacy answer adapter (deprecated)                                                         |
| `POST`   | `/flint/notes`                     | Create an idempotent user-authored Flint note                                              |
| `DELETE` | `/flint/notes/{id}`                | Idempotently delete an owned Flint note                                                    |
| `POST`   | `/bookmarks`                       | Bookmark a URL                                                                             |
| `POST`   | `/money/accounts`                  | Create a manual finance account                                                            |
| `PATCH`  | `/money/accounts/{id}`             | Update a manual finance account, or pin/unpin any account                                  |
| `DELETE` | `/money/accounts/{id}`             | Archive a manual finance account                                                           |
| `POST`   | `/money/accounts/{id}/balances`    | Add a balance entry (`Idempotency-Key` accepted)                                           |
| `POST`   | `/devices/test`                    | Send a test push notification                                                              |
| `POST`   | `/logout`                          | End the calling session (revokes this token and its refresh token)                         |
| `POST`   | `/api-tokens`                      | Create a personal access token (requires `tokens:manage`; unreachable from an iOS session) |
| `DELETE` | `/api-tokens/{id}`                 | Revoke a personal access token                                                             |

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

**`Idempotency-Key` (optional):** send a UUID and a retried `POST` with
the same key replays the first response instead of attaching (or
find-or-creating) the tag a second time. Keys are scoped per user and per
endpoint, and stay live for 24 hours.

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

Deprecated compatibility adapter for one released client version. It writes
through the same canonical action service as the versioned endpoint.

**Request Body**: `{"answer": "Yes", "answer_note": "optional"}` (`answer` required, max 1000 chars; `answer_note` optional, max 1000 chars).

**Response `200`**: `{"block_id": "uuid", "answer": "Yes", "answer_note": null, "answered_at": "...", "data": FlintQuestion}`

`data` is the full updated question resource — the same shape
`GET /flint/questions` and `POST .../actions` return — added additively
alongside the original flat fields so a client can update in place without a
follow-up `GET`, without breaking anything still reading the flat shape.

Successful responses include `Deprecation: true` and a `Sunset` header.
**Response `403`** — Block's digest doesn't belong to the caller.
**Response `422`** — Block is not a `flint_user_question`.

---

### `POST /flint/questions/{block}/actions`

Appends a canonical question action. `answer` is valid for open or retired
questions; `correct` requires an effective answer and retains prior history;
`skip` is valid only while open. The block metadata holds the complete ordered
history and compatibility snapshot—no separate model or table is used.

**Headers**: `If-Match` with the strong question version; `Idempotency-Key`
with a UUID. Replay of the same key and body returns the original logical
result even if its original ETag is now stale.

**Request Body**

```json
{
    "action": "answer",
    "answer": "Move it to Friday.",
    "context": "Thursday clashes."
}
```

For a correction use `action: "correct"`. A skip body is only
`{"action": "skip"}`.

**Response `201`** for a newly appended action; **`200`** for an idempotent
replay. The body is `{"data": FlintQuestion}` and the fresh strong version is
returned both in `data.version` and the `ETag` header.

**Response `403`** — Question is not owned by the caller. **`409`** —
idempotency key reused with different content. **`412`** — stale `If-Match`.
**`422`** — invalid action or transition. **`428`** — missing `If-Match`.
Precondition failures include the current ETag.

---

### `POST /flint/notes`

Creates a searchable Flint note using the existing object and relationship
stores. The server derives the title from `authored_at` in the effective
timezone: `Note to Flint 14/09/26 13:17`. Same-minute collisions become
`(2)`, `(3)`, and so on.

**Request Body**

```json
{
    "client_mutation_id": "uuid",
    "authored_at": "2026-09-14T13:17:00+01:00",
    "body": "Keep Friday evening free after the train.",
    "context_links": [{ "type": "event", "id": "event-uuid" }],
    "consent_version": "flint-note-v1"
}
```

`context_links` may contain owned `event`, `digest`, `block`, or `topic`
identifiers (maximum 20). The body is limited to 10,000 characters and is
excluded from telemetry and activity-log properties.

**Response `201`** when created; **`200`** for an identical mutation replay.
Both return `{"data": FlintNote}` and its strong `ETag`. **Response `409`** —
mutation ID reused with different content. **Response `422`** — invalid,
cross-tenant context, consent, or authored time.

---

### `DELETE /flint/notes/{id}`

Soft-deletes an owned Flint note and its context relationships. Deletion is
tenant-scoped and idempotent: missing, already-deleted, and other-account IDs
all return **`204`** without revealing ownership.

---

### `POST /bookmarks`

Bookmarks a URL shared from the iOS share extension. Delegates to the same
service as the legacy `POST /api/fetch/bookmarks` endpoint.

**Request Body**: `{"url": "https://example.com/article"}` (required, valid URL, max 2048 chars).

**Response `201`/`200`**: `{"state": "...", "bookmark": {"id": "uuid", "url": "..."}}` (`201` when newly created, `200` when it already existed).

**Response `422`** — URL fails the safety validator.

---

### `POST /bookmarks/capture`

Captures content rendered in Safari and supplied by the iOS Share extension.
Uses the same capture service as `POST /api/v1/bookmarks/capture`, but accepts
the iOS session's `ios:write` ability.

**Request Body**:
`{"url": "https://example.com/article", "title": "Article title", "html": "<!doctype html>..."}`.
`url` and `html` are required; `title` is optional. HTML is limited to 5 MB.

**Response `201`/`200`**:
`{"state": "captured|recaptured", "bookmark": {"id": "uuid", "url": "...", "title": "..."}}`.

**Response `422`** — URL fails the safety validator or readable content cannot
be extracted.

**Coverage:** neither this endpoint nor `POST /bookmarks` above has a
client counterpart in `spark-ios`'s `SparkKit/API/Endpoints` as of this
writing — the iOS share extension doesn't call either yet. Not deprecated:
these exist specifically to receive the share-extension capture once it's
wired up client-side, which is `spark-ios` work outside this repo. Documented
here so the gap is visible rather than silent; if the share extension still
isn't calling either endpoint by the next removal review, that's the point
to reconsider.

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

**`is_pinned` (boolean, any account type):** the one field on this
endpoint that isn't restricted to manual accounts — pinning is a user
preference, not account data, so a synced Monzo or GoCardless account can be
pinned too. At most one account is pinned per user: setting `is_pinned: true`
on an account clears it on every other account first. Returned on
[MoneyAccount](API_v1.md#moneyaccount) via `GET /money/accounts`.

**Response `422`** — A field other than `is_pinned` was sent for an account
that is not `manual_account` (synced accounts can't have their account data
edited here — only pinned).

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

**`Idempotency-Key` (optional):** a retried `POST` with the same key
replays the first response instead of recording the balance twice — same
contract as `POST /events/{id}/tags` above.

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

> **Requires `tokens:manage`.** An iOS OAuth session is only ever issued
> `ios:read`/`ios:write` (see `OAuthController::scopeToAbilities`), so this
> endpoint is **not reachable from the app** and returns `403`. Token
> administration is a web-settings journey. The route remains registered so a
> non-mobile credential holding `tokens:manage` can use it.

**Request Body**

```json
{
    "name": "Zapier integration",
    "abilities": ["data:read", "insights:read"]
}
```

`name` is required (max 255 chars). `abilities` is **required** — between 1
and 20 distinct strings, each of which must appear in
`SparkAbility::DELEGABLE`:

`bookmark:write`, `data:image`, `data:read`, `data:write`, `finance:read`,
`finance:write`, `flint:read`, `flint:run`, `flint:write`, `insights:read`,
`insights:write`, `integrations:read`, `integrations:sync`, `tokens:manage`

Authority attenuates: a token-authenticated caller may only request
capabilities its own credential already holds. `ios:read`, `ios:write` and
`mcp:read` are never delegable — `mcp:read` remains accepted on existing
tokens as a legacy alias, but new tokens must name the capability they need.

**Response `201`**

```json
{
    "id": "3",
    "name": "Zapier integration",
    "plaintext": "1|abc123def456..."
}
```

`plaintext` is shown only in this response — it cannot be retrieved again. It
is a bearer credential: it is excluded from application telemetry and must
never be logged.

**Response `403`** — The requested capabilities exceed those of the credential
making the request.

**Response `422`** — `abilities` omitted, empty, or containing an unknown or
non-delegable value (including `*` and `ios:*`).

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

**`Idempotency-Key` (optional):** a retried `POST` with the same key
replays the first response instead of re-running the submission — same
contract as `POST /events/{id}/tags`.

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

Marks a single notification as read. **No `If-Match` required** — marking read
is idempotent, so there is no update to lose.

**Response `204`** — No content. Carries the notification's refreshed `ETag`.

**Response `404`** — Notification not found or belongs to another user.

---

### `POST /notifications/read-all`

Marks all unread notifications for the authenticated user as read. **No
`If-Match` required.**

**Response `204`** — No content. Carries the user's refreshed `ETag`.

---

### `DELETE /notifications/{id}`

Deletes a single notification from the authenticated user's inbox.

**Requires `If-Match`** with the notification's current version — the `version`
field on each item in `GET /notifications`.

**Response `204`** — No content.

**Response `404`** — Notification not found or belongs to another user.

**Response `428`** — `If-Match` header missing.

**Response `412`** — `If-Match` does not match the notification's current
version; re-read the list and retry.

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
    "group_key": "oura:had_sleep_score:actor-uuid",
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

| Field                 | Always present | Description                                                                                                                                                                                                                                                                 |
| --------------------- | -------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `group_key`           | Yes            | `service:action:actor_id`. Consecutive events sharing this key are one run (e.g. twenty Spotify plays) — group on it directly instead of implementing a client-side run-length rule that has to happen to agree with the web app's.                                         |
| `display_name`        | Yes            | Human-readable action label from plugin registry                                                                                                                                                                                                                            |
| `display_with_object` | Yes            | `true` if UI should include the related object title when rendering the action                                                                                                                                                                                              |
| `hidden`              | Yes            | `true` if this action should be hidden in default UI (e.g. balance updates)                                                                                                                                                                                                 |
| `value`               | No             | Formatted numeric value (applies `value_multiplier`); omitted when no value                                                                                                                                                                                                 |
| `unit`                | No             | Unit string; omitted when no value                                                                                                                                                                                                                                          |
| `display_value`       | No             | Fully formatted string, e.g. `"£10.50"`; omitted when no value                                                                                                                                                                                                              |
| `direction`           | Money only     | `"in"` \| `"out"` \| `"internal"` \| `"excluded"` \| `"unknown"`, resolved server-side (see [MoneyDirection](../../app/Support/MoneyDirection.php)) — present only when `domain` is `"money"` and `value` is set. Replaces inferring direction from the action-name suffix. |
| `url`                 | No             | Omitted when not set on the event                                                                                                                                                                                                                                           |
| `actor`               | No             | Omitted when not set; `media_url` within may be `null`                                                                                                                                                                                                                      |
| `target`              | No             | Omitted when not set; `media_url` within may be `null`                                                                                                                                                                                                                      |
| `tldr`                | No             | Content of the first block whose `block_type` contains `tldr`; any domain                                                                                                                                                                                                   |
| `tags`                | Yes            | Always an array (empty when no tags); each item has `name` and `type`                                                                                                                                                                                                       |
| `blocks_count`        | Feed only      | Integer count of attached blocks; present in `/feed`, absent in `/events/id`                                                                                                                                                                                                |
| `blocks`              | Detail only    | Full block array; present in `GET /events/{id}`, absent in `/feed`                                                                                                                                                                                                          |

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
    },
    "version": "\"9f2c…\""
}
```

| Field         | Type    | Description                                                           |
| ------------- | ------- | --------------------------------------------------------------------- |
| `id`          | UUID    | Database notification ID                                              |
| `title`       | string  | Notification title, defaults to `"Notification"` if absent            |
| `body`        | string  | Optional message body                                                 |
| `domain`      | string  | Optional Spark domain, when the notification carries one              |
| `is_read`     | boolean | `true` when `read_at` is set                                          |
| `received_at` | string  | ISO timestamp for notification creation                               |
| `entity`      | object  | Optional deep-link target with `kind` and `id`                        |
| `version`     | string  | Strong entity tag; send as `If-Match` on `DELETE /notifications/{id}` |

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
