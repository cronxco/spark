# Spark mobile API — requirements

What `/api/v1/mobile/*` has to provide for the iOS app, and where today's
surface falls short of it.

This is a requirements list, not a reference. The reference is
[`mobile_API.md`](mobile_API.md); the check-in contract is
[`MOBILE_CHECK_INS.md`](MOBILE_CHECK_INS.md). Every "current state" note below
was checked against `routes/mobile.php`, the controller, or a live response at
the time of writing — where something was not checked, it says so.

Requirements are numbered `MR-n` so they can be cited from tickets and from
the client. Priority is one of:

| | meaning |
| --- | --- |
| **P1** | the client is doing something wrong or wasteful today because this is missing |
| **P2** | the client works, but carries logic that belongs on the server |
| **P3** | worth doing, nothing is broken |

---

## 1. Principles

These hold for every endpoint; the numbered requirements below only call them
out where they are currently broken.

1. **The client renders, the server decides.** Anything that needs a
   threshold, a baseline, a rounding rule or a piece of prose parsing is the
   server's job. A rule implemented in the app ships on Apple's release
   schedule and is wrong on every version already installed.
2. **Say what you don't know.** A field that is absent because a service has
   not reported must be distinguishable from one that is genuinely zero. The
   day Apple Health has not synced is not a day with 211 steps.
3. **One shape per concept.** `sync_status`, pagination envelopes, error
   bodies and timestamps should look the same on every endpoint that has them.
4. **Every list is paginated and every document is cacheable.** The app is on
   a phone, on a train, on a 25-second background budget.
5. **Aggregates are the server's.** If the app has to make N requests and do
   arithmetic to show one number, that number should be an endpoint.
6. **Times carry their zone.** The user's day boundary is not the server's and
   not UTC.

---

## 2. Day and briefing

`GET /briefing/today` is the Day tab's primary request and the app's most
important endpoint.

### MR-1 — `sync_status` must be documented, typed and explicit · **P1**

**Current state.** The response carries a map of service name to that
service's last write:

```json
"sync_status": {
  "oura":         { "event_count": 6,  "last_event_time": "…", "actions": [...] },
  "apple_health": { "event_count": 13, "last_event_time": "…", "actions": [...], "coverage": "partial" }
}
```

The iOS client modelled this as a flat `{ up_to_date, stale, last_event_at }`
object. Every field is optional, so it decoded to all-nulls against every real
response and never failed — the app simply never knew a service was behind.
That is why a morning with Apple Health unsynced rendered 211 steps as a
−97.5% anomaly rather than as a gap. Fixed client-side, but the shape was
never written down.

**Requirement.** Document the per-service shape, and add two fields so the
client is not inferring freshness from a timestamp and a hard-coded threshold:

- `stale: bool` — the server's judgement that this service is behind for this
  day, using whatever cadence it knows the integration runs at.
- `as_of: timestamp` — when the server last successfully reached the service,
  which is not the same as the last event it produced.

Keep `coverage` and state its full enumeration.

**Acceptance.** A client can render "waiting on Apple Health" from `stale`
alone, without knowing what a reasonable interval is for any integration.

### MR-2 — `/briefing/today` must emit an ETag · **P1**

**Current state.** `BriefingController::today` sets `Last-Modified` only. The
architecture the app is built on is stale-while-revalidate against an ETag
(see `spark-ios/CLAUDE.md`), `SparkKit` ships an `ETagCache`, and several
other mobile controllers already emit ETags — `FlintDigestsController`,
`MoneyAccountsController`, `EventsController`, `BlocksController`,
`ObjectsController`, `NotificationsController` and others. Briefing, the
endpoint hit most often and on every foreground, is not one of them.

**Requirement.** Emit a strong ETag derived from the summary content and
honour `If-None-Match` with a 304.

**Acceptance.** A foreground revalidation on an unchanged day transfers no
body.

### MR-3 — day-scoped aggregates need day-scoped baselines · **P2**

**Current state.** `vs_baseline_pct` is published per metric inside
`sections`, and `GET /metrics/baselines` exposes the underlying
`MetricStatistic` rows. But those statistics are computed **per event value**,
not per day. That is the same thing only for metrics that emit once a day —
`apple_health.had_step_count.count` has a mean of 8,546, which is a daily
figure. It is not the same thing for anything that emits many times a day:
`monzo.card_payment_to.GBP` has a mean of £77.85, which is the mean
*transaction*, not the mean day.

So there is no baseline for "what does a day normally cost", and the Day tab's
money card is the only one of four that cannot draw its figure against a
band.

**Requirement.** Publish baselines for the day-level aggregates the briefing
already computes, starting with `money.total_spend`, as `vs_baseline_pct` on
the same shape as the health and activity metrics.

**Acceptance.** Every figure the briefing presents as a day total carries
either a `vs_baseline_pct` or an explicit reason it has none.

### MR-4 — separate spend from internal transfers · **P1**

**Current state.** `money.total_spend` is the sum of the day's money events
regardless of direction or destination. On 19 September 2026 it reported
£2,621.16. The actual outflow to merchants that day was £106.89; the rest was
£2,508.27 moving from a current account into a savings pot, plus a £52 pot
withdrawal. Moving money between your own accounts is not spending, and a
client cannot tell the difference without hard-coding Monzo's action names.

**Requirement.** Split the money section into at least:

- `total_spend` — outflow to third parties, which is what the word means;
- `internal_transfers` — movement between the user's own accounts and pots;
- `total_in` — inbound.

Keep the full `transactions` array as it is.

**Acceptance.** A quiet Sunday with one automated savings transfer reports
£0.00 spend, not £5.26.

---

## 3. Money

### MR-5 — net worth must be one request · **P1**

**Current state.** There is no net-worth endpoint. To show net worth and its
month-on-month change the client must `GET /money/accounts`, then
`GET /money/accounts/{id}/balances` once per account, then reconcile the
histories by day and sum them, applying `is_negative_balance` per account.
That is N+1 requests on a phone for two numbers, and the reconciliation logic
now exists in two places in the app.

**Requirement.** `GET /money/net-worth`, returning the current total, the
currency, and a comparison over a requested window:

```
GET /money/net-worth?compare=1month
{ "data": { "total": 48210.64, "currency": "GBP",
            "comparison": { "window": "1month", "then": 47006.46,
                            "change": 1204.18, "change_pct": 2.56 },
            "as_of": "…" } }
```

Accounts whose history does not span the window must be excluded from **both**
sides of the comparison, and the response should say how many were.

**Acceptance.** The Day tab's money card and the Explore money hero are both
served by one request.

### MR-6 — accounts need a pinned flag · **P2**

**Current state.** `MoneyAccount` has `kind`, `account_type`, `provider` and
so on, but nothing that marks an account as the one the user wants to see.
The client currently guesses: first account whose type contains "current".

**Requirement.** A user-settable `is_pinned` on the account, exposed on
`GET /money/accounts` and settable via `PATCH /money/accounts/{id}`. At most
one pinned account, or an ordered list if more than one surface will use it.

**Acceptance.** The Day tab shows the account the user chose, not the first
one that pattern-matched.

---

## 4. Flint

### MR-7 — a digest needs a structured opener · **P2**

**Current state.** `summary` is prose with an implicit structure: a greeting
paragraph, a heading in capitals, then the paragraph that says something.
An evening digest instead opens with an em-dashed cheat-sheet list. To show
the lede on the Day tab the client string-parses all of that: drops a greeting
under 40 characters, drops any paragraph whose letters are all uppercase,
strips a leading `— `, strips Markdown emphasis, then truncates on a sentence
boundary. That is five heuristics against prose the skill can change at any
time, shipped in a binary.

**Requirement.** Publish the lede as its own field — `opener` — alongside
`summary`. The generating skill already knows which sentence it is; it should
not have to be recovered by parsing.

**Acceptance.** The client renders `digest.opener` verbatim and owns no
knowledge of digest prose structure.

### MR-8 — a way to ask for the latest digest · **P2**

**Current state.** `GET /flint/digests` takes a `date`. "The most recent
thing Flint has written" is not expressible: before the morning brief has run,
the newest digest is yesterday evening's. The client asks for today, inspects
the result, and asks again for yesterday — two round trips on every cold
start, on the path that renders the first card on the home screen.

**Requirement.** `GET /flint/digests/latest`, optionally
`?kind=briefing`, returning the single most recent digest across dates, with
its `local_date` and `period` so the client can say which run it was.

**Acceptance.** One request, on any day, at any hour, returns the right
digest.

### MR-9 — questions need a title and a time window · **P2**

**Current state.** Two problems on `GET /flint/questions`:

1. `FlintQuestion` has `question` but no `title`. The same question as a
   digest block (`flint_user_question`) has both — the block carries
   "The £2,508 transfer from Daniel" as its title and the full question as its
   body. The questions endpoint drops the title, so a surface that lists
   questions has only the full text to show.
2. There is no time filter. To show the last 48 hours the client fetches all
   open questions and all answered questions, then filters on `asked_at`
   locally — two unbounded requests to render at most a handful of cards.

**Requirement.** Add `title` to the question resource, and a `since`
parameter (ISO timestamp or a relative window) applied server-side. Allow
`status` to take multiple values so open and answered can be fetched together
in one call.

**Acceptance.** `GET /flint/questions?status=open,answered&since=48h` returns
exactly what the Day tab shows, in one request.

### MR-10 — a thread should publish what it is waiting for · **P2**

**Current state.** `FlintTopic.content` is a running prose summary that, by
convention, ends by naming what would move the thread on — "the decisive next
developments are a G7 decision on reserves…". That closing sentence is the
only part worth showing on a home screen, so the client extracts it by
splitting the content into sentences and taking the last one, reaching back a
sentence when the last is too short to carry anything.

**Requirement.** A `watching_for` field on the topic resource, written by the
same routine that writes `content`.

**Acceptance.** The client shows `watching_for` and owns no sentence-splitting.

### MR-11 — question answers should return the updated resource · **P3**

**Current state.** Not verified in detail. `POST /flint/questions/{block}/answer`
returns `FlintQuestionAnswerResponse`; the client currently re-fetches the
question list after answering to pick up the new state.

**Requirement.** Return the full updated question resource so a client can
update in place without a second request.

---

## 5. Timeline and feed

### MR-12 — grouping should be server-side · **P3**

**Current state.** Both the web Day page and the iOS timeline group
consecutive events sharing an action and a service, and both implement it
separately. Twenty Spotify plays become one row saying twenty — but only
because two codebases independently decided so, with their own run-length
rules.

**Requirement.** Either publish the grouping key on each event, or return
pre-grouped runs from `GET /feed` with a count and the member ids.

**Acceptance.** Web and iOS group identically without coordinating.

### MR-13 — events need a resolved display direction for money · **P3**

**Current state.** The client infers whether money moved in or out from the
action string — suffix `_to`, suffix `_from`, contains "credit". That is a
guess over an open vocabulary that grows with every integration.

**Requirement.** A `direction` field (`in` / `out` / `internal`) on money
events.

---

## 6. Cross-cutting

### MR-14 — one pagination envelope · **P3**

**Current state.** Some endpoints return `Page<T>` with `data`, `next_cursor`
and `has_more`; others return a bare `{ "data": [...] }` with no cursor —
`GET /flint/topics` and `GET /money/accounts` among them. Unbounded lists are
fine while they are small and become a problem silently.

**Requirement.** Every collection endpoint returns the same envelope and
accepts `cursor` and `limit`, even where the list is currently short.

### MR-15 — document the error envelope · **P3**

**Current state.** Not audited. Responses observed in passing use
`{ "message": "…" }` with a 4xx.

**Requirement.** Document one error shape — code, human message, and optional
field-level detail — and state which endpoints can return which codes.
Validation failures in particular need to be machine-readable.

### MR-16 — state the time zone contract · **P2**

**Current state.** Mixed. `briefing/today` returns a `timezone`;
`flint/digests` returns `effective_timezone` in its meta; `check-ins` has its
own `check-ins/timezone` endpoint; other day-scoped endpoints return neither.
A client assembling a day from several endpoints has to assume they agree.

**Requirement.** Every day-scoped endpoint states the zone it resolved the day
in, under one field name, and all of them resolve it the same way.

### MR-17 — idempotency on every mutating endpoint · **P3**

**Current state.** `POST /flint/questions/{block}/actions` takes an
`Idempotency-Key` and an `If-Match`. Other mutating endpoints — check-in
submission, balance creation, tag mutations — do not.

**Requirement.** Accept `Idempotency-Key` on all POSTs that create something.
A phone on a bad connection retries.

---

## 7. Coverage

Routes with no client counterpart in `SparkKit/API/Endpoints`, as of this
writing:

| route | note |
| --- | --- |
| `POST /bookmarks`, `POST /bookmarks/capture` | share-extension capture is not wired to these |
| `GET /flint/routines/health` | no client surface |
| `GET /context/day`, `GET /context/service-status` | superseded in practice by `briefing/today`; `context/service-status` overlaps MR-1 |

**Requirement (MR-18, P3).** Decide per row: wire it up, or mark it
deprecated in `mobile_API.md` with a removal version. An endpoint no client
calls is an endpoint no one is testing.

---

## 8. Non-functional

- **NFR-1.** `GET /briefing/today` returns in under 400ms warm. It is on the
  critical path for first paint.
- **NFR-2.** The silent-push sync handler has a 25-second budget end to end,
  so `GET /sync/delta` must be bounded and cursor-driven.
- **NFR-3.** No single mobile response exceeds ~250KB uncompressed. The
  briefing grows with the day; watch `transactions` and the digest `blocks`.
- **NFR-4.** Every endpoint the Day tab needs is reachable in one round trip
  per concern. Today the tab issues: briefing, digests (×2 dates), questions
  (×2 statuses), topics, accounts, and balances (×N accounts). MR-5, MR-8 and
  MR-9 take that from roughly 6+N to 5.

---

## 9. Priority summary

| | requirement | area |
| --- | --- | --- |
| **P1** | MR-1 sync_status explicit | briefing |
| **P1** | MR-2 briefing ETag | briefing |
| **P1** | MR-4 spend vs transfers | money |
| **P1** | MR-5 net worth endpoint | money |
| **P2** | MR-3 day-level baselines | briefing |
| **P2** | MR-6 pinned account | money |
| **P2** | MR-7 digest opener | flint |
| **P2** | MR-8 latest digest | flint |
| **P2** | MR-9 question title + window | flint |
| **P2** | MR-10 thread watching_for | flint |
| **P2** | MR-16 time zone contract | cross-cutting |
| **P3** | MR-11, MR-12, MR-13, MR-14, MR-15, MR-17, MR-18 | — |

The four P1s are each a case where the app is already doing something wrong or
wasteful in production. MR-1 and MR-4 are correctness: one made a sync gap
look like a collapse in activity, the other makes a savings transfer look like
spending. MR-2 and MR-5 are cost: a full briefing body on every foreground,
and N+1 requests for two numbers.

## 10. Open questions

1. Should day-level baselines (MR-3) live in `MetricStatistic` alongside the
   per-event ones, or in a separate daily-aggregate table? They answer
   different questions and sharing a table will confuse both.
2. Is `total_spend` (MR-4) consumed anywhere that expects the current
   behaviour? Changing its meaning is a breaking change even though it is
   arguably a bug fix — it may need to arrive as a new field.
3. Does the web Day page want MR-12's server-side grouping, or is its Livewire
   implementation load-bearing enough that a shared key is the better route?
