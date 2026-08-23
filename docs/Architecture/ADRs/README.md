# Architecture Decision Records

These records retain the implementation evidence that informed each decision. Product Owner decisions recorded in the ADRs establish the current policy; accepted implementation is not implied where an ADR explicitly defers it.

## Current state

| ADR | Record |
| --- | --- |
| [0001](0001-laravel-modular-monolith.md) | Laravel modular monolith |
| [0002](0002-postgresql-extensions-and-uuid-identifiers.md) | PostgreSQL, extensions, UUIDs |
| [0003](0003-current-tenancy-and-authorization-boundary.md) | Current tenancy and authorization boundary |
| [0005](0005-integration-credential-groups.md) | Integration credential groups |
| [0006](0006-unified-event-object-block-model.md) | EventObject, Event, Block model |
| [0007](0007-soft-deletion-and-asynchronous-integration-teardown.md) | Deletion and teardown |
| [0008](0008-plugin-based-integration-boundary.md) | Plugin integration boundary |
| [0009](0009-asynchronous-ingestion-and-dedicated-queue-lanes.md) | Queue lanes |
| [0010](0010-scheduled-work-is-single-flight-and-observed.md) | Scheduled work |
| [0011](0011-api-surfaces-use-sanctum-and-ownership-scoping.md) | API ownership boundary |
| [0012](0012-versioned-mobile-api-with-capability-scoped-tokens.md) | Mobile API |
| [0013](0013-postgis-location-and-place-resolution.md) | PostGIS location |
| [0014](0014-pgvector-semantic-search.md) | pgvector search |
| [0015](0015-media-library-and-content-addressed-deduplication.md) | Media deduplication |
| [0016](0016-observability-with-sentry-horizon-and-structured-logs.md) | Observability |
| [0017](0017-task-pipeline-for-derived-analysis.md) | Derived task pipeline |
| [0018](0018-proposed-credential-security-hardening.md) | Credential-security hardening (implementation deferred) |

## Deferred hardening

| ADR | Record |
| --- | --- |
| [0004](0004-proposed-tenant-integrity-hardening.md) | Tenant-integrity hardening |

## Open-decision register

- **Privacy risk:** current tenant integrity is application-enforced and incomplete at the relational layer; an event can reference another tenant's object.
- **Security risk:** credential encryption is accepted at the `APP_KEY` boundary; rotation, automated revocation/lifecycle controls, and access audit remain deferred Product Owner-managed risks.
- Define retention/deletion SLA, backup/restore RPO/RTO, API deprecation, extension/migration compatibility, queue retry/replay/DLQ, and web/mobile/MCP contract ownership.
- Define a tenancy authorization test matrix, migration/data-audit approach, and review cadence for these reconstructed records.
- Flint architecture is not accepted here; its boundary remains an evidence gap pending owner decision.
