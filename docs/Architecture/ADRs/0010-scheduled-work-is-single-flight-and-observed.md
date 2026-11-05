# ADR 0010: Scheduled Work Is Single-Flight and Observed

**Status: Accepted**

> **Note**: Implementation evidence is retained; the Product Owner decision below establishes the current policy.

## Context
Scheduled work uses `onOneServer()` and `withoutOverlapping()`; Horizon snapshots are conditionally scheduled.

## Decision
Scheduled work uses single-server and overlap guards, and Spark performs bounded catch-up for missed scheduled work up to 24 hours. Older missed runs are not replayed automatically and must be surfaced for authorised manual replay.

## Consequences
Shared scheduling coordination is required. Catch-up relies on idempotency and must be recorded; scheduler freshness and alert thresholds remain to be defined under ADR 0016.

## Alternatives rejected
Skipping all missed work and full historical automatic replay were rejected.

## Related repository paths
`routes/console.php`, `docs/Architecture/SCHEDULED_INTEGRATION_UPDATES.md`.

## Evidence gaps / open questions
Define catch-up implementation, manual replay process, and alert thresholds.
