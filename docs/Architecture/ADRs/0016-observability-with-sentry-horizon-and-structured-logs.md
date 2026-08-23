# ADR 0016: Observability With Sentry, Horizon, and Structured Logs

**Status: Accepted**

> **Note**: Implementation evidence is retained; the Product Owner decision below establishes the current policy.

## Context
Sentry middleware/configuration, Horizon, and base-job telemetry provide current application observability.

## Decision
Spark adopts an internal monitored-service baseline. It will define internal freshness, error, and backlog objectives; alert an accountable owner on missed schedules, failed/dead-letter work, and queue saturation; and maintain incident and replay runbooks. No public uptime or support SLA is implied.

## Consequences
Request/job reporting and queue visibility exist. Concrete objectives, thresholds, retention, accountable owner, and runbooks must be established before this policy can be operationally complete.

## Alternatives rejected
Public service commitments and best-effort telemetry without response ownership were rejected.

## Related repository paths
`config/sentry.php`, `bootstrap/app.php`, `app/Providers/HorizonServiceProvider.php`, `app/Jobs/Base/`.

## Evidence gaps / open questions
Define internal objectives, alert thresholds/owner, telemetry retention, and incident/replay runbooks.
