# Spark API v1 Reference

`/api/v1` is Spark's client-neutral, capability-scoped REST API — the same
underlying services and response shapes as the MCP server and the mobile
adapter, reused directly rather than reimplemented. See
[README.md](README.md) for the cross-surface capability model and parity
matrix before diving into individual endpoints.

## Table of Contents

- [Authentication](#authentication)
- [Common Conventions](#common-conventions)
- [ETag and If-Match](#etag-and-if-match)
- [`data:read`](#dataread)
- [`insights:read`](#insightsread)
- [`integrations:read` and `integrations:sync`](#integrationsread-and-integrationssync)
- [`flint:read` and `flint:write`](#flintread-and-flintwrite)
- [`finance:read` and `finance:write`](#financeread-and-financewrite)
- [`data:write`](#datawrite)
- [Shared response schemas](#shared-response-schemas)
- [Legacy `/api` reference](#legacy-api-reference)
- [Related Documentation](#related-documentation)

---

## Authentication

Every `/api/v1` route requires a Laravel Sanctum bearer token carrying the
ability the route enforces:

```
Authorization: Bearer <token>
```

Abilities are checked by the `spark.ability:<name>` middleware
(`App\Http\Middleware\RequireSparkAbility` → `App\Support\SparkAbility::allows`),
the same class MCP tools use via `RequiresSparkAbility`. Available abilities:
`data:read`, `data:write`, `insights:read`, `insights:write`,
`integrations:read`, `integrations:sync`, `flint:read`, `flint:write`,
`finance:read`, `finance:write`. There is no Policy or Gate involved — it is
this one middleware everywhere. Tokens carrying only the legacy `mcp:read`
ability still satisfy `data:read`, `insights:read`, `integrations:read`, and
`flint:read` (never a `:write` ability, and never `finance:read`).

`/api/v1` does **not** require the `ios.enabled` feature flag or
`ios:read`/`ios:write` abilities — those are mobile-only, applied separately
to `/api/v1/mobile` (see [mobile_API.md](mobile_API.md)).

---

## Common Conventions

**Base path**: `/api/v1`

**Errors**: JSON body `{"message": "..."}`, sometimes with a `hint` or
`errors` (validation) key, matching the mobile adapter's format.

**Pagination**: collection endpoints that support paging use the same opaque
cursor scheme as the mobile API — `next_cursor` / `has_more` in the response,
`cursor` as the request query parameter.

**Middleware stack**: `auth:sanctum`, `etag` (`App\Http\Middleware\ETag` —
weak representation ETag on GET responses, 304 short-circuit on
`If-None-Match`), then `spark.ability:<name>` per route group.

---

## ETag and If-Match

Detail reads and writes to mutable entities emit a strong, opaque resource
ETag via `App\Services\Api\ResourceVersion::etag()` — the same class the
mobile adapter uses, backed by Postgres's `xmin` system column so two
writes inside the same second never collide on a stale version. `/api/v1`
and `/api/v1/mobile` enforce the identical `if-match:<target>` middleware on
the same set of entity-mutation routes: missing the header returns `428`;
a stale value returns `412`; both responses include the current `ETag`. The
version check and the write happen inside one row-locked transaction, so a
second writer can't slip a stale check in underneath the first.

`If-Match` is required on: `PATCH {kind}/{id}`, `PATCH events/{id}/note`,
event/object tag store and destroy, `POST knowledge/events/{id}/reprocess`,
relationship create and destroy, and the finance account update/destroy/
add-balance routes. It is **not** required on routes that create a new
resource or don't have a single entity to version — `POST bookmarks`,
`POST finance/accounts`, `POST check-ins`, `POST anomalies/{id}/acknowledge`,
`POST up-to-speed/read`, `POST flint/digests`, `POST flint/questions/{block}/answer`,
and `POST integrations{,/{id}}/sync` — same as the mobile adapter.

---

## `data:read`

### `GET /api/v1/events`

Cursor-paginated reverse-chronological event feed. Same controller
(`V1Mobile\FeedController@index`) and response shape as
`GET /api/v1/mobile/feed` — see [mobile_API.md](mobile_API.md#get-feed) for
the full query parameter table (`cursor`, `limit`, `domain`, `date`) and
response example.

### `GET /api/v1/events/{id}`

Single event by UUID, full embedded `blocks` array. Identical to
`GET /api/v1/mobile/events/{id}` — see
[CompactEvent](mobile_API.md#compactevent).

### `GET /api/v1/objects/{id}`

Single `EventObject`, optionally with recent events (`include_events`,
`event_limit` 1–25). Identical to `GET /api/v1/mobile/objects/{id}`.

### `GET /api/v1/blocks/{id}`

Single block by UUID. Identical to `GET /api/v1/mobile/blocks/{id}`.

### `GET /api/v1/search`

Multi-mode search across events, objects, integrations, and metrics.
`mode` ∈ `default`, `semantic`, `tag`, `metric`, `integration`; an unknown
mode returns `422` listing the valid modes. Identical to
`GET /api/v1/mobile/search` — see
[mobile_API.md](mobile_API.md#get-search) for the full mode table and
response shape.

### `GET /api/v1/tags`

Cursor-paginated list of the user's tags, each annotated with usage counts.

**Query parameters**

| Parameter | Type    | Default | Description                          |
| --------- | ------- | ------- | ------------------------------------- |
| `q`       | string  | —       | Filter by tag name or type (max 255) |
| `cursor`  | string  | —       | Opaque pagination cursor              |
| `limit`   | integer | 30      | Items per page (max 100)              |

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

Tags are ordered by `total_count` descending, then `id` ascending. Only tags
attached to an event on one of the user's integrations, or an object owned
by the user, are returned.

### `GET /api/v1/tags/suggest`

Autocomplete-style tag suggestions, ranked exact-match first, then
prefix-match, then by usage.

**Query parameters**: `q` (string, optional), `limit` (integer, default 10, max 25).

**Response `200`**: `{"data": [Tag, ...]}` — same tag shape as `GET /tags`.

### `GET /api/v1/tags/{id}`

A single tag (numeric ID) plus a cursor-paginated feed of the events,
objects, and blocks it's attached to, newest first.

**Query parameters**: `cursor`, `limit` (default 30, max 100).

**Response `200`**

```json
{
    "tag": { "id": "12", "name": "running", "type": "spark", "events_count": 42, "objects_count": 3, "total_count": 45 },
    "data": [
        { "kind": "event", "id": "uuid", "title": "5K Run", "subtitle": "2026-05-10T07:02:00+00:00", "domain": "health" },
        { "kind": "object", "id": "uuid", "title": "Personal", "subtitle": "account", "concept": "account" },
        { "kind": "block", "id": "uuid", "title": "Heart Rate", "subtitle": "biometric", "block_type": "biometric" }
    ],
    "next_cursor": null,
    "has_more": false
}
```

**Response `404`** — Tag not found or not associated with the user.

### `GET /api/v1/map/data`

Geo-located events and places within a bounding box; clusters instead of
individual markers once the result count exceeds 500. Identical to
`GET /api/v1/mobile/map/data` — see
[mobile_API.md](mobile_API.md#get-mapdata).

### `GET /api/v1/places/{id}`

A single place (`EventObject` with `concept = 'place'`). Identical to
`GET /api/v1/mobile/places/{id}`.

### `GET /api/v1/{kind}/{id}/relationships`

Lists the relationships attached to an owned event, object, or block.
`{kind}` ∈ `events`, `objects`, `blocks`.

**Response `200`**: `{"data": [Relationship, ...]}` — see
[Relationship](#relationship) below.

**Response `404`** — Entity not found or not owned by the caller.

---

## `insights:read`

### `GET /api/v1/day-summary`

Compact, pre-aggregated daily summary across domains with baseline
comparison and anomaly detection. Identical to
`GET /api/v1/mobile/briefing/today` (same controller,
`V1Mobile\BriefingController@today`) — see
[mobile_API.md](mobile_API.md#get-briefingtoday) for the `date`/`domains`
query parameters and response shape.

### `GET /api/v1/metrics`

All metric identifiers and metadata for the user — flat array, not wrapped
in `data`. Identical to `GET /api/v1/mobile/metrics`.

### `GET /api/v1/metrics/{metric}`

Metric trend with daily values, summary statistics, and baseline. Accepts
`range` (`7d`/`30d`/`90d`/`1y`) or explicit `from`/`to`. Identical to
`GET /api/v1/mobile/metrics/{metric}`.

### `GET /api/v1/check-ins`

Morning/afternoon check-in completion status for a date.

**Query parameters**: `date` (required, `YYYY-MM-DD`).

**Response `200`**

```json
{
    "date": "2026-05-10",
    "morning": { "completed": true, "physical": 4, "mental": 3, "event_id": "uuid" },
    "afternoon": { "completed": false }
}
```

### `GET /api/v1/check-ins/history`

Day-by-day check-in summary for a date range, capped at 90 days.

**Query parameters**: `from`, `to` (both required, `YYYY-MM-DD`, `to >= from`).

**Response `422`** — Range exceeds 90 days.

**Response `200`**: array of `{date, morning: {...}, afternoon: {...}}` — see
[mobile_API.md](mobile_API.md) history detail; each period object includes
`completed`, and when `true`, `physical`, `mental`, `combined`, `notes`,
`event_id`.

### `GET /api/v1/up-to-speed`

Ordered, typed catch-up queue: `flint_digest` → `check_in` → `anomaly` →
`news_summary` items, each with a `caught_up_at` timestamp (`null` until
marked read via `POST /up-to-speed/read`, or automatically for completed
check-ins).

**Response `200`**

```json
{
    "items": [
        {
            "id": "uuid",
            "type": "flint_digest",
            "caught_up_at": null,
            "payload": { "date": "2026-05-10", "period": "morning", "title": "Morning Digest", "summary": "...", "block_count": 4, "unanswered_question_count": 1 }
        },
        {
            "id": "morning:2026-05-10",
            "type": "check_in",
            "caught_up_at": "2026-05-10T07:15:00+00:00",
            "payload": { "period": "morning", "date": "2026-05-10", "completed": true, "event_id": "uuid" }
        }
    ]
}
```

`anomaly` payloads include `metric`, `display_name`, `direction`,
`current_value`, `baseline_value`, `deviation`, `streak_days`,
`detected_at`. `news_summary` payloads include `title`, `source`, `url`,
`tldr`, `summary`, `key_takeaways` (each nullable, populated from whichever
summary block exists).

### `GET /api/v1/health/dashboard`

Curated fitness-first health dashboard. Identical to
`GET /api/v1/mobile/health/dashboard` — see
[mobile_API.md](mobile_API.md#get-healthdashboard) for the full response
shape (hero score, fitness today/workouts, body metrics, trends, insights).

---

## `integrations:read` and `integrations:sync`

### `GET /api/v1/integrations`

All of the user's integrations, ordered by service. Identical to
`GET /api/v1/mobile/integrations`.

### `GET /api/v1/integrations/{id}`

A single integration. Identical to `GET /api/v1/mobile/integrations/{id}`.

### `POST /api/v1/integrations/{id}/sync`

Triggers an immediate fetch for one integration instance (`integrations:sync`).

**Response `200`**: `{"message": "Integration update triggered.", "jobs_dispatched": 2}`

**Response `422`** — Integration is paused.

### `POST /api/v1/integrations/sync`

Triggers an immediate fetch for every non-paused integration matching a
service name (`integrations:sync`).

**Request body**: `{"service": "oura"}` (required, max 100 chars).

**Response `200`**

```json
{
    "service": "oura",
    "integrations": [
        { "integration_id": "uuid", "status": "triggered", "jobs_dispatched": 1 },
        { "integration_id": "uuid", "status": "skipped", "reason": "paused", "jobs_dispatched": 0 }
    ],
    "total_jobs_dispatched": 1
}
```

**Response `404`** — No integrations found for that service.

`POST /api/v1/integrations/{id}/oauth/start` (mobile-only PKCE re-auth
bridge) is **not** exposed on this surface — see the surface matrix in
[README.md](README.md).

---

## `flint:read` and `flint:write`

### `GET /api/v1/flint/digests`

Flint digest(s) for a date. Defaults to today's most recent; pass `all=true`
for every digest created that day.

**Query parameters**: `date` (`YYYY-MM-DD`, default today), `period`
(`morning`/`afternoon`/`evening`), `all` (boolean).

**Response `200`** (single, default): a digest object — see
[FlintDigest](#flintdigest) below.

**Response `200`** (`all=true`): `{"date": "...", "count": 2, "digests": [FlintDigest, ...]}`

**Response `404`** — No digest found for that date (and period, if given).

### `GET /api/v1/flint/digests/{id}`

A single digest by event UUID. Same [FlintDigest](#flintdigest) shape.

### `POST /api/v1/flint/digests`

Creates a Flint digest event with attached blocks. **Non-idempotent** — do
not blindly retry after an unknown outcome; check
`GET /flint/digests` first. Same request/validation as MCP's
`create-flint-digest` tool — see [MCP.md](MCP.md#create-flint-digest) for
the full block-type reference (`flint_user_question`,
`flint_editorial_note`, any other `flint_*` type).

**Response `201`**: `{"event_id": "uuid", "block_ids": ["uuid", ...]}`

### `POST /api/v1/flint/questions/{block}/answer`

Records the user's answer to a `flint_user_question` block.

**Request body**: `{"answer": "Yes", "answer_note": "optional"}` — `answer`
required (max 1000 chars), `answer_note` optional (max 1000 chars).

**Response `200`**: `{"block_id": "uuid", "answer": "Yes", "answer_note": null, "answered_at": "2026-05-10T09:00:00+00:00"}`

**Response `403`** — Block's digest doesn't belong to the caller.

**Response `422`** — Block is not a `flint_user_question`.

---

## `finance:read` and `finance:write`

Manual finance accounts are `EventObject` rows with `concept = 'account'`
and `type = 'manual_account'`; balances are `Event` rows
(`action = had_balance`). All mutation routes 422 on a non-`manual_account`
target — synced accounts (Monzo, GoCardless) are read-only here.

### `GET /api/v1/finance/accounts`

All non-archived accounts with their latest balance.

**Response `200`**: `{"data": [MoneyAccount, ...]}` — see
[MoneyAccount](#moneyaccount) below.

### `GET /api/v1/finance/accounts/{id}`

A single account. Same [MoneyAccount](#moneyaccount) shape, wrapped in
`{"data": ...}`.

### `GET /api/v1/finance/accounts/{id}/balances`

Cursor-paginated balance history, newest first (25 per page).

**Response `200`**: `{"data": [BalanceEntry, ...], "next_cursor": "...", "has_more": false}`

### `POST /api/v1/finance/accounts`

Creates a manual account.

**Request body**

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

| Field                  | Type    | Required | Notes                                                                                |
| ----------------------- | ------- | -------- | ------------------------------------------------------------------------------------- |
| `name`                 | string  | Yes      | max 255                                                                              |
| `account_type`         | string  | Yes      | `current_account`, `savings_account`, `mortgage`, `investment_account`, `credit_card`, `loan`, `pension`, `other` |
| `currency`             | string  | Yes      | `GBP`, `USD`, `EUR`                                                                  |
| `provider`             | string  | No       | max 255                                                                              |
| `account_number`       | string  | No       | max 255                                                                              |
| `sort_code`            | string  | No       | max 8                                                                                |
| `interest_rate`        | number  | No       | 0–100                                                                                |
| `start_date`           | date    | No       | `YYYY-MM-DD`                                                                         |
| `is_negative_balance`  | boolean | No       | Forced `true` for `credit_card`/`loan`/`mortgage` regardless of what's sent          |

**Response `201`**: `{"data": MoneyAccount}` (no balance yet).

### `PATCH /api/v1/finance/accounts/{id}`

Updates a manual account. Requires `If-Match`. All fields `sometimes`
(partial update); the same allow-list as `POST`. Fields under
`metadata.integration_id` / `account_id` / `pot_id` / `raw` are always
preserved regardless of what's sent.

**Response `422`** — Account is not `manual_account` (synced accounts can't
be edited here). **Response `428`/`412`** — Missing/stale `If-Match`.

### `DELETE /api/v1/finance/accounts/{id}`

Archives (never hard-deletes) a manual account: writes a final zero-balance
event with a note, then sets `metadata.deleted = true` and
`metadata.archived_at`. Requires `If-Match`.

**Response `200`**: `{"message": "Account archived."}`

**Response `422`** — Account is not `manual_account`.

### `POST /api/v1/finance/accounts/{id}/balances`

Adds a balance entry and touches the account (advancing its ETag). Requires
`If-Match`.

**Request body**: `{"balance": 1500.00, "date": "2026-05-10", "notes": "optional, max 1000 chars"}`

**Response `201`**: `{"data": BalanceEntry}`

---

## `data:write`

### `PATCH /api/v1/{kind}/{id}`

Non-destructive update of an owned event, object, or block. `{kind}` ∈
`events`, `objects`, `blocks`. Requires `If-Match`. Same
`EntityMutationService` and allow-list MCP's `update-entity` tool uses —
see [MCP.md](MCP.md#update-entity) for the exact allowed fields per kind.

**Response `200`**: the updated entity in its Compact resource shape.

**Response `404`** — Not found or not owned.

**Response `422`** — Disallowed field or invalid value (from
`EntityMutationService::validateUpdate`). **Response `428`/`412`** —
Missing/stale `If-Match`.

### `PATCH /api/v1/events/{id}/note`

Sets or clears the user-authored note block on an event (`note` block
type). Requires `If-Match`. Same behavior as MCP's `set-event-note`.

### `POST /api/v1/events/{id}/tags` / `DELETE /api/v1/events/{id}/tags/{tagId}`

Attach or detach a tag on an owned event. Requires `If-Match`.

**Request body (`POST`)**: either `{"tag_id": 12}` to attach an existing tag,
or `{"name": "running", "type": "spark"}` to find-or-create one. `type` is
inferred from a `type_name` / `type:name` prefix in `name` when omitted, or
detected as `emoji` for a single-emoji name, defaulting to `spark`
otherwise.

**Response `201`**: `{"tag": Tag, "tags": [Tag, ...]}` (the entity's full
tag list after attaching), with a fresh `ETag` header.

**Response `404`** — Entity or `tag_id` not found.

**Response `422`** — Neither `tag_id` nor a resolvable `name` supplied.

### `POST /api/v1/objects/{id}/tags` / `DELETE /api/v1/objects/{id}/tags/{tagId}`

Same as the event variants, scoped to an owned object.

### `POST /api/v1/bookmarks`

Bookmarks a URL (same service as the legacy `POST /api/fetch/bookmarks` and
the mobile share-extension endpoint).

**Request body**: `{"url": "https://example.com/article"}` (required, valid URL, max 2048 chars).

**Response `201`/`200`**: `{"state": "...", "bookmark": {"id": "uuid", "url": "..."}}`
(`201` when newly created, `200` when it already existed).

**Response `422`** — URL fails the safety validator.

### `POST /api/v1/knowledge/events/{id}/reprocess`

Queues AI reprocessing for a Fetch or Newsletter knowledge event. Requires
`If-Match`. Identical to `POST /api/v1/mobile/knowledge/events/{id}/reprocess`
— see [mobile_API.md](mobile_API.md#post-knowledgeeventsidreprocess) for the
`mode` options and response shape.

### `POST /api/v1/{kind}/{id}/relationships`

Creates a relationship from an owned event/object/block to another entity.
Requires `If-Match`. Prevents self-links and enforces the registered
relationship-type directionality — same rules as MCP's
`manage-relationship` create operation.

**Request body**: `{"to_kind": "objects", "to_id": "uuid", "type": "linked_to", "value": null, "value_multiplier": null, "value_unit": null, "metadata": {}}`

**Response `201`**: [Relationship](#relationship).

**Response `422`** — Invalid endpoints, unregistered type, or ownership
mismatch.

### `DELETE /api/v1/relationships/{relationship}`

Deletes an owned relationship by its UUID. Requires `If-Match`.

**Response `204`** — No content.

**Response `404`** — Not found or not owned.

### `POST /api/v1/check-ins`

Idempotent by `(user, period, date)` — resubmitting the same period/date
updates the existing check-in rather than creating a duplicate. Same
request/response shape as `POST /api/v1/mobile/check-ins` — see
[mobile_API.md](mobile_API.md#post-check-ins).

### `POST /api/v1/anomalies/{id}/acknowledge`

Acknowledges a metric anomaly, optionally with a note and a suppression
date. Identical to `POST /api/v1/mobile/anomalies/{id}/acknowledge`.

### `POST /api/v1/up-to-speed/read`

Marks one or more Up to Speed items as caught up. Idempotent — reposting
the same items is a no-op.

**Request body**: `{"items": [{"type": "flint_digest", "id": "uuid"}, ...]}`
(1–50 items; `type` ∈ `flint_digest`, `anomaly`, `news_summary`).

**Response `200`**: `{"marked": 2}` — count of newly-marked items (already-marked items don't increment it).

---

## Shared response schemas

Full field-level detail for `CompactEvent`, `CompactObject`, `CompactBlock`,
`CompactIntegration`, `CompactMetric`, `CompactPlace`, and
`UserProfile` lives in
[mobile_API.md's Response Schemas](mobile_API.md#response-schemas) — these
are the exact same `App\Http\Resources\Compact\*` classes on both surfaces,
documented once to avoid drift.

### Relationship

```json
{
    "id": "uuid",
    "from_type": "event",
    "from_id": "uuid",
    "to_type": "object",
    "to_id": "uuid",
    "type": "linked_to",
    "value": null,
    "value_multiplier": null,
    "value_unit": null,
    "metadata": {}
}
```

### FlintDigest

```json
{
    "event_id": "uuid",
    "digest_object_id": "uuid",
    "date": "2026-05-10",
    "period": "morning",
    "title": "Morning Digest",
    "summary": "Optional headline summary.",
    "created_at": "2026-05-10T06:00:00+00:00",
    "block_count": 3,
    "unanswered_question_count": 1,
    "blocks": [
        {
            "id": "uuid",
            "block_type": "flint_user_question",
            "title": "Check in",
            "time": "2026-05-10T06:00:00+00:00",
            "question": "How did you sleep?",
            "topic": "health",
            "priority": "medium",
            "answer_options": null,
            "answer": null,
            "answer_note": null,
            "answered_at": null,
            "answered": false
        }
    ]
}
```

Non-question blocks instead carry `content` (markdown, with `[[event:...]]`
references linkified) and an optional `references` array.

### MoneyAccount

```json
{
    "id": "uuid",
    "title": "Joint Savings",
    "kind": "manual_account",
    "account_type": "savings_account",
    "currency": "GBP",
    "is_negative_balance": false,
    "provider": "Monzo",
    "account_number": null,
    "sort_code": null,
    "interest_rate": 4.1,
    "start_date": "2024-01-01",
    "integration_id": null,
    "latest_balance": {
        "id": "uuid",
        "balance": 15234.5,
        "currency": "GBP",
        "time": "2026-05-01T00:00:00+00:00",
        "notes": null
    },
    "updated_at": "2026-05-01T00:00:00+00:00"
}
```

`latest_balance` is `null` for a newly created account with no balance
history yet.

### BalanceEntry

```json
{
    "id": "uuid",
    "balance": 15234.5,
    "currency": "GBP",
    "time": "2026-05-01T00:00:00+00:00",
    "notes": null
}
```

---

## Legacy `/api` reference

Predates the ability system and the `/api/v1` capability model: these
routes require only `auth:sanctum` (no `spark.ability` gate, except the one
route noted below), have no `etag` middleware, and mostly return raw
Eloquent models rather than Resource classes — no Form Request classes
exist anywhere in the app, so all validation is inline. Treated as a
maintained but non-primary surface; use `/api/v1` for new integrations.

| Method                             | Path                                    | Controller / action                              | Description                                                                    | `/api/v1` equivalent |
| ----------------------------------- | ---------------------------------------- | -------------------------------------------------- | -------------------------------------------------------------------------------- | ---------------------- |
| GET/POST/PUT/DELETE                 | `/api/events[/{event}]`                 | `EventApiController` (index/show/store/update/destroy) | Full event CRUD, including nested actor/target/blocks creation in one request | `GET /events[/{id}]`, `PATCH /events/{id}` (no create/delete on v1) |
| POST                                 | `/api/search/events`                     | `SearchApiController@searchEvents`                | Keyword/semantic event search                                                    | `GET /search`         |
| POST                                 | `/api/search/blocks`                     | `SearchApiController@searchBlocks`                | Block search                                                                     | `GET /search`         |
| POST                                 | `/api/search/objects`                    | `SearchApiController@searchObjects`               | Object search                                                                    | `GET /search`         |
| POST                                 | `/api/search`                            | `SearchApiController@searchAll`                   | Combined search across events/objects                                           | `GET /search`         |
| POST                                 | `/api/search/semantic`                   | `SemanticSearchController@search`                 | Pure semantic (embedding) search, 5-minute cached                              | `GET /search?mode=semantic` |
| POST                                 | `/api/tokens/create`                     | inline closure                                    | Creates a Sanctum token with **no ability restriction** (full access)          | — (web settings only) |
| GET                                  | `/api/tokens`                            | inline closure                                    | Lists the caller's tokens                                                        | —                     |
| DELETE                               | `/api/tokens/{token}`                    | inline closure                                    | Revokes a token                                                                  | —                     |
| GET                                  | `/api/integrations[/{integration}]`      | `IntegrationApiController@index/show`             | List/show integrations                                                          | `GET /integrations[/{id}]` |
| POST                                 | `/api/integrations/{integration}/configure` | `IntegrationApiController@configure`           | Update integration configuration                                                | —                     |
| POST                                 | `/api/integrations/{integration}/trigger`| `IntegrationApiController@trigger`                | Trigger an immediate fetch                                                       | `POST /integrations/{id}/sync` |
| DELETE                               | `/api/integrations/{integration}`        | `IntegrationApiController@destroy`                | Remove an integration                                                           | —                     |
| POST                                 | `/api/fetch/bookmarks` (`ability:bookmark:write`) | `FetchApiController@bookmarkUrl`         | Bookmark a URL                                                                   | `POST /bookmarks`     |
| GET                                  | `/api/assistant/context`                 | `AssistantContextController@index`                | Assistant-oriented context payload                                              | `GET /day-summary`, `GET /events/{id}` (no direct 1:1) |
| POST                                 | `/api/flint/questions/{block}/answer`    | `FlintQuestionsController@answer`                 | Answer a Flint user-question block                                             | `POST /flint/questions/{block}/answer` |
| GET                                  | `/api/task-executions[/{taskExecution}]` | `TaskExecutionController@index/show`              | Task pipeline execution records (uses `TaskExecutionResource`)                  | —                     |
| POST                                 | `/api/clear-card-cache`                  | inline closure                                    | Flushes the **entire** cache store (not scoped to the caller — see code comment) | —                     |
| GET                                  | `/api/user`                              | inline closure                                    | Returns the authenticated user model                                            | —                     |
| POST                                 | `/api/oauth/token`, `/api/oauth/refresh` | `Auth\OAuthController@token/refresh`              | Unauthenticated iOS PKCE token exchange/refresh (`throttle:oauth`)              | —                     |

---

## Related Documentation

- [README.md](README.md) — Capability model, surface matrix, and the
  ETag/If-Match asymmetry in full
- [mobile_API.md](mobile_API.md) — Mobile adapter reference (shares most
  controllers with this surface)
- [MCP.md](MCP.md) — MCP tool reference (shares abilities and services with
  this surface)
- [EVENTS.md](../Architecture/EVENTS.md), [OBJECTS.md](../Architecture/OBJECTS.md), [BLOCKS.md](../Architecture/BLOCKS.md) — underlying data model
