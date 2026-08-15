# ADR 0012: Versioned Mobile API With Capability-Scoped Tokens

**Status: Accepted (reconstructed current state)**

> **Note**: This ADR records observed implementation. It is not evidence that the design was intentionally chosen or is sufficient.

## Context
Mobile routes mount at `/api/v1/mobile` behind a feature gate, Sanctum abilities, ETag middleware, and scoped broadcast channel.

## Decision
The current mobile API is versioned and capability-scoped.

## Consequences
Clients use a named version and conditional reads. Compatibility window and cross-surface ownership are open.

## Alternatives rejected
No explicit historical rejection is evidenced.

## Related repository paths
`routes/api.php`, `routes/mobile.php`, `routes/channels.php`, `bootstrap/app.php`, `docs/mobile/MOBILE_API.md`.

## Evidence gaps / open questions
Define deprecation and contract ownership.
