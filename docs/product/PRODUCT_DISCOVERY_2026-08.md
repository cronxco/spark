# Spark Product Discovery — August 2026

## Purpose and scope

This is an evidence-led product-discovery assessment of the existing Spark web
application and its documented iOS companion direction. The owner approved the
product decisions and roadmap below on 15 August 2026; the discovery evidence
is retained as the rationale, not a second competing roadmap.

## Product thesis observed

Spark is a private, single-user personal-data platform. It ingests activity
from health, money, media, knowledge, and online services; normalises that data
into events, objects and blocks; then helps its owner review a day, investigate
history, and receive AI-assisted briefings and coaching.

The iOS vision makes the intended outcome explicit: answers should be
glanceable, timely and actionable, with detail available on demand. The web
application currently supplies the configuration and deep-browsing layer.

**In scope:** one owner using their connected data to understand the present,
reflect on the past, and decide what to do next.

**Out of scope:** a multi-user/SaaS positioning, social sharing, and treating
Spark as a generic integration platform. These are explicitly non-goals of the
iOS vision and should not be inferred from the broad integration surface.

## Owner-approved product decisions — 15 August 2026

| Decision                  | Approved direction                                                                                                                                                                     |
| ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Purpose                   | Spark is a personal data platform for **aggregation, analysis and action**—helping its owner stay on top of life.                                                                      |
| Audience and distribution | Personal dogfooding only. No public beta, multi-user/SaaS or social-sharing scope.                                                                                                     |
| Home experience           | Today is the decision/triage surface. Its timeline is the evidence and investigation layer, not the home outcome.                                                                      |
| Flint authority           | Advisor plus owner-approved action tracker. Flint may propose, explain, prioritise and remember actions; it must not take an external action without explicit per-action confirmation. |

## Roadmap and phase gates

### Phase 1 — Define the daily contract

Specify the daily loop and activation path: **orient → inspect evidence →
choose/defer 1–3 actions → reflect**. Define a small recommended source bundle
that reaches a first useful, evidence-linked brief. No further owner decision
is needed to begin this product-definition phase.

**Gate:** a testable daily contract with target owner, entry state, action
states, reflection and acceptance measures is approved before interaction or
production implementation work begins.

### Phase 2 — Make advice trustworthy and learnable

Define user-facing freshness, coverage and “why this matters” evidence at the
point of advice. Define the action lifecycle: proposed, adopted, deferred,
completed and outcome/reflection.

**Gate:** the owner can inspect the provenance and currentness of a daily
recommendation, control its status, and leave feedback before new intelligence
features are added.

### Phase 3 — Validate the iOS companion against the loop

Use iOS for glanceable daily brief, check-in/capture and approved-action
follow-up. Retain web as the configuration and deep-investigation surface.

**Gate:** iOS scope demonstrates the approved daily loop rather than adding
standalone mobile surfaces.

### Phase 4 — Expand only on proof

Additional integrations, dashboards and specialist views require a demonstrated
gap in the daily loop; integration count is not a roadmap metric.

## Acceptance measures

- Personal use reaches a useful, evidence-linked daily brief from the
  recommended source bundle.
- The daily flow ends in a chosen action, explicit deferral or reflection—not
  only information consumption.
- The owner can answer “why did Spark tell me this?” and “how current is it?”
  without leaving the brief.
- The owner can approve or decline every external action individually.
- Retained daily value and completed/reflected-on actions, rather than source
  breadth, determine whether the next phase is justified.

## Intended users and core jobs

| User                            | Job to be done                                                             | Current evidence                                                                                                                                                     |
| ------------------------------- | -------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Owner/operator                  | See what happened today and record a small amount of reflective context.   | `routes/web.php` → `/today`; `app/Livewire/Day.php`; `resources/views/livewire/day.blade.php`; `docs/mobile/MOBILE_CHECK_INS.md`.                                    |
| Owner/operator                  | Connect and maintain personal data sources so the record stays current.    | `resources/views/livewire/integrations/index.blade.php`; `docs/UI and UX/UPDATES_INTERFACE.md`; `docs/Architecture/INTEGRATION_PLUGINS.md`.                          |
| Owner/operator                  | Find and investigate a moment, entity, metric, place or saved item.        | `routes/web.php` (events, objects, blocks, metrics, map, bookmarks); `docs/Architecture/SEMANTIC_SEARCH.md`; `docs/Architecture/PLACES.md`.                          |
| Owner/operator                  | Turn cross-domain history into useful reflection, priority and coaching.   | `/flint`; `resources/views/livewire/flint/index.blade.php`; `resources/views/livewire/flint/coach-section.blade.php`; `tests/Feature/FlintMultiAgentSystemTest.php`. |
| Mobile owner (companion vision) | Get a quick answer without opening the web app, then drill in when needed. | Projects: `Spark iOS Companion App — Vision, Spec & Implementation Plan`; `routes/mobile.php`.                                                                       |

## Primary journeys represented today

1. **Connect and trust the record:** add/configure an integration, inspect its
   update state, reconnect or manually refresh it when required.
2. **Review a day:** arrive at `/today`, browse an event timeline, filter it,
   complete morning/afternoon check-ins and edit the associated day note.
3. **Investigate:** navigate from an event to objects, blocks, metrics, places,
   media or related search results; use Spotlight/semantic search when the
   starting point is uncertain.
4. **Reflect and orient:** visit Flint for a scheduled digest/newspaper,
   insights, questions, suggestions and a memory of prior feedback.
5. **Capture knowledge:** save, inspect and organise bookmarks/fetched content.

## Feature inventory by user outcome

| Outcome                     | Existing capability                                                                                                  | Evidence                                                                                                                                                       |
| --------------------------- | -------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Unified personal record     | Plugin integrations across health, finance, media, knowledge and online domains; shared event/object/block model.    | `CLAUDE.md`; `docs/Architecture/INTEGRATION_PLUGINS.md`; `docs/README.md`.                                                                                     |
| Daily self-awareness        | Day timeline, day note and twice-daily physical/mental-energy check-ins.                                             | `app/Livewire/Day.php`; `resources/views/livewire/day.blade.php`; `docs/mobile/MOBILE_CHECK_INS.md`.                                                           |
| Trend/anomaly understanding | Metrics, baseline/trend data and anomaly-related tasks.                                                              | `app/Livewire/MetricsOverview.php`; `tests/Feature/MetricTrackingTest.php`; `tests/Feature/TaskPipeline/DetectTrendsTaskTest.php`.                             |
| Data-source control         | Integration configuration and an updates surface with manual triggering and status.                                  | `resources/views/livewire/integrations/index.blade.php`; `docs/UI and UX/UPDATES_INTERFACE.md`.                                                                |
| Recall and research         | Spotlight plus semantic search, bookmarks/fetch, media and places.                                                   | `docs/Architecture/SPOTLIGHT.md`; `docs/Architecture/SEMANTIC_SEARCH.md`; `routes/web.php`.                                                                    |
| Guided reflection           | Flint digest/newspaper, coaching questions, insights and prioritised actions.                                        | `resources/views/livewire/flint/index.blade.php`; `resources/views/livewire/flint/memory.blade.php`; `resources/views/livewire/flint/coach-section.blade.php`. |
| Ambient mobile access       | Mobile API for briefing, feed, health, search, sync, widgets, notifications and check-ins; native companion roadmap. | `routes/mobile.php`; Projects iOS vision.                                                                                                                      |

## Prioritised opportunity list

These are product gaps to validate and decide, not implementation tickets.

| Priority | Opportunity / product consequence                                                                                                                                                                                                                                                                                                                                                                | Impact      | Confidence  | Evidence                                                                                                                                                                                                                             |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------- | ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1        | **Establish a canonical current product brief and success measures.** Spark’s scope is expressed across architecture, feature documents, Flint materials and an iOS plan, but there is no versioned web-product brief defining the primary user, hierarchy of jobs, current in/out scope and success signals. Without it, individual capabilities can grow without a shared outcome.             | High        | High        | `docs/README.md` indexes architecture/integration/UI material only; no `docs/product/` existed before this document. The published Projects `Spark` parent was empty at review time, while the iOS child contains a separate vision. |
| 2        | **Clarify the daily “what matters now?” entry experience across web and mobile.** The web default route is a detailed, searchable event timeline, while the companion vision defines a time-aware, cross-domain Today surface as the primary experience. The product decision is whether the timeline is the home outcome or the investigation layer beneath a daily briefing/triage experience. | High        | High        | `routes/web.php` redirects `/` to `/today`; `resources/views/livewire/day.blade.php` centres timeline navigation/search; published iOS vision §4.1 calls Today the primary surface.                                                  |
| 3        | **Close the insight-to-action loop.** Flint produces insights, questions and prioritised actions, but the product contract for adopting, deferring, completing or measuring an action’s outcome is not defined consistently across the primary day, Flint and task-oriented flows. This makes it hard to tell whether advice creates better decisions.                                           | High        | Medium      | `resources/views/livewire/flint/memory.blade.php` renders “Prioritized Actions”; `coach-section.blade.php` supports answer/dismiss for sessions; `resources/views/livewire/day.blade.php` is a separate timeline and note workflow.  |
| 4        | **Make data reliability legible in user terms.** Update state exposes whether a job is up to date, processing, paused or due, but the user-facing product question is broader: which domains are complete enough to trust today’s view, which have gaps, and how do those gaps affect a conclusion?                                                                                              | High        | Medium-high | `docs/UI and UX/UPDATES_INTERFACE.md` defines operational status states; iOS vision §4.2 identifies “coverage” on the Integration detail but the web product contract does not define it.                                            |
| 5        | **Rationalise discoverability around jobs rather than data-model nouns.** Events, objects, blocks, tags, metrics, media, map, money, bookmarks and Flint form a powerful investigative toolkit, but their role in the three core jobs (orient, investigate, reflect/act) is implicit. The risk is navigation and terminology become a system tour rather than a user journey.                    | Medium-high | High        | `routes/web.php` exposes distinct surfaces; `CLAUDE.md` describes the internal event/object/block hierarchy; `docs/Architecture/SPOTLIGHT.md` supports multiple expert search modes.                                                 |
| 6        | **Set an intentional boundary between web and iOS.** The published vision says web remains canonical for configuration, long-form browsing, administration and Flint editing, while iOS is glanceable and ambient. That division should be validated against the now-substantial mobile API surface before either client expands further.                                                        | Medium-high | High        | Projects iOS vision §§1 and 3; `routes/mobile.php` exposes briefing, feed, health, search, integrations, widgets, notifications and Flint endpoints.                                                                                 |

## Cross-references

- Repository detail: this document, the delivery catalogue in
  `docs/product/COMPONENT_DELIVERY_PLAN_2026-08.md`, and `docs/README.md`.
- Published product context: [Projects → Spark](https://docs.cronx.co/doc/spark-QzA3IllhFm)
  (currently the canonical parent) and [Spark iOS Companion App — Vision, Spec &
  Implementation Plan](https://docs.cronx.co/doc/spark-ios-companion-app-vision-spec-implementation-plan-fUDpxO7bu9).
