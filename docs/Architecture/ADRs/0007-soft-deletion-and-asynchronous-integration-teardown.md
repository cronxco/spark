# ADR 0007: Soft Deletion and Asynchronous Integration Teardown

**Status: Accepted**

> **Note**: Implementation evidence is retained; the Product Owner decision below establishes the current policy.

## Context
Core models soft-delete; integration-group teardown uses queued jobs.

## Decision
Integration removal removes the integration from normal views and stops normal processing, while retaining its data indefinitely for restoration; there is no automatic erasure window. A full Spark account-deletion request revokes access immediately and permanently erases user-owned primary, derived, media, and queued data through the deletion workflow.

## Consequences
Database cascades do not govern soft deletion. Some controllers delete Blocks before Events. Backup expiry and the externally stated completion window for account erasure remain operational policy to set before making a precise user-facing timeline promise.

## Alternatives rejected
Automatic integration-data erasure and indefinite account archiving were rejected. Immediate irreversible integration deletion was not selected.

## Related repository paths
`docs/Architecture/SOFT_DELETES.md`, `app/Jobs/IntegrationGroup/`, `app/Jobs/DeleteIntegrationGroupJob.php`, `app/Http/Controllers/EventApiController.php`.

## Evidence gaps / open questions
Define account-erasure backup expiry, completion window, cascade ordering, and erasure verification before publishing a precise user-facing promise.
