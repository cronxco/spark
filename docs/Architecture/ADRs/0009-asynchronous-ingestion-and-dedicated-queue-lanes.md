# ADR 0009: Asynchronous Ingestion and Dedicated Queue Lanes

**Status: Accepted**

> **Note**: Implementation evidence is retained; the Product Owner decision below establishes the current policy.

## Context
Redis/Horizon queues separate pulls, migrations, embeddings, tasks, and Flint jobs.

## Decision
Spark runs ingestion and derived work asynchronously through named lanes and adopts at-least-once delivery with controlled recovery: bounded automatic retries, idempotent handlers, monitored failure/dead-letter storage, and authorised, auditable manual replay.

## Consequences
HTTP work is decoupled from long-running jobs. This is explicitly not exactly-once processing; retry limits, failure monitoring, replay authority, and idempotency are required operational controls.

## Alternatives rejected
Best-effort dropping and unbounded automatic retry were rejected.

## Related repository paths
`config/queue.php`, `config/horizon.php`, `app/Jobs/Base/`, `app/Jobs/Concerns/EnhancedIdempotency.php`, `docs/Architecture/JOBS.md`.

## Evidence gaps / open questions
Define concrete retry limits, failure/dead-letter implementation, accountable operators, and alerts.
