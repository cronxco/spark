# ADR 0006: Unified EventObject, Event, and Block Model

**Status: Accepted (reconstructed current state)**

> **Note**: This ADR records observed implementation. It is not evidence that the design was intentionally chosen or is sufficient.

## Context
EventObjects are user-scoped entities, Events are timestamped facts, and Blocks are derived display records.

## Decision
The current data model uses EventObject → Event → Block, with actor/target references and text external `source_id` values.

## Consequences
Object identity is user-scoped. `EventObject::integration()` has no backing column, and `Event::source()` declares a misleading object relation over text `source_id`; neither is an accepted schema contract.

## Alternatives rejected
No explicit historical rejection is evidenced.

## Related repository paths
`CLAUDE.md`, `docs/Architecture/OBJECTS.md`, `docs/Architecture/EVENTS.md`, `docs/Architecture/BLOCKS.md`, core create migrations, `app/Models/EventObject.php`, `app/Models/Event.php`.

## Evidence gaps / open questions
Correct code/documentation drift and define integrity controls through ADR 0004.
