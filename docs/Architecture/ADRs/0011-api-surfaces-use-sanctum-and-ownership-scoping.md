# ADR 0011: API Surfaces Use Sanctum and Ownership Scoping

**Status: Accepted (reconstructed current state)**

> **Note**: This ADR records observed implementation. It is not evidence that the design was intentionally chosen or is sufficient.

## Context
Protected API routes use Sanctum and ownership helpers.

## Decision
The current API boundary is Sanctum authentication plus ownership scoping.

## Consequences
Correct controller use is security-critical; no global ownership scope or exhaustive authorization matrix is evidenced.

## Alternatives rejected
No explicit historical rejection is evidenced.

## Related repository paths
`routes/web.php`, `routes/api.php`, `app/Traits/AuthorizesOwnership.php`, `docs/Architecture/API.md`.

## Evidence gaps / open questions
Define API deprecation and tenancy tests.
