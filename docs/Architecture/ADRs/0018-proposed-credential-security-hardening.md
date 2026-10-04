# ADR 0018: Proposed Credential-Security Hardening

**Status: Accepted — implemented**

## Context

[ADR 0005](0005-integration-credential-groups.md) observes stored credential groups but does not establish encryption-at-rest, rotation, access audit, or recovery controls. This creates a security and privacy risk.

## Decision

OAuth access tokens, refresh tokens, and webhook secrets will use application-layer encryption at the existing Laravel `APP_KEY` boundary. Rotation, automatic revocation, credential-lifecycle automation, and access audit are deferred as explicit Product Owner-managed risks. Until later controls are approved, compromise remediation is manual.

## Implementation status

Phase 1 is implemented: `IntegrationGroup` casts `access_token`, `refresh_token` and `webhook_secret` as `encrypted`, and `integrations:encrypt-credentials` backfills existing plaintext rows. No migration was required — all three are `text` columns and appear in no `where()` clause, so ciphertext length and lookups are both unaffected. Credentials are excluded from the activity log (`logExcept()`), which reads attributes through their casts and would otherwise have recorded the decrypted values.

Phase 2 is implemented. `IntegrationGroup::auth_metadata` and `Integration::configuration` also carry provider secrets (Immich and Goodreads API keys, Hevy's key, and Fetch's per-domain session cookies) but are `jsonb` columns read and written through SQL JSON paths — `where('auth_metadata->gocardless_reference', …)` and ten `configuration->migration_*` updates. Whole-column encryption would write a non-JSON string into a `jsonb` column and break those queries.

The **leaf-value cast** was chosen over a column-type migration, per the standing instruction to avoid migrations where a viable alternative exists. `App\Casts\EncryptedJsonSecrets` encrypts only the secret leaves and leaves the JSON structurally valid, so all eleven JSON paths keep working unchanged. Because a cast is transparent, no call site changed: the plugins' array reads are untouched.

Two rules, both narrower than `sensitive_log_keys()` — that list is tuned for logging, where over-redaction is free, and includes keys such as `key`, `auth` and `server_url` that carry ordinary configuration here:

- **Secret leaf keys**, encrypted individually: `access_token`, `api_key`, `api_token`, `client_secret`, `password`, `refresh_token`, `secret`, `token`, `webhook_secret`.
- **Secret subtrees**, where every leaf is encrypted whatever its own key is called: `cookies`. Fetch stores session cookies at `auth_metadata.domains.{domain}.cookies` as arbitrary name/value pairs, which name matching alone cannot reach.

Reads fall back to the raw value when decryption fails, so rows the backfill has not reached yet still read correctly. There is therefore no flag day and no ordering requirement between deploying the cast and running `integrations:encrypt-credentials`, which now covers both the `text` columns and the `jsonb` leaves.

The `activity_log` rows written before redaction was in place are handled separately: `App\Traits\RedactsLoggedProperties` keeps new credentials out of the changelog while preserving the rest of each diff, and `activity-log:redact-credentials` rewrites the secret leaves in rows already written. The Product Owner chose redaction over deletion so the audit trail survives.

## Consequences

Implementation must still define data audit, migration, rollback, observability, testing, accountable ownership, secret redaction, and backup exposure. `APP_KEY` compromise affects both general application encryption and credentials.

## Alternatives rejected

Dedicated credential-encryption keys and external key management were rejected for now; automatic lifecycle controls were deferred.

## Related repository paths

See [ADR 0005](0005-integration-credential-groups.md).

## Evidence gaps / open questions

Define threat model, migration authority, backup handling, and manual incident response before implementation.
