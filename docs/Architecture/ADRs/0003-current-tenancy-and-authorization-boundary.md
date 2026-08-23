# ADR 0003: Current Tenancy and Authorization Boundary

**Status: Accepted**

> **Warning**: Implementation evidence is retained; the Product Owner decision below establishes the current policy. Tenant integrity remains application-enforced and incomplete at the relational layer.

## Context
Integrations carry `user_id`; Events derive ownership through integrations and `scopeForUser`/ownership queries. Event actor/target references and polymorphic relationships have no same-tenant database constraint.

## Decision
Spark retains an application-layer tenancy boundary rather than undertaking retrospective database-integrity hardening. The authorised remediation is limited to `POST /api/events`; the broader authenticated API policy is recorded in ADR 0011. User-owned records must not cross users except through a future explicit sharing feature, but the current relational model does not prove that invariant.

## Consequences
An event for tenant A can reference an object for tenant B if application validation fails. This is an accepted, Product Owner-managed residual privacy risk outside the scoped remediation. Application validation, negative authorization tests, and observability are the control boundary until a later decision authorises broader hardening.

## Alternatives rejected
Retrospective database constraints, triggers, audit/backfill, and automatic repair were deferred to avoid major retrospective change. Controlled cross-user sharing was not selected.

## Related repository paths
`database/migrations/2025_07_27_142753_create_integrations_table.php`, `database/migrations/2025_07_27_143050_create_objects_table.php`, `database/migrations/2025_07_27_143829_create_events_table.php`, `database/migrations/2025_11_09_163057_create_relationships_table.php`, `app/Models/Event.php`, `app/Http/Controllers/EventApiController.php`, `app/Traits/AuthorizesOwnership.php`.

## Evidence gaps / open questions
See [ADR 0004](0004-proposed-tenant-integrity-hardening.md). Define test coverage and historical-data audit if broader hardening is reconsidered.
