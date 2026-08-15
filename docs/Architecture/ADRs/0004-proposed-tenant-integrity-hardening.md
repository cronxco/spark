# ADR 0004: Proposed Tenant-Integrity Hardening

**Status: Proposed / owner decision required**

## Context
[ADR 0003](0003-current-tenancy-and-authorization-boundary.md) documents an incomplete application-enforced boundary and material privacy risk.

## Decision
An owner decision is required before selecting controls that guarantee actor/target and polymorphic relationship tenant consistency. No implementation approach is selected.

## Consequences
The selected approach must cover historical-data audit/backfill, constraints versus triggers versus service validation, phased rollout, rollback, violation observability, authorization test matrix, accountable ownership, credential revocation implications, key lifecycle dependencies, and backup exposure.

## Alternatives rejected
None. Alternatives require owner review.

## Related repository paths
See ADR 0003.

## Evidence gaps / open questions
Choose privacy scope, valid cross-tenant exception model, migration strategy, and data-repair authority before implementation.
