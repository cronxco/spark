# ADR-0001: POST events tenancy enforcement

- Status: Accepted
- Date: 2026-08-15

## Context

`POST /api/events` creates user-scoped EventObjects, an integration-scoped
Event, and event-scoped Blocks in one request. The request previously supplied
the ownership and linkage fields directly.

## Decision

Resolve `event.integration_id` through the authenticated user's integrations
before the create transaction. Within the transaction, derive EventObject
`user_id`, Event actor/target IDs, and Block `event_id` server-side. Keep
actor, target, and block `integration_id` input non-authoritative and ignored
for compatibility.

## Consequences

Foreign, unknown, and soft-deleted integration IDs return an opaque `404`; no
records are created. Missing or malformed payloads continue to return `422`,
and unauthenticated requests continue to return `401`. Existing clients may
still send ignored integration fields, but cannot use them to establish
ownership.

## Alternatives rejected

- Database ownership constraints or a migration: broader tenancy work and out
  of scope for this targeted application-layer remediation.
- Model hooks or global policies: would affect ingestion pipelines beyond this
  endpoint.

## Related paths

- `app/Http/Controllers/EventApiController.php`
- `tests/Feature/EventApiTest.php`
- `docs/API/API_v1.md`
