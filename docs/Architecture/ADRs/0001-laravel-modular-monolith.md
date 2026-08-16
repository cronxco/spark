# ADR 0001: Laravel Modular Monolith

**Status: Accepted (reconstructed current state)**

> **Note**: This ADR records observed implementation. It is not evidence that the design was intentionally chosen or is sufficient.

## Context
Spark is one Laravel 12 application with Livewire 3, split route surfaces, and domain directories.

## Decision
The observed deployment unit is a Laravel modular monolith.

## Consequences
Shared framework and database behavior simplify integration; failure and deployment isolation remain application-wide.

## Alternatives rejected
No explicit historical rejection is evidenced.

## Related repository paths
`CLAUDE.md`, `composer.json`, `app/`, `routes/`.

## Evidence gaps / open questions
No service-extraction or module-ownership policy is evidenced.
