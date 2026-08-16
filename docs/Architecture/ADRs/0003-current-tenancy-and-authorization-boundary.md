# ADR 0003: Current Tenancy and Authorization Boundary

**Status: Accepted (reconstructed current state)**

> **Warning**: This ADR records observed implementation. It is not evidence that the design was intentionally chosen or is sufficient. Tenant integrity is application-enforced and incomplete at the relational layer.

## Context
Integrations carry `user_id`; Events derive ownership through integrations and `scopeForUser`/ownership queries. Event actor/target references and polymorphic relationships have no same-tenant database constraint.

## Decision
The current boundary is application-level authorization and ownership scoping, rather than database-enforced tenant integrity.

## Consequences
An event for tenant A can reference an object for tenant B if application validation fails. This is a material privacy risk. Tests and observability must detect authorization defects until a future decision changes the design.

## Alternatives rejected
No explicit historical rejection is evidenced.

## Related repository paths
`database/migrations/2025_07_27_142753_create_integrations_table.php`, `database/migrations/2025_07_27_143050_create_objects_table.php`, `database/migrations/2025_07_27_143829_create_events_table.php`, `database/migrations/2025_11_09_163057_create_relationships_table.php`, `app/Models/Event.php`, `app/Http/Controllers/EventApiController.php`, `app/Traits/AuthorizesOwnership.php`.

## Evidence gaps / open questions
See [ADR 0004](0004-proposed-tenant-integrity-hardening.md); define test coverage and historical-data audit.
