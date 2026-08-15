# ADR 0001: Laravel Modular Monolith

**Status: Accepted**

> **Note**: Implementation evidence is retained; the Product Owner decision below establishes the current policy.

## Context
Spark is one Laravel 12 application with Livewire 3, split route surfaces, and domain directories.

## Decision
Spark remains a deliberate Laravel modular monolith: one deployable application with owned internal modules and interfaces. Service extraction requires measured operational, scaling, security-isolation, or independent-release evidence that outweighs the added distributed-system cost.

## Consequences
Shared framework and database behavior simplify integration; failure and deployment isolation remain application-wide. Module boundaries must be explicit enough that a future extraction can be justified and reversed from evidence rather than preference.

## Alternatives rejected
Service extraction as a default roadmap and an unconstrained single deployment were rejected because neither is justified by current evidence.

## Related repository paths
`CLAUDE.md`, `composer.json`, `app/`, `routes/`.

## Evidence gaps / open questions
Document module ownership and interfaces as modules evolve; extraction remains a future evidence-based decision.
