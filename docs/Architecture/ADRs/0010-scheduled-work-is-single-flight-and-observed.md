# ADR 0010: Scheduled Work Is Single-Flight and Observed

**Status: Accepted (reconstructed current state)**

> **Note**: This ADR records observed implementation. It is not evidence that the design was intentionally chosen or is sufficient.

## Context
Scheduled work uses `onOneServer()` and `withoutOverlapping()`; Horizon snapshots are conditionally scheduled.

## Decision
The current scheduler uses single-server and overlap guards.

## Consequences
Shared scheduling coordination is required. Missed-run handling and scheduler SLOs remain open.

## Alternatives rejected
No explicit historical rejection is evidenced.

## Related repository paths
`routes/console.php`, `docs/Architecture/SCHEDULED_INTEGRATION_UPDATES.md`.

## Evidence gaps / open questions
Define recovery and alert thresholds.
