# ADR 0006: Unified EventObject, Event, and Block Model

**Status: Accepted**

> **Note**: Implementation evidence is retained; the Product Owner decision below establishes the current policy.

## Context
EventObjects are user-scoped entities, Events are timestamped facts, and Blocks are derived display records.

## Decision
`EventObject` → `Event` → `Block` is Spark's canonical model. New ingestion and product features must read and write it. Legacy paths are read-only compatibility adapters pending audited migration/removal; every transition needs validation and rollback or forward-fix criteria.

## Consequences
Object identity is user-scoped. `EventObject::integration()` has no backing column, and `Event::source()` declares a misleading object relation over text `source_id`; neither is an accepted schema contract. Permanent dual-model support and provider-native primary storage were rejected.

## Alternatives rejected
Permanent dual-model support and provider-native storage as the primary model were rejected.

## Related repository paths
`CLAUDE.md`, `docs/Architecture/OBJECTS.md`, `docs/Architecture/EVENTS.md`, `docs/Architecture/BLOCKS.md`, core create migrations, `app/Models/EventObject.php`, `app/Models/Event.php`.

## Evidence gaps / open questions
Correct code/documentation drift, define integrity controls through ADR 0004 if reconsidered, and plan audited legacy migrations.
