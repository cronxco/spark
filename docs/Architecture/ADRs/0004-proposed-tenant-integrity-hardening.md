# ADR 0004: Proposed Tenant-Integrity Hardening

**Status: Deferred**

## Context
[ADR 0003](0003-current-tenancy-and-authorization-boundary.md) documents an incomplete application-enforced boundary and material privacy risk.

## Decision
Product Owner deferred retrospective tenant-integrity hardening. Spark will retain application-layer enforcement, with only the separately authorised `POST /api/events` remediation in scope. No database constraint, trigger, historical audit/backfill, automatic repair, or cross-tenant exception model is selected.

## Consequences
The known relational integrity risk remains. A later hardening proposal must cover historical-data audit/backfill, constraints versus triggers versus service validation, phased rollout, rollback, violation observability, authorization tests, accountable ownership, key lifecycle dependencies, and backup exposure.

## Alternatives rejected
Database constraints, triggers, and broad service-layer validation were deferred; no cross-user sharing model was approved.

## Related repository paths
See ADR 0003.

## Evidence gaps / open questions
Choose migration strategy, data-repair authority, and any valid cross-tenant exception model before implementation.
