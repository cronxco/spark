# ADR 0007: Soft Deletion and Asynchronous Integration Teardown

**Status: Accepted (reconstructed current state)**

> **Note**: This ADR records observed implementation. It is not evidence that the design was intentionally chosen or is sufficient.

## Context
Core models soft-delete; integration-group teardown uses queued jobs.

## Decision
The current lifecycle uses recoverable deletion with staged teardown.

## Consequences
Database cascades do not govern soft deletion. Some controllers delete Blocks before Events; restoration, hard-delete order, and retention remain application policy gaps.

## Alternatives rejected
No explicit historical rejection is evidenced.

## Related repository paths
`docs/Architecture/SOFT_DELETES.md`, `app/Jobs/IntegrationGroup/`, `app/Jobs/DeleteIntegrationGroupJob.php`, `app/Http/Controllers/EventApiController.php`.

## Evidence gaps / open questions
Define retention SLA, restoration, cascade, and erasure verification.
