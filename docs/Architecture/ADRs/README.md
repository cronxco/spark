# Architecture Decision Records

These records separate observed implementation from future design. Current-state records are reconstructed, so they do not assert historical intent, adequacy, or a complete guarantee.

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

## Future proposals

| ADR | Record |
| --- | --- |
| [0004](0004-proposed-tenant-integrity-hardening.md) | Tenant-integrity hardening |
| [0018](0018-proposed-credential-security-hardening.md) | Credential-security hardening |

## Open-decision register

- **Privacy risk:** current tenant integrity is application-enforced and incomplete at the relational layer; an event can reference another tenant's object.
- **Security risk:** credential encryption-at-rest, rotation, access audit, and ownership policy are not evidenced.
- Define retention/deletion SLA, backup/restore RPO/RTO, API deprecation, extension/migration compatibility, queue retry/replay/DLQ, and web/mobile/MCP contract ownership.
- Define a tenancy authorization test matrix, migration/data-audit approach, and review cadence for these reconstructed records.
- Flint architecture is not accepted here; its boundary remains an evidence gap pending owner decision.
