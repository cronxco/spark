# Spark MCP Server

Spark exposes an MCP (Model Context Protocol) server so AI agents can read
and act on a user's Spark data using the same authorization model and
underlying services as the REST API. See [README.md](README.md) for the
cross-surface capability model before diving into individual tools.

## Table of Contents

- [Overview](#overview)
- [Connecting a client](#connecting-a-client)
- [Tool naming caveat](#tool-naming-caveat)
- [Tool reference](#tool-reference)
- [Resource reference](#resource-reference)
- [Prompts](#prompts)
- [Authorization model](#authorization-model)
- [Observability](#observability)
- [Testing coverage](#testing-coverage)
- [Related Documentation](#related-documentation)

---

## Overview

The server is registered in `routes/ai.php`:

```php
Mcp::web('/mcp/spark', SparkServer::class)->middleware('auth:sanctum');
```

- **Endpoint**: `/mcp/spark` (both `GET` and `POST`, per the MCP HTTP
  transport)
- **Server class**: `App\Mcp\Servers\SparkServer` (`app/Mcp/Servers/SparkServer.php`)
- **Name / version**: `Spark` / `1.0.0`
- **Auth**: `auth:sanctum` at the transport level authenticates the caller;
  it does **not** by itself authorize any operation. Every tool and
  resource independently enforces its own capability via the
  `App\Mcp\Concerns\RequiresSparkAbility` trait, which calls the same
  `App\Support\SparkAbility::allows()` class the REST API's
  `spark.ability:*` middleware uses. A narrowly-scoped token (e.g. one with
  only `flint:read`) is authenticated by Sanctum but still rejected by
  every tool that needs a different ability.
- **Shared services**: MCP tools call the same query/command services as
  `/api/v1` — `EventLookup`, `ObjectLookup`, `BlockLookup`,
  `EntityMutationService`, `EventFeed`, `MetricTrendService`,
  `FlintDigestService`, and the `Compact*Resource` response classes — so
  behavior and response shapes match the REST equivalents documented in
  [API_v1.md](API_v1.md).

## Connecting a client

Send a Sanctum bearer token on every request, same as REST:

```
Authorization: Bearer <token>
```

`tools/list` returns every registered tool in a single page —
`SparkServer::$defaultPaginationLength = 50`, comfortably above the current
23 tools, so clients that don't implement cursor-following still see the
full tool set. The server's own `$instructions` block (returned during
`initialize`) gives the LLM client a curated tour of the tools grouped by
task — briefing/summary, metrics/trends, precise filtering, search/detail,
actions, Flint digest, and web fetching — which this document mirrors
below.

## Tool naming caveat

Only 9 of the 23 tool classes carry an explicit `#[Laravel\Mcp\Server\Attributes\Name]`
attribute. A tool without one gets its registered MCP name from the
framework default — `Str::kebab(class_basename($this))` — which does
**not** strip a trailing `Tool` suffix. So, for example, `GetDaySummaryTool`
registers as `get-day-summary-tool`, not `get-day-summary`. The table below
gives the **verified** registered name for every tool (confirmed by
instantiating each class and reading `->name()` / `->toArray()` directly,
not by guessing from the class name) — always prefer this table, the raw
[`openapi/mcp-tools.json`](openapi/mcp-tools.json) snapshot, or your own
live `tools/list` call over the server's own prose instructions or class
names when you need the exact string a client must send.

---

## Tool reference

All 23 tools use `RequiresSparkAbility` and return a `Response::error(...)`
immediately if the caller's token lacks the required ability. Every "Yes"
in **Read-only** below corresponds to the compiled `readOnlyHint: true`
annotation; **Idempotent** likewise reflects the compiled
`idempotentHint: true` annotation — these come from the same verified
`toArray()` dump as the parameter tables.

### Briefing & Summary

#### `get-day-summary-tool`

*Class*: `GetDaySummaryTool` · *Ability*: `insights:read` · *Read-only, idempotent*

Compact, pre-aggregated summary for one or more dates — structured domain
sections (health, activity, money, media, knowledge) with baseline
comparisons and anomaly detection. Preferred over `get-day-context-tool`
for daily briefings.

| Parameter | Type            | Required | Default    | Notes                                                        |
| --------- | ---------------- | -------- | ---------- | -------------------------------------------------------------- |
| `dates`   | array of string  | No       | `["today"]` | ISO or relative (`today`, `yesterday`, `tomorrow`)            |
| `domains` | array of enum    | No       | all        | `health`, `activity`, `money`, `media`, `knowledge`            |

#### `get-day-context-tool`

*Class*: `GetDayContextTool` · *Ability*: `insights:read` · *Read-only, idempotent*

Full raw day context — events, metrics, and relationships for one date,
grouped by service/action/hour, plus service breakdown. Larger response
than `get-day-summary-tool`; prefer that unless you need the raw detail.
Mirrors the `day-context-resource` MCP resource below.

| Parameter | Type          | Required | Default | Notes                                                    |
| --------- | -------------- | -------- | ------- | ----------------------------------------------------------- |
| `date`    | string         | No       | `today` | ISO or relative (`today`, `yesterday`, `tomorrow`)          |
| `domains` | array of enum  | No       | all     | `health`, `money`, `media`, `knowledge`, `online`           |

#### `get-service-status-tool`

*Class*: `GetServiceStatusTool` · *Ability*: `insights:read` · *Read-only, idempotent*

Sync status and data coverage for all services on a given date — event
count, last event time, distinct actions, coverage notes for services with
known sync lag (e.g. Apple Health).

| Parameter | Type   | Required | Default | Notes |
| --------- | ------ | -------- | ------- | ----- |
| `date`    | string | No       | `today` | ISO or relative |

#### `get-check-ins`

*Class*: `GetCheckInsTool` · *Ability*: `insights:read` · *Read-only, idempotent*

Morning and afternoon daily check-in records for a date, including
completion and recorded energy values.

| Parameter | Type   | Required | Default | Notes                       |
| --------- | ------ | -------- | ------- | ----------------------------- |
| `date`    | string | No       | `today` | `YYYY-MM-DD`, `today`, `yesterday` |

### Metrics & Trends

#### `get-metric-trend-tool`

*Class*: `GetMetricTrendTool` · *Ability*: `insights:read` · *Read-only, idempotent*

Daily metric values over a date range with baseline comparison — per-day
values, `vs_baseline_pct`, anomaly flags, and summary statistics. Accepts
flexible identifiers (`oura.had_sleep_score.percent`, `oura.sleep_score`);
the `had_` prefix and unit suffix can be omitted when unambiguous.

| Parameter | Type   | Required | Default        | Notes                                                                  |
| --------- | ------ | -------- | --------------- | ------------------------------------------------------------------------- |
| `metric`  | string | **Yes**  | —               | Dot-notation identifier                                                  |
| `from`    | string | No       | `30_days_ago`   | ISO, relative, or range keyword (`last_7_days`, `this_week`, `last_month`) |
| `to`      | string | No       | `today`         | ISO or relative                                                          |

#### `get-baselines-tool`

*Class*: `GetBaselinesTool` · *Ability*: `insights:read` · *Read-only, idempotent*

Baseline statistics (mean, stddev, min, max, normal bounds, sample size)
for one or more metrics. Omit `metrics` entirely to discover every
available baseline.

| Parameter | Type            | Required | Notes                            |
| --------- | ---------------- | -------- | ----------------------------------- |
| `metrics` | array of string  | No       | Omit to get all available baselines |

### Precise Filtering

#### `get-events-by-filter-tool`

*Class*: `GetEventsByFilterTool` · *Ability*: `data:read` · *Read-only, idempotent*

Exact filtering by service, action, and date range — for precise queries
("all Monzo transactions this week") that semantic search handles poorly.

| Parameter   | Type    | Required | Default | Notes                                  |
| ----------- | ------- | -------- | ------- | ----------------------------------------- |
| `service`   | string  | **Yes**  | —       | e.g. `oura`, `apple_health`, `monzo`      |
| `action`    | string  | No       | —       | Omit for all actions on the service       |
| `from_date` | string  | No       | last 30 days | ISO, relative, or range keyword     |
| `to_date`   | string  | No       | today   | ISO or relative                           |
| `limit`     | integer | No       | 50      | 1–100                                     |

### Search & Detail

#### `search-events-tool` / `search-blocks-tool` / `search-objects-tool`

*Classes*: `SearchEventsTool`, `SearchBlocksTool`, `SearchObjectsTool` ·
*Ability*: `data:read` · *Read-only, idempotent*

Semantic (vector similarity) or keyword search over events, blocks, or
objects respectively. Semantic mode adds a `similarity` score per result.

| Parameter    | Type    | Required | Default | Notes                                                        | Which tool(s) |
| ------------ | ------- | -------- | ------- | ---------------------------------------------------------------- | -------------- |
| `query`      | string  | **Yes**  | —       | Search text                                                       | all three      |
| `semantic`   | boolean | No       | `true`  | Enable vector similarity search                                   | all three      |
| `service`    | string  | No       | —       | e.g. `monzo`, `oura`, `spotify`                                   | events only    |
| `domain`     | enum    | No       | —       | `health`, `money`, `media`, `knowledge`, `online`                 | events only    |
| `block_type` | string  | No       | —       | e.g. `fetch_summary_paragraph`, `heart_rate`                      | blocks only    |
| `concept`    | string  | No       | —       | e.g. `user`, `track`, `account`, `merchant`, `place`              | objects only   |
| `type`       | string  | No       | —       | e.g. `spotify_track`, `monzo_merchant`                            | objects only   |
| `from_date` / `to_date` | string | No | — | ISO date filter                                                 | events, blocks |
| `limit`      | integer | No       | 20      | max 50                                                            | all three      |

#### `get-event-tool` / `get-object-tool` / `get-block-tool`

*Classes*: `GetEventTool`, `GetObjectTool`, `GetBlockTool` ·
*Ability*: `data:read` · *Read-only, idempotent*

Full detail for a specific entity by UUID, ownership-scoped through the
caller — a cross-user ID is treated as not found.

| Tool             | Parameter        | Type    | Required | Default | Notes                                          |
| ----------------- | ---------------- | ------- | -------- | ------- | ------------------------------------------------- |
| `get-event-tool`  | `id`             | string  | **Yes**  | —       | Event UUID                                       |
| `get-object-tool` | `id`             | string  | **Yes**  | —       | Object UUID                                      |
| `get-object-tool` | `include_events` | boolean | No       | `true`  | Attach recent events where this object appears    |
| `get-object-tool` | `event_limit`    | integer | No       | 10      | 1–25                                              |
| `get-block-tool`  | `id`             | string  | **Yes**  | —       | Block UUID                                       |

#### `list-integrations`

*Class*: `ListIntegrationsTool` · *Ability*: `integrations:read` · *Read-only, idempotent*

Lists the user's integrations — service, status, and identifying details. No
parameters.

### Actions

#### `set-event-note`

*Class*: `SetEventNoteTool` · *Ability*: `data:write`

Sets or clears the user-authored note attached to an event. Pass `null` or
an empty string to clear.

| Parameter  | Type   | Required | Notes                              |
| ----------- | ------ | -------- | ------------------------------------- |
| `event_id`  | string | **Yes**  | Event UUID                           |
| `note`      | string | No       | Omit or empty to clear                |

#### `update-entity`

*Class*: `UpdateEntityTool` · *Ability*: `data:write`

Safely updates an owned event, object, or block. Never deletes records or
changes integration ownership. Same allow-list and validation
(`EntityMutationService::validateUpdate`) that `PATCH /api/v1/{kind}/{id}`
uses.

| Parameter    | Type   | Required | Notes                                                                                                                      |
| ------------- | ------ | -------- | ------------------------------------------------------------------------------------------------------------------------------ |
| `kind`        | string | **Yes**  | `event`, `object`, or `block`                                                                                                 |
| `id`          | string | **Yes**  | Entity UUID                                                                                                                    |
| `attributes`  | object | **Yes**  | Allowed fields — event: `action`/`value`/`value_multiplier`/`value_unit`/`time`; object: `title`/`type`/`concept`/`url`; block: `title`/`block_type`/`value`/`value_multiplier`/`value_unit`/`time`/`url` |

#### `manage-relationship`

*Class*: `ManageRelationshipTool` · *Ability*: `data:read` for `operation: list`, otherwise `data:write`

Lists, creates, or deletes an owned relationship between events, objects,
and blocks. Creation prevents self-links and respects registered
relationship-type directionality.

| Parameter          | Type   | Required | Notes                                          |
| ------------------- | ------ | -------- | ------------------------------------------------- |
| `operation`         | string | **Yes**  | `list`, `create`, or `delete`                     |
| `kind` / `id`       | string | No       | Source entity, for `list`/`create`                |
| `relationship_id`   | string | No       | For `delete`                                       |
| `to_kind` / `to_id` | string | No       | Target entity, for `create`                        |
| `type`              | string | No       | Registered relationship type, for `create`         |
| `value` / `value_multiplier` | number | No | Optional, for `create`                          |
| `value_unit`        | string | No       | Optional, for `create`                             |
| `metadata`          | object | No       | Optional, for `create`                             |

#### `trigger-integration-update-tool`

*Class*: `TriggerIntegrationUpdateTool` · *Ability*: `integrations:sync`

Triggers an immediate on-demand fetch for one integration instance or every
non-paused instance of a service, without affecting the scheduled pull
cycle.

| Parameter        | Type   | Required | Notes                                                     |
| ----------------- | ------ | -------- | -------------------------------------------------------------- |
| `integration_id`  | string | No       | Specific instance UUID — takes precedence over `service`      |
| `service`        | string | No       | Triggers all non-paused instances of this service               |

### Flint Digest

#### `create-flint-digest`

*Class*: `CreateFlintDigestTool` · *Ability*: `flint:write`

Creates a Flint digest event with an array of blocks. **Non-idempotent** —
do not retry after an unknown outcome without checking
`get-latest-flint-digest` first. Returns `event_id` and `block_ids`.

| Parameter | Type            | Required | Default | Notes                                                                    |
| --------- | ---------------- | -------- | ------- | ----------------------------------------------------------------------- |
| `title`   | string           | **Yes**  | —       | Digest title                                                             |
| `period`  | string           | No       | inferred from current time | `morning`, `afternoon`, or `evening`                  |
| `date`    | string           | No       | `today` | ISO date                                                                 |
| `summary` | string           | No       | —       | Optional headline summary                                                |
| `blocks`  | array of object  | No       | —       | Each requires `block_type` + `title`; see field notes below              |

Each block object supports: `content` (markdown, for `flint_editorial_note`
and other content types), `referenced_event_ids` (surfaced as tappable
reference chips and linkified inline), and for `flint_user_question`:
`question`, `topic`, `priority` (`low`/`medium`/`high`), `answer_options`
(omit for freeform).

#### `get-latest-flint-digest`

*Class*: `GetLatestFlintDigestTool` · *Ability*: `flint:read` · *Read-only, idempotent*

Retrieves Flint digest(s) for a date, including all blocks. Defaults to
today's most recent digest. For `flint_user_question` blocks, returns the
user's `answer`, `answer_note`, and `answered_at` (null until answered) —
use this to check whether previously-asked questions have been answered.

| Parameter | Type    | Required | Default | Notes                                                        |
| --------- | ------- | -------- | ------- | ----------------------------------------------------------- |
| `date`    | string  | No       | `today` | ISO date                                                     |
| `period`  | string  | No       | latest  | `morning`, `afternoon`, or `evening`                          |
| `all`     | boolean | No       | `false` | Return every digest for the date instead of just the latest |

#### `answer-flint-question`

*Class*: `AnswerFlintQuestionTool` · *Ability*: `flint:write`

Answers a `flint_user_question` block. Use after retrieving a digest with
`get-latest-flint-digest`.

| Parameter     | Type   | Required | Notes                        |
| ------------- | ------ | -------- | ------------------------------ |
| `block_id`    | string | **Yes**  | UUID of a `flint_user_question` block |
| `answer`      | string | **Yes**  | The user's answer               |
| `answer_note` | string | No       | Optional supporting note        |

### Web Fetching

#### `fetch-webpage-html`

*Class*: `FetchWebpageHtmlTool` · *Ability*: `web:fetch` (MCP-only — never
available through REST or mobile, since it can use saved browser cookies)

Renders a URL with Playwright and returns its raw HTML. Saved Fetch cookies
for the target domain are used automatically when available, and any
refreshed cookies are persisted back to the caller's Fetch cookie store.
HTML is capped at 1 MB; the response says explicitly if it was truncated.
URLs are validated by `UrlSafetyValidator` before any request is made.

| Parameter | Type   | Required | Notes                          |
| --------- | ------ | -------- | --------------------------------- |
| `url`     | string | No*      | Public HTTP/HTTPS URL to render   |

\* The compiled schema does not mark `url` required, but the tool errors
without a usable URL in practice — treat it as required.

---

## Resource reference

### `day-context-resource`

*Class*: `App\Mcp\Resources\DayContextResource` · *Ability*: `insights:read`

- **URI template**: `spark://context/day/{date}`
- **MIME type**: `application/json`
- **`date`**: ISO date or `today`/`yesterday`/`tomorrow` (default `today`)

Structured day context including events, metrics, and relationships for a
specific date — the resource-style equivalent of `get-day-context-tool`,
without that tool's `domains` filter. Internally reuses
`AssistantContextService::generateTimeframeContext()` against a
mock/shared "flint/assistant" integration, and returns pretty-printed JSON.

---

## Prompts

None are registered — `SparkServer::$prompts` is an empty array and no
`app/Mcp/Prompts/` directory exists.

---

## Authorization model

Every tool and the one resource share the same enforcement path:

1. `auth:sanctum` on `/mcp/spark` authenticates the caller (valid bearer
   token → `$request->user()`). This alone grants no capability.
2. `RequiresSparkAbility::requireAbility($request, $ability)` calls
   `App\Support\SparkAbility::allows($user, $ability)` — the identical
   class the REST `spark.ability:*` middleware uses. If the token lacks the
   ability, the tool returns `Response::error("Token lacks required
   capability: {$ability}.")` without touching any data.
3. A request with no user at all (auth failed) returns
   `Response::error('Authentication required.')`.

**Legacy `mcp:read` alias**: `SparkAbility::LEGACY_ALIASES` lets a token
carrying only the older `mcp:read` ability still satisfy `data:read`,
`insights:read`, `integrations:read`, and `flint:read` — but never a
`:write` ability, and never `web:fetch`. This is a deliberate, read-only
migration path, not a broader grant.

**No Policies or Gates** are involved anywhere in this chain — it's this
one middleware/trait pair, consistently, across both MCP and REST. The one
exception: a non-token session (`$user->currentAccessToken() === null`)
only passes `SparkAbility::allows()` inside the `testing` environment, which
exists purely to keep Laravel MCP's in-process `SparkServer::actingAs()`
test helper ergonomic — it does not make a real cookie-authenticated
request all-powerful in any other environment.

---

## Observability

`app/Mcp/Tracing/McpSpan.php` wraps three JSON-RPC phases in Sentry spans
(no-op if `config('mcp.sentry.enabled', true)` is false or there's no
active parent span):

- **`tools/call`** — a child span tagged with `mcp.tool.name`,
  `mcp.transport`, `mcp.request.id`, `mcp.session.id`, and each primitive
  argument as `mcp.request.argument.{key}` (truncated at 1000 chars);
  marks `mcp.tool.result.is_error` and re-throws on exception.
- **`resources/read`** — same pattern, tagged with `mcp.resource.uri` /
  `mcp.resource.name`.
- **`initialize`** — tags client name/version, then calls
  `setSessionMetadata()` to stamp `mcp.client.name/version`,
  `mcp.server.name/version`, and `mcp.protocol.version` on the Sentry scope
  for every subsequent span in the session.

`App\Mcp\Tracing\TransportDetector` maps the live `Transport` instance to
`"http"`/`"stdio"`/`"unknown"` (and the OSI-level `network.transport` tag)
for the spans above.

---

## Testing coverage

`tests/Feature/Mcp/` covers `SparkServerTest.php` (registration/auth for
several read tools, ownership-scoping denials, UUID validation, keyword
filtering), plus dedicated files for `AcknowledgeAnomalyTool`,
`CreateFlintDigestTool`, `GetLatestFlintDigestTool`,
`FetchWebpageHtmlTool`, `GetDaySummaryTool`, `GetEventsByFilterTool`, and
`GetMetricTrendTool`. No dedicated test file exists yet for
`SetEventNoteTool`, `UpdateEntityTool`, `ManageRelationshipTool`,
`TriggerIntegrationUpdateTool`, `AnswerFlintQuestionTool`,
`GetBaselinesTool`, `GetServiceStatusTool`, or `DayContextResource` — worth
knowing before assuming a behavior is pinned by a test.

---

## Related Documentation

- [README.md](README.md) — Cross-surface capability model and parity matrix
- [API_v1.md](API_v1.md) — REST equivalents of the shared entity/relationship/Flint operations
- [openapi/mcp-tools.json](openapi/mcp-tools.json) — Verified JSON Schema snapshot of every tool
