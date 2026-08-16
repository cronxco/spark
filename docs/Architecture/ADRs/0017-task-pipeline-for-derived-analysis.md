# ADR 0017: Task Pipeline for Derived Analysis

**Status: Accepted (reconstructed current state)**

> **Note**: This ADR records observed implementation. It is not evidence that the design was intentionally chosen or is sufficient.

## Context
A task-pipeline provider and queued jobs execute registry-defined, dependency-aware derived analysis.

## Decision
The current system uses a task pipeline for derived work.

## Consequences
Task behavior inherits queue failure semantics; ownership, dependency failure, and replay policy remain open.

## Alternatives rejected
No explicit historical rejection is evidenced.

## Related repository paths
`app/Providers/TaskPipelineServiceProvider.php`, `app/Jobs/TaskPipeline/`, `docs/Architecture/TASK_PIPELINE.md`.

## Evidence gaps / open questions
Define task status guarantees and replay authority.
