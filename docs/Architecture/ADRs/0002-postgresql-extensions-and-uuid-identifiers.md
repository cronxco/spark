# ADR 0002: PostgreSQL Extensions and UUID Identifiers

**Status: Accepted (reconstructed current state)**

> **Note**: This ADR records observed implementation. It is not evidence that the design was intentionally chosen or is sufficient.

## Context
Spark configures PostgreSQL, `pgcrypto` UUIDs, and JSONB metadata; PostGIS and pgvector have dedicated records.

## Decision
The current primary store is PostgreSQL with database-generated UUIDs and JSONB.

## Consequences
Extensions are deployment prerequisites. Extension `down()` safety is limited: some migrations remove a database-wide extension.

## Alternatives rejected
No explicit historical rejection is evidenced.

## Related repository paths
`docker-compose.yml`, `config/database.php`, `database/migrations/2025_07_27_141000_enable_pgcrypto_extension.php`, core create migrations.

## Evidence gaps / open questions
Define extension ownership, compatibility, backup, and rollback policy.
