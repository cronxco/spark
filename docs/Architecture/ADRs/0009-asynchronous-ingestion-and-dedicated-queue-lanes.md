# ADR 0009: Asynchronous Ingestion and Dedicated Queue Lanes

**Status: Accepted (reconstructed current state)**

> **Note**: This ADR records observed implementation. It is not evidence that the design was intentionally chosen or is sufficient.

## Context
Redis/Horizon queues separate pulls, migrations, embeddings, tasks, and Flint jobs.

## Decision
The current application runs ingestion and derived work asynchronously through named lanes.

## Consequences
HTTP work is decoupled from long-running jobs. Replay, dead-letter, saturation, and universal idempotency policy are not evidenced.

## Alternatives rejected
No explicit historical rejection is evidenced.

## Related repository paths
`config/queue.php`, `config/horizon.php`, `app/Jobs/Base/`, `app/Jobs/Concerns/EnhancedIdempotency.php`, `docs/Architecture/JOBS.md`.

## Evidence gaps / open questions
Define retry/replay/DLQ ownership and alerts.
