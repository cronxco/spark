# ADR 0013: PostGIS Location and Place Resolution

**Status: Accepted**

> **Note**: Implementation evidence is retained; the Product Owner decision below establishes the current policy.

## Context
PostGIS geography `POINT,4326` and GiST indexes support EventObject/Event locations and place resolution.

## Decision
Spark uses PostGIS geography points for location-aware data. Precise location is an indefinite, owner-only personal-history asset: exact coordinates are retained and may be displayed or exported at exact precision for the owning user. They are unavailable to other users and are erased only as part of full account deletion under ADR 0007; there is no automatic source- or integration-retention change.

## Consequences
Spatial lookup is indexed. Exact location carries heightened sensitivity and depends on the deny-by-default ownership policy in ADR 0011. Extension rollback is database-wide and unsafe guarantees are not established.

## Alternatives rejected
Automatic coarsening, coarse-only persistence, and source-aligned deletion were rejected.

## Related repository paths
`database/migrations/2025_12_25_120651_enable_postgis_extension.php`, `database/migrations/2025_12_25_120706_add_location_to_events_table.php`, `database/migrations/2025_12_25_120729_add_location_to_event_objects_table.php`, `app/Models/Place.php`, `docs/Architecture/PLACES.md`.

## Evidence gaps / open questions
Define access/export enforcement and extension rollback operations; retention is indefinite except full account deletion.
