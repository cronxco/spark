# ADR 0008: Plugin-Based Integration Boundary

**Status: Accepted**

> **Note**: Implementation evidence is retained; the Product Owner decision below establishes the current policy.

## Context
External services are registered through a plugin registry and typed contracts.

## Decision
Integration plugins are an internal implementation boundary. Spark makes no long-lived compatibility or deprecation promise for plugin/configuration contracts beyond a deployed release.

## Consequences
Registration and contracts centralize provider behavior. The absence of a long-lived contract promise does not waive ordinary safe deployment, rollback, or user-data protection.

## Alternatives rejected
Versioned managed contracts and core-only stability were rejected for the current internal boundary.

## Related repository paths
`app/Integrations/PluginRegistry.php`, `app/Providers/IntegrationServiceProvider.php`, `app/Integrations/Contracts/`, `docs/Architecture/INTEGRATION_PLUGINS.md`.

## Evidence gaps / open questions
Reconsider compatibility governance only if the boundary becomes externally consumed or its operational needs change.
