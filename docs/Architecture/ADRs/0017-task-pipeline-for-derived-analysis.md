# ADR 0017: Task Pipeline for Derived Analysis

**Status: Accepted**

> **Note**: Implementation evidence is retained; the Product Owner decision below establishes the current policy.

## Context
A task-pipeline provider and queued jobs execute registry-defined, dependency-aware derived analysis.

## Decision
Spark uses an explicit task-pipeline dependency state machine. Failed or cancelled prerequisites block downstream work; `failed`, `blocked`, `skipped`, and `succeeded` are distinct states; retries/restarts are auditable and may unblock dependents only after the prerequisite succeeds.

## Consequences
Task behavior inherits ADR 0009 queue recovery policy. State transitions, replay authority, and ownership must preserve the selected dependency semantics.

## Alternatives rejected
Independent best-effort downstream execution and pipeline fail-fast cancellation were rejected.

## Related repository paths
`app/Providers/TaskPipelineServiceProvider.php`, `app/Jobs/TaskPipeline/`, `docs/Architecture/TASK_PIPELINE.md`.

## Evidence gaps / open questions
Define state-transition implementation, audit evidence, and authorised replay ownership.
