# Spark Component Delivery Plan — August 2026

## Status and purpose

**Status:** proposed product plan, based on owner-approved direction of 15
August 2026. This catalogue turns the discovery findings in
`docs/product/PRODUCT_DISCOVERY_2026-08.md` into sequenced product requirements.
It is not implementation authorisation.

**Product outcome:** personal dogfooding reliably produces a trustworthy daily
decision and useful reflection through aggregation, analysis and owner-approved
action. The sequence is **orient → inspect evidence → choose/defer 1–3 actions
→ reflect**.

**Universal scope boundary:** one owner only. Public beta, multi-user/SaaS,
social sharing, integrations, dashboards and specialist views that lack a
demonstrated daily-loop gap are out of this plan.

## Delivery rules and gates

| Phase | Product requirement                                 | Exit evidence                                                                                                                       |
| ----- | --------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| 1     | Define the daily contract and first-use activation. | A testable contract identifies entry state, recommended source bundle, brief contents, 1–3 action limit, deferral and reflection.   |
| 2     | Make recommendations trustworthy and learnable.     | Advice exposes source/provenance, freshness, coverage, why-it-matters and the full action lifecycle.                                |
| 3     | Validate iOS as a companion to the proven loop.     | iOS makes the brief, capture/check-in and approved-action follow-up glanceable without duplicating web investigation/configuration. |
| Later | Expand only on a measured gap.                      | Personal-use evidence shows that the daily loop cannot meet its outcome without the proposed surface/source.                        |

No component may bypass an earlier gate simply because related code or APIs
already exist.

## Component catalogue

### 1. Platform and integrations

**Current evidence:** normalised event/object/block record and registered
integration framework: `app/Integrations/`,
`app/Providers/IntegrationServiceProvider.php`,
`docs/Architecture/INTEGRATION_PLUGINS.md`, and integration/update routes in
`routes/web.php`.

**User outcome:** enough current, understandable source data to trust the
daily brief.

**In scope:** Phase 1 specifies the smallest recommended source bundle and its
activation/recovery path. Phase 2 defines owner-facing coverage and freshness
for that bundle, including how missing or stale data constrains advice.

**Out / later:** adding sources for breadth; new integration dashboards;
automatic external changes. Later sources must close a logged daily-loop gap.

**Dependencies:** source identity, update-state semantics and ownership data
from architecture/data review.

**Acceptance:** an owner can connect the recommended bundle, reach a first
useful brief, see which required sources are absent/stale, and recover without
guessing whether a recommendation is affected.

**Documentation destination:** product contract here; implementation semantics
in `docs/Architecture/INTEGRATION_PLUGINS.md` and `docs/UI and UX/UPDATES_INTERFACE.md`.

### 2. Today and the daily loop

**Current evidence:** `/today` and `Day` provide date navigation, a searchable
timeline, Outline day note and morning/afternoon check-ins:
`routes/web.php`, `app/Livewire/Day.php`,
`resources/views/livewire/day.blade.php`, `resources/views/livewire/cards/day/`.

**User outcome:** orient quickly, inspect only necessary evidence, make or defer
up to three owner-controlled actions, then reflect.

**In scope:** Phase 1 defines Today’s entry state, brief hierarchy, evidence
links, daily action selection/deferral and reflection hand-off. Phase 2 joins
the lifecycle and advice trust signals. Phase 3 validates compact iOS follow-up.

**Out / later:** replacing the timeline; turning Today into a broad dashboard;
unbounded task management.

**Dependencies:** approved interaction model; Flint recommendation/action
contract; source freshness/coverage vocabulary.

**Acceptance:** the daily flow does not end at passive consumption; every
suggested item can be inspected, selected, explicitly deferred or left as a
non-action, while the timeline remains reachable as evidence.

**Documentation destination:** this plan and a future detailed daily-contract
specification in `docs/product/`; UI behaviour in `docs/UI and UX/` after the
interaction model is approved.

### 3. Investigation and recall

**Current evidence:** events, objects, blocks, tags, media, places/map,
bookmarks, Spotlight and semantic search in `routes/web.php` and
`docs/Architecture/{SPOTLIGHT,SEMANTIC_SEARCH,PLACES}.md`.

**User outcome:** verify a conclusion or recover a relevant past item without
leaving the trusted record.

**In scope:** Phase 1 establishes evidence links from a brief/action to the
smallest useful investigation context. Phase 2 standardises provenance language
at those links.

**Out / later:** navigation redesign and new browsing modes unless personal use
shows the evidence path is insufficient.

**Dependencies:** canonical provenance/evidence reference supplied by the data
plan; Today interaction model.

**Acceptance:** an owner can answer “why did Spark tell me this?” from the
brief and reach supporting records without a terminology-led scavenger hunt.

**Documentation destination:** product evidence requirements here;
implementation remains in the cited architecture documents.

### 4. Money, health, metrics and trust

**Current evidence:** money/accounts and receipts, health ingestion, metrics,
baselines/trends, anomalies, notifications and update progress: `routes/web.php`,
`app/Livewire/MetricsOverview.php`, `docs/Architecture/ACTION_PROGRESS.md`,
`docs/Architecture/NOTIFICATIONS.md`, `docs/UI and UX/UPDATES_INTERFACE.md`.

**User outcome:** understand the reliability and materiality of a health,
financial or metric-based conclusion before acting.

**In scope:** Phase 2 defines cross-domain freshness, coverage, confidence
language, anomaly acknowledgement and why-it-matters presentation at the point
of advice.

**Out / later:** domain-specific dashboards and new health/money analytics
that do not alter the daily decision quality.

**Dependencies:** data freshness/retention/aggregation decisions from the data
plan; safety and presentation constraints from the interaction model.

**Acceptance:** an owner can distinguish fact, inference, missing coverage and
stale input, and can see how each changes a recommendation.

**Documentation destination:** product trust contract here; domain/data
semantics in `docs/Architecture/` and future data ADRs.

### 5. Flint

**Current evidence:** digest/newspaper, insights, questions, memory, feedback
and prioritised action blocks in `resources/views/livewire/flint/` and
`app/Services/FlintBlockCreationService.php`.

**User outcome:** receive explainable advice and retain a deliberate record of
what was adopted, deferred, completed and learned.

**In scope:** Phase 1 specifies the recommendation-to-Today hand-off and owner
control boundary. Phase 2 defines the canonical lifecycle: proposed → adopted
or deferred → completed → outcome/reflection, with feedback and evidence.

**Out / later:** autonomous external-service actions, more specialised agents
or intelligence surfaces before lifecycle evidence proves value.

**Dependencies:** decision on the authoritative action record and event model
from architecture/data review; Today interaction model.

**Acceptance:** Flint cannot create an external change without an explicit
per-action confirmation; a user can inspect why an action was proposed and its
state/outcome across Flint and Today.

**Documentation destination:** this product lifecycle; technical ownership and
state transitions in an ADR plus relevant Flint architecture documentation.

### 6. Mobile API

**Current evidence:** briefing, check-ins, search/detail, metrics, map,
integrations/sync, widgets, notifications, money, bookmarks and Flint endpoints
in `routes/mobile.php` and `app/Http/Controllers/Api/V1/Mobile/`.

**User outcome:** iOS has the constrained data/actions necessary to support the
same daily loop, with equivalent trust boundaries.

**In scope:** Phase 2 defines the API contract required for evidence,
freshness/coverage and action state. Phase 3 supplies only the endorsed
companion flows.

**Out / later:** endpoint expansion for every existing web surface; mobile-only
behaviour that diverges from the owner’s daily record.

**Dependencies:** final daily/action/provenance contract; API and sync review.

**Acceptance:** iOS can render the approved brief, open its supporting evidence,
submit approved lifecycle transitions and reflect without inventing client-only
meaning.

**Documentation destination:** product capability boundary here; API contracts
and versioning in `docs/Architecture/`.

### 7. iOS companion and platform capabilities

**Current evidence:** Day, Explore, Knowledge, Flint and Search tabs plus
HealthKit, widgets, Live Activities, extensions, Watch targets and local sync:
`SparkApp/Sources/App/MainTabView.swift`, `Packages/`, `Extensions/`, `Watch/`
in the `spark-ios` checkout; server support in `routes/mobile.php`.

**User outcome:** a glanceable daily brief, lightweight check-in/capture and
approved-action follow-up; web remains configuration and deep investigation.

**In scope:** Phase 3 validates those three companion jobs against the Phase 1
and 2 contract, including notification/widget entry points only where they lead
to a safe, evidence-backed daily action.

**Out / later:** standalone mobile feature expansion, App Store/public-beta
planning and new Watch scope. The older Projects iOS plan is historical context,
not the controlling roadmap.

**Dependencies:** stable API contract, iOS interaction model and measured web
daily-loop use.

**Acceptance:** a mobile owner can complete the supported loop with the same
evidence and approval boundary as web; unsupported deep investigation clearly
hands off to web.

**Documentation destination:** product boundary here; iOS implementation plan
must be reconciled in `REPOS/spark-ios/docs/` and the existing Projects iOS page
once Phase 3 is authorised.

## Cross-component decisions requiring owner approval

These must be resolved before implementation planning turns into production
work:

1. **Authoritative action record:** which existing or new record owns lifecycle
   state, its history and outcome/reflection across Today, Flint and iOS.
2. **Recommended source bundle:** the minimum initial sources and the required
   behaviour when any source is unavailable, stale or incomplete.
3. **Decision threshold and safety language:** when Spark may recommend an
   action versus only present an observation, especially for health and money.
4. **Reflection minimum:** whether reflection is an explicit daily prompt,
   completion outcome, optional note, or a combination.

Architecture and data proposals should resolve technical alternatives and
produce ADRs; the owner decides these product consequences.

## Success measures

- Useful, evidence-linked brief after recommended-bundle activation.
- Daily sessions end with selected action(s), explicit deferral or reflection.
- Provenance, freshness and coverage are understandable in context.
- No external action happens without individual confirmation.
- New scope is admitted only through an evidenced daily-loop gap.

## Published context

The concise human-facing roadmap is maintained on Projects → Spark:
https://docs.cronx.co/doc/spark-QzA3IllhFm.
