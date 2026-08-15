# ADR 0013: PostGIS Location and Place Resolution

**Status: Accepted (reconstructed current state)**

> **Note**: This ADR records observed implementation. It is not evidence that the design was intentionally chosen or is sufficient.

## Context
PostGIS geography `POINT,4326` and GiST indexes support EventObject/Event locations and place resolution.

## Decision
The current application uses PostGIS geography points for location-aware data.

## Consequences
Spatial lookup is indexed. Extension rollback is database-wide and unsafe guarantees are not established.

## Alternatives rejected
No explicit historical rejection is evidenced.

## Related repository paths
`database/migrations/2025_12_25_120651_enable_postgis_extension.php`, `database/migrations/2025_12_25_120706_add_location_to_events_table.php`, `database/migrations/2025_12_25_120729_add_location_to_event_objects_table.php`, `app/Models/Place.php`, `docs/Architecture/PLACES.md`.

## Evidence gaps / open questions
Define precision, retention, and rollback policy.
