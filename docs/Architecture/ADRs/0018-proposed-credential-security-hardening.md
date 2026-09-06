# ADR 0018: Proposed Credential-Security Hardening

**Status: Accepted — partially implemented**

## Context
[ADR 0005](0005-integration-credential-groups.md) observes stored credential groups but does not establish encryption-at-rest, rotation, access audit, or recovery controls. This creates a security and privacy risk.

## Decision
OAuth access tokens, refresh tokens, and webhook secrets will use application-layer encryption at the existing Laravel `APP_KEY` boundary. Rotation, automatic revocation, credential-lifecycle automation, and access audit are deferred as explicit Product Owner-managed risks. Until later controls are approved, compromise remediation is manual.

## Implementation status
Phase 1 is implemented: `IntegrationGroup` casts `access_token`, `refresh_token` and `webhook_secret` as `encrypted`, and `integrations:encrypt-credentials` backfills existing plaintext rows. No migration was required — all three are `text` columns and appear in no `where()` clause, so ciphertext length and lookups are both unaffected. Credentials are excluded from the activity log (`logExcept()`), which reads attributes through their casts and would otherwise have recorded the decrypted values.

Phase 2 is outstanding. `IntegrationGroup::auth_metadata` and `Integration::configuration` also carry provider secrets (Immich and Goodreads API keys, Hevy's key) but are `jsonb` columns read and written through SQL JSON paths — `where('auth_metadata->gocardless_reference', …)` and roughly a dozen `configuration->…` updates. Whole-column encryption would write a non-JSON string into a `jsonb` column and break those queries, so it needs either a column-type migration or a cast that encrypts only known secret leaf values while keeping the JSON structurally valid. Neither is chosen yet.

Also outstanding: existing `activity_log` rows written before `logExcept()` still contain plaintext credentials, and need a retention decision.

## Consequences
Implementation must still define data audit, migration, rollback, observability, testing, accountable ownership, secret redaction, and backup exposure. `APP_KEY` compromise affects both general application encryption and credentials.

## Alternatives rejected
Dedicated credential-encryption keys and external key management were rejected for now; automatic lifecycle controls were deferred.

## Related repository paths
See [ADR 0005](0005-integration-credential-groups.md).

## Evidence gaps / open questions
Define threat model, migration authority, backup handling, and manual incident response before implementation.
