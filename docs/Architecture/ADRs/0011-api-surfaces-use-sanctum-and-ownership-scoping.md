# ADR 0011: API Surfaces Use Sanctum and Ownership Scoping

**Status: Accepted**

> **Note**: Implementation evidence is retained; the Product Owner decision below establishes the current policy.

## Context
Protected API routes use Sanctum and ownership helpers.

## Decision
Authenticated user-facing APIs will use a shared, deny-by-default ownership authorization layer with an endpoint-by-endpoint negative authorization test matrix. Trusted background/system jobs may bypass user-request scoping only through explicitly tenant-derived inputs. This is a future policy; only `POST /api/events` remediation is currently authorised to implement it.

## Consequences
Correct controller use remains security-critical until the policy is implemented. The policy covers user-facing API ownership, while explicit tenant-derived inputs constrain trusted job bypasses.

## Alternatives rejected
Controller-by-controller ownership as the durable boundary and broad trusted-client access were rejected.

## Related repository paths
`routes/web.php`, `routes/api.php`, `app/Traits/AuthorizesOwnership.php`, `docs/API/README.md`.

## Evidence gaps / open questions
Define rollout ownership, endpoint inventory, negative tests, and API deprecation separately.
