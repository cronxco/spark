# Spark API Documentation

This directory documents Spark's three programmatic surfaces — the general REST API, the iOS mobile adapter, and the MCP server — and how they relate to one another.

## Where to look

| Document | Covers |
| --- | --- |
| [API_v1.md](API_v1.md) | `/api/v1` full endpoint reference, plus a brief legacy `/api` section |
| [mobile_API.md](mobile_API.md) | `/api/v1/mobile` full endpoint reference (iOS companion app) |
| [MOBILE_CHECK_INS.md](MOBILE_CHECK_INS.md) | Deep dive on the check-in domain data model, shared by web and mobile |
| [MCP.md](MCP.md) | Spark's MCP server (`/mcp/spark`) — tools, resources, and authorization |
| [openapi/api-v1.openapi.yaml](openapi/api-v1.openapi.yaml) | Machine-readable OpenAPI 3.1 spec for `/api/v1` |
| [openapi/mobile-api.openapi.yaml](openapi/mobile-api.openapi.yaml) | Machine-readable OpenAPI 3.1 spec for `/api/v1/mobile` |
| [openapi/mcp-tools.json](openapi/mcp-tools.json) | Verified JSON Schema snapshot of every MCP tool |

## Canonical v1 API

`/api/v1` is Spark's client-neutral, capability-scoped API. It is the REST
counterpart to the Spark MCP server; `/api` remains supported as a legacy
surface and `/api/v1/mobile` remains an iOS-specific adapter.

Use least-privilege Sanctum abilities per operation: `data:read`,
`data:write`, `insights:read`, `insights:write`, `integrations:read`,
`integrations:sync`, `flint:read`, `flint:write`, `finance:read`, and
`finance:write`. `web:fetch` is a separate, MCP-only capability because it can
use saved browser cookies. Existing `mcp:read` tokens are accepted as a
read-only compatibility alias for `data:read`, `insights:read`,
`integrations:read`, and `flint:read` during migration.

Public API and MCP calls require a Sanctum bearer token; cookie sessions are
not capability credentials (`SparkAbility::allows()` only grants access to a
non-token session inside the `testing` environment, to keep Laravel MCP's
in-process test helper ergonomic).

Collection reads use a weak representation ETag for cache revalidation.
Detail reads of mutable entities emit a strong opaque resource ETag
(`ResourceVersion::etag()`), backed by Postgres's `xmin` system column
rather than a timestamp — `updated_at` is whole-second precision, so two
writes inside one second would otherwise hash to the same "version" and
silently defeat the check. Both `/api/v1` and `/api/v1/mobile` require
clients to send this in `If-Match` when changing an event, object, block,
its relationships, an event note, tag assignment, integration, notification,
or a manual finance account/balance — missing preconditions receive `428`,
stale tokens receive `412`, and both include the current `ETag`. The
version check and the write happen inside one row-locked transaction, so a
second writer can't slip a stale check in underneath the first. See
[API_v1.md](API_v1.md#etag-and-if-match) for the verified route-by-route
detail. Digest creation remains deliberately non-idempotent and does not use
`If-Match` on either surface.

### Command retry semantics

Not every safe write has a single resource representation to version. Daily
check-ins are naturally idempotent by user, period, and date; bookmarks dedupe
by user and URL; Up-to-Speed read receipts are idempotent; and knowledge
reprocessing is guarded by the target event ETag. A service-wide integration
sync and Flint digest creation intentionally remain non-idempotent commands:
clients must not automatically retry them after an unknown outcome.

The v1 surface exposes event/object/block/feed/search/tag/map/place
data; day summaries, metrics, check-ins, health dashboard, anomalies, and Up
to Speed; integration inspection and sync; Flint digest/question workflows;
and manual finance account/balance management, including archival.

### Surface matrix

| Feature                                                          | General REST               | Mobile adapter                | MCP                           | Boundary                        |
| ----------------------------------------------------------------- | --------------------------- | ------------------------------ | ------------------------------ | -------------------------------- |
| User data, insights, integrations, Flint and finance             | Yes, granular capabilities | Yes, `ios:read` / `ios:write` | Yes, granular capabilities    | Shared services where available |
| Entity edits, relationships and locations                        | Yes where listed           | Yes                           | Entity/relationship MCP tools | Owned resources only            |
| Device/APNs, HealthKit ingestion, Live Activities, OAuth handoff | No                         | Yes                           | No                            | iOS lifecycle transport only    |
| API-token administration                                         | No                         | Yes                           | No                            | Web settings and mobile only    |
| Browser HTML fetch with saved cookies                            | No                         | No                            | Yes, `web:fetch`              | MCP-only                        |
| Admin and task-pipeline operations                               | No                         | No                            | No                            | Internal/web administration     |

### MCP capability mapping

The MCP transport only authenticates the caller (`auth:sanctum` on
`/mcp/spark`). Each tool independently enforces its capability via the shared
`App\Support\SparkAbility` class, so a narrowly scoped integration token
cannot read events or metrics. Existing `mcp:read` tokens remain a read-only
compatibility alias while clients migrate.

| Capability          | MCP tools                                                                              |
| ------------------- | ---------------------------------------------------------------------------------------- |
| `data:read`         | `get-event-tool`, `get-object-tool`, `get-block-tool`, `get-events-by-filter-tool`, `search-events-tool`, `search-blocks-tool`, `search-objects-tool` |
| `insights:read`     | `get-day-summary-tool`, `get-day-context-tool`, `get-metric-trend-tool`, `get-baselines-tool`, `get-service-status-tool`, `get-check-ins`, `day-context-resource` |
| `integrations:read` | `list-integrations`                                                                       |
| `flint:read`        | `get-latest-flint-digest`                                                                |
| `insights:write`    | `acknowledge-anomaly-tool`                                                                |
| `integrations:sync` | `trigger-integration-update-tool`                                                        |
| `flint:write`       | `create-flint-digest`, `answer-flint-question`                                          |
| `data:write`        | `set-event-note`, `update-entity`, `manage-relationship` (read-only `list` operation needs `data:read` instead) |
| `web:fetch`         | `fetch-webpage-html` (MCP only)                                                          |

See [MCP.md](MCP.md) for full tool detail, including the actual registered
tool name for each class (several differ from the short names their own
docblocks use — verified against a live `tools/list` call, not guessed).

`PATCH /api/v1/events/{id}`, `/objects/{id}`, and `/blocks/{id}` provide the
same non-destructive edits as MCP's `update-entity`. Relationship list/create/
delete is available at the corresponding entity `/relationships` routes and
through `manage-relationship`. Both surfaces scope every entity through the
caller before loading it; cross-user identifiers are therefore treated as
missing. Deletion of events, objects, and blocks is intentionally not
exposed. Manual finance accounts may be archived with
`DELETE /api/v1/finance/accounts/{id}`.

### Transport parity and intentional boundaries

The REST API and MCP share the same ownership-scoped entity mutations and
Flint digest creation command. REST additionally exposes client-oriented tag,
bookmark, map/place, finance-account, check-in, and Up to Speed endpoints.
MCP additionally exposes semantic per-entity search and browser HTML
fetching. The mobile adapter additionally exposes day context, service
status, exact event filtering, typed event/object/block semantic or keyword
search, and explicit location operations. Service-wide integration sync is
available through both MCP and `POST /api/v1/integrations/sync`.

MCP resources enforce the same capability as their equivalent tools. MCP
browser fetching is intentionally MCP-only under `web:fetch`; it is never
available through REST or mobile because it can use saved browser cookies.

### Mobile API parity

The `/api/v1/mobile` adapter retains `ios:read` and `ios:write` scopes for
iOS-client compatibility. It includes the shared event/object/block edits,
relationship management, Flint digest creation, integration sync by instance
or service, and metric baseline discovery, alongside its existing user profile,
feed, widgets, notifications, check-ins, finance, map, delta-sync, and
personal API-token management APIs.

Device registration, APNs/Live Activities, HealthKit ingestion, and OAuth
handoff are deliberately mobile-only: they remain necessary for the iOS app
but are not general REST or MCP capabilities. API-token management is
available in web settings and on the mobile adapter, but not through the
general REST API or MCP. Browser HTML fetching stays MCP-only under
`web:fetch`.

## Standards this documentation follows

There is no OpenAPI-generation package in this app (no `scribe`,
`l5-swagger`, or `zircote/swagger-php` in `composer.json`), so the Markdown
references in this directory are hand-written directly from
`routes/*.php`, controllers, and MCP tool classes — not generated. The
`openapi/*.yaml` specs are likewise hand-written OpenAPI 3.1 documents,
kept as the machine-readable companion to the Markdown rather than a
separate source of truth: when an endpoint changes, update both in the same
pass. `openapi/mcp-tools.json` mirrors the JSON Schema every MCP tool
already carries as part of the protocol itself, captured in one file for
tooling that wants the whole surface without a live `tools/list` handshake.

## Related Documentation

- `CLAUDE.md` — Architecture and plugin system overview
- [SPOTLIGHT.md](../Architecture/SPOTLIGHT.md) — Command palette documentation
- [ADR 0011](../Architecture/ADRs/0011-api-surfaces-use-sanctum-and-ownership-scoping.md) — API ownership boundary
- [ADR 0012](../Architecture/ADRs/0012-versioned-mobile-api-with-capability-scoped-tokens.md) — Mobile API capability-scoped tokens
