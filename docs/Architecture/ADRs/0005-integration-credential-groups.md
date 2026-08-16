# ADR 0005: Integration Credential Groups

**Status: Accepted (reconstructed current state)**

> **Note**: This ADR records observed implementation. It is not evidence that the design was intentionally chosen or is sufficient.

## Context
Users own integration groups, and groups hold credentials shared by integration instances.

## Decision
The current model separates user-owned provider credentials from individual integrations.

## Consequences
Credential reuse is possible across instances. Encryption-at-rest, rotation, access audit, and recovery controls are not established by this record.

## Alternatives rejected
No explicit historical rejection is evidenced.

## Related repository paths
`database/migrations/2025_07_27_142700_create_integration_groups_table.php`, `database/migrations/2025_07_27_142753_create_integrations_table.php`, `app/Models/IntegrationGroup.php`, `app/Models/Integration.php`.

## Evidence gaps / open questions
See [ADR 0018](0018-proposed-credential-security-hardening.md).
