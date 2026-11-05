# ADR 0002: PostgreSQL Extensions and UUID Identifiers

**Status: Accepted**

> **Note**: Implementation evidence is retained; the Product Owner decision below establishes the current policy.

## Context
Spark configures PostgreSQL, `pgcrypto` UUIDs, and JSONB metadata; PostGIS and pgvector have dedicated records.

## Decision
Spark uses PostgreSQL with database-generated UUIDs and JSONB, and adopts a managed extension baseline. Every extension-related change requires an approved extension/version inventory, compatibility and migration preflight, backup/restore validation, and a tested rollback or forward-fix path.

## Consequences
Extensions are deployment prerequisites. Extension `down()` safety is limited: some migrations remove a database-wide extension. The managed baseline makes extension ownership and recovery an operational responsibility rather than an implicit platform assumption.

## Alternatives rejected
Platform-managed-only extension lifecycle and minimising all extension use were rejected because they do not provide adequate compatibility or recovery assurance for existing PostGIS and pgvector dependencies.

## Related repository paths
`docker-compose.yml`, `config/database.php`, `database/migrations/2025_07_27_141000_enable_pgcrypto_extension.php`, core create migrations.

## Evidence gaps / open questions
Set the concrete approved inventory, accountable owner, and preflight/runbook implementation before the next extension change.
