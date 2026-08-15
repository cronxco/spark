# ADR 0018: Proposed Credential-Security Hardening

**Status: Proposed / owner decision required**

## Context
[ADR 0005](0005-integration-credential-groups.md) observes stored credential groups but does not establish encryption-at-rest, rotation, access audit, or recovery controls. This creates a security and privacy risk.

## Decision
An owner decision is required to select credential-security controls. No encryption, rotation, migration, or other implementation approach is selected.

## Consequences
The future decision must define data audit, migration, rollback, observability, testing, accountable ownership, credential revocation, key lifecycle, secret redaction, and backup exposure.

## Alternatives rejected
None. Alternatives require owner review.

## Related repository paths
See [ADR 0005](0005-integration-credential-groups.md).

## Evidence gaps / open questions
Choose scope, threat model, key ownership, retention, migration authority, and incident response before implementation.
