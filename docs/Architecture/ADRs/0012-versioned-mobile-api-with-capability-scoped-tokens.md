# ADR 0012: Versioned Mobile API With Capability-Scoped Tokens

**Status: Accepted**

> **Note**: Implementation evidence is retained; the Product Owner decision below establishes the current policy.

## Context
Mobile routes mount at `/api/v1/mobile` behind a feature gate, Sanctum abilities, ETag middleware, and scoped broadcast channel.

## Decision
Spark adopts least-privilege, action-scoped mobile API authorization. Each endpoint must require only the ability it needs; every resource access must be user-scoped and covered by an authorization matrix and tests; token revocation takes effect on subsequent requests.

## Consequences
Clients use a named version and conditional reads. The current additive `ios:read`/`ios:write` middleware convention is not the desired long-term contract. Compatibility/deprecation remains separate policy work.

## Alternatives rejected
The current dual-ability convention and broad trusted-client access were rejected.

## Related repository paths
`routes/api.php`, `routes/mobile.php`, `routes/channels.php`, `bootstrap/app.php`, `docs/mobile/MOBILE_API.md`.

## Evidence gaps / open questions
Define endpoint ability mapping, authorization tests, and compatibility/deprecation ownership.
