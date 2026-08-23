# ADR 0018: Proposed Credential-Security Hardening

**Status: Accepted (implementation deferred)**

## Context
[ADR 0005](0005-integration-credential-groups.md) observes stored credential groups but does not establish encryption-at-rest, rotation, access audit, or recovery controls. This creates a security and privacy risk.

## Decision
OAuth access tokens, refresh tokens, and webhook secrets will use application-layer encryption at the existing Laravel `APP_KEY` boundary. Rotation, automatic revocation, credential-lifecycle automation, and access audit are deferred as explicit Product Owner-managed risks. Until later controls are approved, compromise remediation is manual.

## Consequences
Implementation must still define data audit, migration, rollback, observability, testing, accountable ownership, secret redaction, and backup exposure. `APP_KEY` compromise affects both general application encryption and credentials.

## Alternatives rejected
Dedicated credential-encryption keys and external key management were rejected for now; automatic lifecycle controls were deferred.

## Related repository paths
See [ADR 0005](0005-integration-credential-groups.md).

## Evidence gaps / open questions
Define threat model, migration authority, backup handling, and manual incident response before implementation.
