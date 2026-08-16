# ADR 0008: Plugin-Based Integration Boundary

**Status: Accepted (reconstructed current state)**

> **Note**: This ADR records observed implementation. It is not evidence that the design was intentionally chosen or is sufficient.

## Context
External services are registered through a plugin registry and typed contracts.

## Decision
The current integration boundary is plugin-based rather than route-specific.

## Consequences
Registration and contracts centralize provider behavior; plugin lifecycle and secret controls are not evidenced.

## Alternatives rejected
No explicit historical rejection is evidenced.

## Related repository paths
`app/Integrations/PluginRegistry.php`, `app/Providers/IntegrationServiceProvider.php`, `app/Integrations/Contracts/`, `docs/Architecture/INTEGRATION_PLUGINS.md`.

## Evidence gaps / open questions
Define contract versioning and deprecation.
