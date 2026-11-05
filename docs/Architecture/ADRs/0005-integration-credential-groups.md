# ADR 0005: Integration Credential Groups

**Status: Accepted**

> **Note**: Implementation evidence is retained; the Product Owner decision below establishes the current policy.

## Context
Users own integration groups, and groups hold credentials shared by integration instances.

## Decision
Spark separates user-owned provider credentials from individual integrations. OAuth access tokens, refresh tokens, and webhook secrets are to use application-layer encryption at the existing Laravel `APP_KEY` boundary, as defined by ADR 0018.

## Consequences
Credential reuse is possible across instances. `APP_KEY` compromise also affects credentials; rotation, automatic revocation, lifecycle automation, and access audit remain explicitly deferred Product Owner-managed risks.

## Alternatives rejected
Dedicated credential keys and external key management were rejected for now in favour of the existing application-key boundary. Automatic credential lifecycle controls were deferred.

## Related repository paths
`database/migrations/2025_07_27_142700_create_integration_groups_table.php`, `database/migrations/2025_07_27_142753_create_integrations_table.php`, `app/Models/IntegrationGroup.php`, `app/Models/Integration.php`.

## Evidence gaps / open questions
See [ADR 0018](0018-proposed-credential-security-hardening.md) for implementation, migration, and incident-response gaps.
