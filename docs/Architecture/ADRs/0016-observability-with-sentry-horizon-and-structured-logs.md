# ADR 0016: Observability With Sentry, Horizon, and Structured Logs

**Status: Accepted (reconstructed current state)**

> **Note**: This ADR records observed implementation. It is not evidence that the design was intentionally chosen or is sufficient.

## Context
Sentry middleware/configuration, Horizon, and base-job telemetry provide current application observability.

## Decision
The current system uses Sentry and Horizon with application logging/job telemetry.

## Consequences
Request/job reporting and queue visibility exist; alert thresholds, retention, and incident procedures are open.

## Alternatives rejected
No explicit historical rejection is evidenced.

## Related repository paths
`config/sentry.php`, `bootstrap/app.php`, `app/Providers/HorizonServiceProvider.php`, `app/Jobs/Base/`.

## Evidence gaps / open questions
Define SLOs, alert ownership, and replay runbooks.
