# Spark P0 containment — validation and minimum viable changes

## Context

The Outline page **Spark — Product Domains** (`docs.cronx.co/doc/spark-product-domains-oXQSuKm3fB`) records a
nine-domain product audit completed 1 September 2026, reconciled into 25 canonical work packages. Its first phase,
"Immediate containment — days 0–14", is eight security/privacy packages (**PSEC-01 … PSEC-08**) drawn from 81
domain-level findings.

Those findings were recorded against fixed revisions and have never been re-checked against code. This plan
re-validates all eight against the current working tree, corrects the record where the audit is wrong or stale, and
proposes the **minimum viable change** for each — the smallest diff that removes the exposure, reusing what already
exists rather than introducing new infrastructure.

**Constraint honoured throughout:** no database migration is proposed. ADR 0003/0004 already record a Product Owner
decision to keep tenancy application-enforced and to defer retrospective schema hardening, so staying application-layer
is both the cautious choice and the one already ratified. Where a finding _cannot_ be fixed without a migration
(PSEC-08 phase 2), the plan says so explicitly and stops rather than proposing one.

### Drift since the audit

| Repo        | Audit SHA  | Current          | Drift                                                                                                            |
| ----------- | ---------- | ---------------- | ---------------------------------------------------------------------------------------------------------------- |
| `spark`     | `08b54853` | `32d94f6`        | 4 commits, all unrelated bugfixes. Findings carry over intact.                                                   |
| `spark-ios` | `1dc11161` | `74f595a` (main) | **The audit SHA is not on `main`.** It is `origin/feature/mobile-api-implementation`, 4 commits _ahead_ of main. |

The iOS drift is material and is treated as a first-class finding below.

### Scope

PSEC-01 … PSEC-08 only — the containment phase. `IOS_MOBILE_API_ENABLED` is **true in production**, confirmed with
Will, so every mobile-surface finding below is real shipped exposure rather than a pre-launch defect.

---

## Validation summary

| ID       | Package                                    | Audit status | **Validated status**                                  | Migration needed |
| -------- | ------------------------------------------ | ------------ | ----------------------------------------------------- | ---------------- |
| PSEC-01  | Token & telemetry containment (APO-01)     | P0 Shipped   | **Confirmed — worse than recorded**                   | No               |
| PSEC-02a | Telemetry payloads (APO-02)                | P0 Shipped   | **Confirmed**                                         | No               |
| PSEC-02b | Tenant-seal read/write paths               | P0 Shipped   | **Mostly confirmed; 1 item already fixed, 2 broader** | No               |
| PSEC-03  | Global cache flush (APO-04)                | P0 Shipped   | **Confirmed**                                         | No               |
| PSEC-04  | Logout / account-switch purge (TA-01)      | P0 Partial   | **Confirmed — worse than recorded**                   | No               |
| PSEC-05  | Raw presentation (TA-02)                   | P0 Shipped   | **Confirmed, different mechanism**                    | No               |
| PSEC-06  | Share capture (CC-05)                      | P0 Shipped   | **Confirmed; audit wrong on the cause**               | No               |
| PSEC-07  | Native notification contract (NOTIF-01/02) | P0 Shipped   | **Confirmed — contract is unsatisfiable**             | No               |
| PSEC-08  | Connector credential encryption (INT-01)   | P0 Planned   | **Confirmed; both phases now shipped**                | No               |
| NOTIF-03 | Notification preference taxonomy           | Not recorded | **New finding — 4 of 5 toggles were decorative**      | No               |

### Corrections to the audit record

1. **EX-01 (exact metric-series detail) is already fixed** and should be struck from PSEC-02.
   `app/Livewire/MetricDetail.php:36` aborts 403 on a foreign metric; `MetricsController::show`
   (`app/Http/Controllers/Api/V1/Mobile/MetricsController.php:45`) resolves through
   `MetricIdentifierMap::resolve($metricId, $user)` and 404s. The residual metric leak is via Spotlight, which
   belongs under CC-01.
2. **EOB-01 (tags) is half fixed.** `Api/V1/Mobile/TagsController::tagQuery()` (line 216) is a correct
   ownership-derived implementation. The leak survives only on the **web** catalogue.
3. **APO-03 is broader than recorded.** The audit said admin _listings_ were scoped. For
   `task-pipeline-overview.blade.php` they are not — six unscoped read queries including
   `getRecentFailuresProperty` (line 253), which exposes other users' error text. Two components the audit never
   named (`pending-links.blade.php`, admin `search.blade.php`) have the same defect;
   `search.blade.php:88` exposes every user's raw search strings.
4. **APO-05 (CI) is partially fixed** — not a PSEC item, but it gates evidence for every item here. Both workflows
   still end the PHPUnit line with `|| true`, but now reconstruct failure from the TeamCity stream and `exit 1`, so
   ordinary failures do fail CI. What still passes green: a crash, a bootstrap error, an OOM, or a zero-test run.
   Compounding this, **90 test files / 767 methods use only `@test` docblocks**, which PHPUnit 13.1.14 does not
   discover — they are silently absent from the arithmetic CI uses to decide pass/fail.
5. **PSEC-08's blocking product decision is already made.** The roadmap lists "APP_KEY-bound encryption versus KMS"
   as needing Will's approval; ADR 0018 already records it as **Accepted (implementation deferred)** with APP_KEY
   chosen and KMS explicitly rejected. No new decision is required to start.
6. **The audit's `webhook_secret` "constant-time lookup" risk does not apply.** `webhook_secret`, `access_token` and
   `refresh_token` appear in **zero** `where()` clauses repo-wide — they are only read as model attributes. This is
   what makes PSEC-08 phase 1 safe and migration-free.
7. **PSEC-08 phase 2 needed no migration after all, and the "~67 call sites" figure was wrong.** A cast is
   transparent, so no call site changed. What mattered was whether any SQL JSON path touches a secret leaf; all
   eleven were checked and none does. See PSEC-08 below.
8. **The notification preference taxonomy is a finding the audit did not record** — four of the five mobile
   categories gated notifications that do not exist, while three real types had no toggle. Filed here as NOTIF-03.
9. **`Integration::configuration` was leaking into the activity log too.** The phase 1 write-up spotted this for
   `IntegrationGroup` but not for `Integration`, which logs `configuration` via `logFillable()` with no `logExcept`
   — so Hevy and Goodreads API keys kept landing in the changelog.

---

## PSEC-01 — Token authority containment

**Validated: confirmed, and the escalation path is live.** Four independent wildcard vectors:

| Site                                                            | Behaviour                                                                                                                                      |
| --------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| `app/Http/Controllers/Api/V1/Mobile/ApiTokensController.php:51` | `$abilities = array_values($validated['abilities'] ?? ['*']);`                                                                                 |
| same, `:54-58`                                                  | strips `ios:read`/`ios:write`, then `if (empty($abilities)) { $abilities = ['*']; }` — so requesting _only_ iOS scopes yields a wildcard token |
| `routes/api.php:69`                                             | legacy `POST /api/tokens/create` calls `createToken($name)` with abilities omitted → `['*']`                                                   |
| `resources/views/livewire/settings/api-tokens.blade.php:68`     | web settings, same omission → `['*']`                                                                                                          |

The route is gated on `ability:ios:write` (`routes/mobile.php:233`), which an ordinary iOS OAuth session holds. Because
`SparkAbility::allows()` (`app/Support/SparkAbility.php:32`) delegates to `tokenCan()`, and `tokenCan()` returns true
for everything on a `['*']` token, **every `spark.ability:*` gate in `routes/api.php:169-231` is satisfied by a token an
iOS session can mint for itself.** There is no ability allowlist anywhere in the codebase.

`tests/Feature/Api/V1/Mobile/ApiTokensControllerTest.php:79` pins the wildcard default as intended behaviour.

### Minimum viable change

1. **Add the vocabulary to the class that already owns capability semantics** — `app/Support/SparkAbility.php`.
   The enforced set is fully enumerable from the codebase: `bookmark:write`, `data:image`, `data:read`, `data:write`,
   `finance:read`, `finance:write`, `flint:read`, `flint:run`, `flint:write`, `insights:read`, `insights:write`,
   `integrations:read`, `integrations:sync`, plus `mcp:read` (existing legacy alias) and `ios:read`/`ios:write`
   (session-only, **never delegable**). Add `SparkAbility::DELEGABLE` and a
   `SparkAbility::canDelegate(User $issuer, array $requested): bool` doing a strict-subset check.
2. **`ApiTokensController::store`** — make `abilities` `required`, validate each against
   `Rule::in(SparkAbility::DELEGABLE)`, delete the `?? ['*']` default and delete the all-hidden→wildcard collapse
   (return 422 instead), and reject any request not a subset of the issuer's own abilities.
3. **Contain the mobile path without deleting the route**: change `routes/mobile.php:233` from `ability:ios:write` to
   a new `tokens:manage` ability. `OAuthController::scopeToAbilities` (line 290) only ever issues `ios:read`/`ios:write`,
   so no mobile session can hold it — the endpoint becomes unreachable from iOS immediately, with no route removal and
   no client coordination. This is precisely the containment the audit asks for.
4. **Close the two omitted-argument sites** — pass an explicit least-privilege array at `routes/api.php:69` and
   `api-tokens.blade.php:68`, validated against the same allowlist.
   `resources/views/livewire/bookmarks/index.blade.php:1559` (`['bookmark:write']`) is the in-repo model to copy.
5. **Tests**: rewrite `store_defaults_to_wildcard_when_no_abilities_given` to assert 422; add cases for `[]`, `['*']`,
   `['ios:*']`, an unknown ability, and non-subset delegation.

No migration.

**The incident half — shipped: revoke all now.** The code change stops new wildcard tokens being minted; every token
issued before it still carries `["*"]` and still satisfies every `tokenCan()` in the application, including the
non-delegable `ios:read`, `ios:write` and `mcp:read` the allowlist deliberately withholds. The Product Owner's decision
was to **revoke all of them**, so `tokens:revoke-wildcard` ships alongside: `--dry-run` prints the inventory by
provenance (id, name, owner, created, last used) so the blast radius can be read before anything is destroyed, and the
live run deletes each token together with its paired `oauth_refresh_tokens` row in one transaction — otherwise the
refresh token would simply mint a replacement. Same resumable, idempotent shape as
`integrations:encrypt-credentials`.

---

## PSEC-02a — Telemetry payload containment

**Validated: confirmed, including the token-leak path.**
`app/Http/Middleware/SentryMobileApiLogging.php` captures the complete query map (line 47), full JSON request bodies for
POST/PATCH/PUT (line 52), and — when the response has no `data` key and is under the 4 KB limit — the **entire response
body** (line 111). It redacts exactly two top-level keys, `apns_token` and `push_token` (line 16), request-side only.

The api-tokens response is `{"id":…,"name":…,"plaintext":"…"}`: no `data` key, well under 4 KB. **A freshly minted
Sanctum bearer token is written to Sentry in cleartext.** The oversized-payload branch (line 116) keeps top-level
scalars, so `plaintext` leaks there too.

Sentry hooks exist (`config/sentry.php:20-26` → `app/Support/helpers.php:511-580`) but `redact_sensitive_data` is
key-blind — it only rewrites one configured URL. A correct key-based scrubber, `sanitizeData()`
(`app/Support/helpers.php:433`), already exists with the right key list and **is wired to nothing**.

### Minimum viable change

1. **Invert `SentryMobileApiLogging` to an allowlist.** Keep `route`, `method`, `response_status`,
   `response_size_bytes`, duration. Delete `query`, `request_summary` and the response-body branches outright. Retain
   safe enumerations (the existing `sample_count`) via an explicit per-route const.
2. **Wire the scrubber that already exists**: call `sanitizeData()` from `redact_sentry_log`/`redact_sentry_event` as
   defence in depth, and add `plaintext` to its `$sensitiveKeys` list (line 438).
3. **Replace the tests that pin the leak.** `SentryMobileApiLoggingTest.php:131`
   (`query_parameters_are_captured_in_log_context`) currently asserts the defect. Replace with canary tests: post a
   note / mint a token containing a unique sentinel string, assert it appears nowhere in captured log context.

No migration. **Operational follow-up:** determine the Sentry exposure window, revoke anything found, and record the
retention/deletion response — again the user's call, not code.

---

### Historical Sentry events — **accepted risk, no action**

The rewrite stops new leakage; it does nothing about events already in Sentry, which include query maps, request
bodies and response bodies captured before it. The Product Owner's decision is to **accept the exposure and move on**:
the events are being left in place, un-deleted, with no user notification.

Recorded here in the same voice ADRs 0003 and 0004 use for the deferred DB-level tenancy hardening. Naming an accepted
risk beats leaving it undocumented, and the deliberate decision is what distinguishes it from an oversight. Anyone
revisiting it should know the exposure window is bounded by the deploy of `SentryMobileApiLogging`'s allowlist rewrite,
and that the leaked material is mobile API request/response content for whoever used the app in that window.

---

## PSEC-02b — Tenant-seal shipped read/write paths

**Validated per component.** No policy layer and no global scopes exist on `Event`/`Block`/`EventObject`, so every fix
is an explicit call-site change. Four _correct_ patterns already exist in-repo and should be copied rather than
replaced: `app/Traits/AuthorizesOwnership.php`, `app/Services/Mobile/SearchDispatcher.php` (correct in all five modes),
`TagsController::tagQuery()`, and `MetricIdentifierMap::resolve()`. `TaskExecution::scopeForUser()`
(`app/Models/TaskExecution.php:51`) exists and sits unused beside the code that needs it.

### APO-03 — admin mutations (confirmed, broader than recorded)

Listings are scoped; mutations are not. Unscoped `whereIn` deletes in
`resources/views/livewire/admin/{events:91,events:94,objects:84,blocks:85,relationships:94}.blade.php`, all driven by a
public Livewire `$selected*` array. `bin.blade.php:310` resolves IDs through an unscoped `findDeletedItem()` feeding
both `bulkRestore` and `bulkDelete`, and cascades to an unscoped **hard** delete at lines 181-197.
`task-pipeline-overview.blade.php` is unscoped in six read properties _and_ at `:358`/`:363` (`TaskExecution::find`) and
`:377` (`Event::find`), the last feeding a `ProcessTaskPipelineJob` write against another user's event. Not in the
audit: `pending-links.blade.php` (4 unscoped `Relationship` mutations) and `search.blade.php:88` (every user's raw
search strings). `sense-check.blade.php` contains no ownership reference at all and needs its own pass — see below, it was the last
gap closed.

**MVC:** re-resolve selected IDs through the component's own already-scoped listing query inside the mutation — add the
`user_id` predicate (or the `integration.user_id` join for `Event`/`Block`) to each `whereIn`, and swap
`TaskExecution::find` for the existing `scopeForUser`. This keeps admin tenant-local, which is ADR 0003's stated policy
and the roadmap's own recommendation, and defers the separate global-operator role entirely.

### APO-06 — the sense-check page (confirmed, shipped last)

Eighteen unscoped read queries: three orphan hunts, five integration-validity checks, six embedding-coverage counts and
four type-catalogue aggregates. Read-only, routed at `routes/web.php:331`, and behind the same `admin` middleware every
sibling page uses — which gates power-user tooling, not cross-tenant access.

Two of the checks needed more than a predicate, and the reason is worth recording because it is not obvious from the
call site. `Event::integration()` (`Event.php:140`) and `Block::event()` (`Block.php:246`) are both `->withTrashed()`
relations, so "orphaned" does not mean the parent is in the bin — it means the parent row is **gone**, taking its
`user_id` with it:

- An orphaned **event** is attributed through its actor/target objects, which carry `user_id` directly (`objects.user_id`)
  and outlive the integration.
- An orphaned **block** carries no `user_id` and no foreign key but `event_id`, so a dangling one cannot be attributed
  to anybody. It is now shown to **nobody** rather than to everybody. A cross-tenant integrity sweep belongs in an
  artisan command run by an operator, not in a per-user page.

Both states are in fact unreachable behind the current `RESTRICT` foreign keys (`events.integration_id` is `NOT NULL`;
`blocks.event_id` is nullable but constrained), so the regression tests reproduce them by dropping the constraint inside
the test transaction — the referential corruption those checks exist to find.

The integrations check also carried a latent OR-precedence bug — `whereNull(...)->orWhereDoesntHave(...)` — that the new
owner predicate would have escaped, so its two conditions are now grouped.

### CC-01 — Spotlight lexical search (confirmed)

Eight unscoped query classes registered at `app/Providers/SpotlightServiceProvider.php:314-318`:
`EventSearchQuery:23`, `ObjectSearchQuery:23`, `BlockSearchQuery:23`, `TagSearchQuery:18`, `MetricSearchQuery:17`,
`MetricTrendsQuery:19`, `FinancialAccountSearchQuery:22` (leaks other users' account names _and_ balances),
`IntegrationEventsQuery:19`. Semantic search, mobile and the legacy `/api` search routes are all correctly scoped —
`SemanticSearchQuery.php:58,67` is the in-file reference implementation. Exposure is palette metadata: the `jump_to`
targets do enforce `authorizeOwner`.

**MVC:** add the user predicate to each of the eight, copying `SemanticSearchQuery`.

### ENR-01 — receipt matching (confirmed, both directions)

`app/Integrations/Receipt/ReceiptTransactionMatcher.php:50` selects candidate transactions with no user filter — while
the very next method reads `$receipt->integration->user_id` (line 111) to stamp the resulting `Relationship`. Reverse
direction: `app/Jobs/TaskPipeline/Tasks/FindReceiptForTransactionTask.php:27`, duplicated verbatim in the legacy
`app/Jobs/Data/Receipt/FindReceiptForTransactionJob.php:43`.

**MVC:** scope both candidate queries by the owning user via the integration join, using the `user_id` already resolved
a few lines away.

### EOB-01 — tags (partially fixed)

Web only. `resources/views/livewire/tags/index.blade.php:70` joins `tags`→`taggables` with no join to
`events`/`objects` and no user predicate, so it lists every tag in the installation with **counts aggregated across all
tenants**. `tags/show.blade.php:17` (`Tag::findOrFail($id)`) leaks the tag entity, though both content listings beneath
it are correctly scoped.

**MVC:** port the ownership-derivation from `TagsController::tagQuery()` (line 216) to the web catalogue; add an
existence gate to `show`.

### EX-01 — metric series

**Already fixed. Strike from scope.** Its residual is the two Spotlight metric queries, covered under CC-01.

### Regression coverage

One two-tenant test per fixed call site. This is where APO-05 bites: **write them with `#[Test]`**, because `@test`
alone does not run under PHPUnit 13.1.14.

---

## PSEC-03 — Remove the authenticated global cache flush

**Validated: confirmed, unchanged.** `routes/api.php:129-143`. The route computes
`$pattern = "card_stream_{$userId}_*"`, never reads it, and calls `Cache::flush()`. Its only protection is the
enclosing `auth:sanctum` group at line 50 — **no ability check**, so any token reaches it. The default store is Redis
on its own DB (`config/cache.php:18`, `config/database.php` `REDIS_CACHE_DB=1`), making this a whole-application
FLUSHDB any authenticated user can trigger. It is the only user-reachable `Cache::flush()` in the repo; the other three
are test `setUp`.

### Minimum viable change

**Delete the route.** This is genuinely minimal rather than lazy: the keys it nominally targets are built with a
trailing `uniqid()` (`resources/views/livewire/card-streams.blade.php:164`), so they were never enumerable — which is
why the author reached for `flush()` — and they are already correctly released by `Cache::forget()` at line 177. The
endpoint has no working function to preserve. No replacement service is needed; if one is ever wanted, the per-user
key-prefix pattern at `app/Livewire/Media/Index.php:288` is the model.

Add a test asserting the route is gone, plus a two-user cache-isolation test.

No migration.

---

## PSEC-08 — Connector credential encryption

**Validated: confirmed.** `app/Models/IntegrationGroup.php:34-41` casts `auth_metadata` as plain `array` and does not
cast `access_token`, `refresh_token` or `webhook_secret` at all. `Integration::configuration` (line 38-46) is plain
`array` and still carries secrets — `HevyPlugin.php:554,913` reads `configuration['api_key']`, despite the Hevy schema
telling users the key is "stored encrypted". `auth_metadata` holds API keys for Immich (`ImmichPlugin.php:254`) and
Goodreads (`GoodreadsPlugin.php:238`). No migration has ever touched these columns.

The codebase already knows how: `app/Models/LiveActivityToken.php:42` uses `'push_token' => 'encrypted'`, and
`Crypt::encryptString` / `encrypt()` appear across eight OAuth plugins.

### Two decisive facts that shape the MVC

1. **The three dedicated secret columns are `text`** (`2025_07_27_142700_create_integration_groups_table.php:17-19`)
   and appear in **zero** `where()` clauses repo-wide. Adding an `encrypted` cast needs **no migration and breaks no
   query** — ciphertext is longer, and `text` is unbounded.
2. **`auth_metadata` and `configuration` are `jsonb`, and are queried and written through SQL JSON paths** —
   `IntegrationController.php:145` does `->where('auth_metadata->gocardless_reference', $ref)`, and ten sites across
   `StartIntegrationMigration.php` / `OutlineMigrationPull.php` write `'configuration->migration_status' => …`.
   Whole-column encryption would write a non-JSON string into a `jsonb` column — Postgres rejects it — and would
   therefore require a **column-type migration plus a refactor of ~67 call sites**.

### Minimum viable change — phase 1 (proposed now, no migration)

1. Add `'access_token' => 'encrypted'`, `'refresh_token' => 'encrypted'`, `'webhook_secret' => 'encrypted'` to
   `IntegrationGroup::$casts`.
2. Add a **resumable backfill artisan command** that reads raw values (`getRawOriginal`), skips anything that already
   decrypts, and re-saves through the cast. A command, not a migration — it is re-runnable, interruptible, and
   reversible, none of which a migration gives you here.
3. **Fix the activity-log leak this exposes.** `IntegrationGroup` uses `LogsActivity` with `->logFillable()`
   (line 114), and `dontLogIfAttributesChangedOnly([...])` (line 117) only suppresses the log when _nothing else_
   changed — it does not redact. Because Spatie reads attributes through casts, adding the encrypted cast would log the
   _decrypted_ value. Add `->logExcept(['access_token','refresh_token','webhook_secret','auth_metadata'])`.
   **This is a new finding the audit did not record**, and existing `activity_log` rows already hold plaintext
   credentials — a data-remediation item for the user to decide.
4. Correct the false "stored encrypted" copy in `HevyPlugin::getConfigurationSchema()`.
5. Tests: cast round-trip, backfill idempotency on mixed plaintext/ciphertext, redaction of API/serialisation/activity
   -log output.

### Phase 2 — **shipped: leaf-value cast, no migration**

The Product Owner chose the cast over a column-type migration, consistent with the caution instruction.
`App\Casts\EncryptedJsonSecrets` encrypts only the secret leaves of `auth_metadata` and `configuration`, leaving the
JSON structurally valid so all eleven SQL JSON paths keep working unchanged.

The "~67 call sites" estimate in the phase 1 write-up was wrong, and worth correcting: **a cast is transparent, so no
call site changed at all.** The plugins' array reads and writes are untouched. What the estimate should have counted
was the risk that a JSON path query hits an encrypted leaf — and every one of the eleven was checked
(`auth_metadata->gocardless_reference`, ten `configuration->migration_*` writes). None touches a secret key.

Two rules, both deliberately narrower than `sensitive_log_keys()` — that list is tuned for logging, where
over-redaction is free, and includes `key`, `auth` and `server_url`, which carry ordinary configuration here:

- **Secret leaf keys**, encrypted individually: `access_token`, `api_key`, `api_token`, `client_secret`, `password`,
  `refresh_token`, `secret`, `token`, `webhook_secret`.
- **Secret subtrees**, every leaf encrypted whatever its own key is called: `cookies`. Fetch stores per-domain session
  cookies at `auth_metadata.domains.{domain}.cookies` as arbitrary name/value pairs, which name matching cannot reach.
  The phase 1 write-up missed these entirely.

Reads fall back to the raw value when decryption fails, so rows the backfill has not reached still read correctly.
There is no flag day and no deploy/backfill ordering requirement. `integrations:encrypt-credentials` now covers both
the `text` columns and the `jsonb` leaves in two passes.

Verified against the integration, migration and fetch suites: an **identical failure set with and without the cast**
(20 pre-existing failures, no regressions).

### The activity-log remediation — **shipped: redact, do not delete**

Item 3 of phase 1 flagged that existing `activity_log` rows hold plaintext credentials, and left the retention call to
the Product Owner. The decision was to **redact the values in place** rather than delete the rows: the audit trail is
the point of the table, and every non-secret field in each diff survives.

`activity-log:redact-credentials` rewrites the rows already written. `App\Traits\RedactsLoggedProperties` stops new
ones, on both `IntegrationGroup` and `Integration` — the latter is a gap the phase 1 write-up did not spot, since
`Integration` logs `configuration` via `logFillable()` with no `logExcept`, so Hevy and Goodreads API keys kept landing
in the changelog. The trait taps each activity through the existing `sanitizeData()`, so only the secret leaves are
replaced and the rest of the diff is preserved — strictly better than widening `logExcept`, which would have thrown
away every legitimate settings change alongside the secret.

---

## The iOS branch situation

The audit read `1dc11161` = `origin/feature/mobile-api-implementation`, four commits ahead of `main` (`74f595a`,
itself a revert of `0179382` "Complete mobile API foundations and tags", −1148 lines).

**All four iOS findings are present identically on both.** `git diff 74f595a origin/feature/mobile-api-implementation`
is _empty_ for `AppModel.swift`, `AuthenticationService.swift`, `KeychainTokenStore.swift`, `Extensions/SparkShare/`,
`SparkApp.swift` and `Project.swift`. So the branch question does not change _what_ is broken — but it does change one
fix's cost, noted under PSEC-07.

CI is green on both (`ios.yml` runs #29 and #32). Relevant coverage gap: **`SparkShare` is never built by CI** — it is
not in the `SparkApp` test scheme — which is exactly why PSEC-06's defects survive a green pipeline.

---

## PSEC-04 — Complete logout and account-switch purge

**Validated: confirmed, and worse than recorded.** The whole logout path is `AppModel.signOut()`
(`SparkApp/Sources/App/AppModel.swift:255-267`).

Cleared: Keychain OAuth blob, ETag cache, `spark.userId`, `spark.apnsDeviceId`, in-memory `profile`, Reverb socket,
and the server device row (best effort).

**Not cleared:**

- **SwiftData** — never purged. The store lives in the App Group (`SparkDataStore.swift:8,16,24`), so all ten cached
  models survive into the next account. A correct wipe routine already exists but is trapped inside `#if DEBUG` at
  `Settings/DebugView.swift:476-484` and is never called from `signOut()`.
- **App Group defaults** — `spark.profile.name`, `spark.apnsToken`, `onboarding.*`, `health.upload.enabled`,
  `spark.background.mode`, `spark.env.name`, `spark.checkin.legacyCleared.v1`. `spark.profile.name` is re-read at init
  (`AppModel.swift:81-83`), so **the departing user's display name is restored into the next session.**
- **APNs** — no `unregisterForRemoteNotifications()` anywhere; no `removeAllDeliveredNotifications()`, so the old
  user's delivered notifications stay in Notification Center.
- **Core Spotlight** — `deleteAllSearchableItems()` exists (`SpotlightIndexer.swift:75-84`) but only on a lazy
  BG-processing path (`SparkApp.swift:161-166`). Until iOS schedules that task, the previous user's records stay
  searchable from the system.
- **Recents** — `spark.search.recents` in `UserDefaults.standard` (`Search/SearchView.swift:252-256`), never cleared.
- **Retry after offline revocation** — `AppModel.swift:257-259` discards the revoke result (`_ = try?`) and then
  removes `spark.apnsDeviceId` unconditionally on the next line. Offline logout **destroys the only identifier needed
  to retry**, and nothing is enqueued.

**Backend: there is no logout endpoint at all.** `routes/mobile.php` (302 lines) has no `logout` and no `oauth/revoke`;
`OAuthController` has only `authorize/approve/deny/token/refresh`. `routes/auth.php:35` is the _web session_ logout.
So **the Sanctum access and refresh tokens stay valid until natural expiry after every sign-out.** Only the push
subscription is revoked (`routes/mobile.php:161`).

### Minimum viable change

**Server (deployable today, no client release):** add `POST /api/v1/mobile/logout` under `ability:ios:read` that
deletes the current access token (`$request->user()->currentAccessToken()->delete()`) and its paired refresh token.
This is the single highest-value part of PSEC-04 and it is small.

**Client:** one idempotent `purge()` coordinator called by `signOut()`, which (a) lifts the existing wipe from
`DebugView.swift:476-484` out of `#if DEBUG` into `SparkDataStore` and calls it, (b) removes the enumerated defaults
keys plus `spark.search.recents`, (c) awaits `SpotlightIndexer` purge synchronously rather than deferring to BG,
(d) calls the new logout endpoint, and (e) **only** removes `spark.apnsDeviceId` on a successful revoke — otherwise
leaves a non-secret pending marker to retry.

No migration.

---

## PSEC-05 — Remove unsafe raw presentation

**Validated: confirmed, but the audit named the wrong mechanism.** There is **no** raw-dump fallback for unrecognised
card or block types — every `default:` branch degrades gracefully (`BlockDetailView.swift:58` → shimmer,
`TodayView.swift:251-256` → `EmptyState`, `FeedSection.swift:427` → placeholder). That part of the finding should be
struck.

What is real is an **always-on production raw-payload surface**, not `#if DEBUG` gated:

1. A **"Raw" toolbar button and sheet on every detail screen** — `Shared/SparkAppViewSystem.swift:436-440, 449-453`.
   `SparkRawPayloadView` renders the unfiltered HTTP response body as `Text` with a **Copy JSON** pasteboard button.
   `rawPayload` is `response.utf8Body`, set in six view models and attached on seven screens (event, object, block,
   metric, place, integration, knowledge).
2. **`RawFeedJSONView` inlined into Today** (`TodayView.swift:58-60`) with whole response bodies from four endpoints
   (`TodayViewModel.swift:112, 157, 186, 257`), and into three Explore screens
   (`MetricsExploreView:92`, `MoneyExploreView:118`, `HealthExploreView:118`).

### Minimum viable change

Wrap both surfaces in `#if DEBUG` — the toolbar button/sheet in `SparkAppViewSystem.swift` and the `RawFeedJSONView`
call sites — and stop populating `rawPayload` / `rawAPIEntries` in release builds so the payloads are never retained
in memory at all. `DebugView.swift:1` is the in-repo precedent. Add a lint/CI grep so it cannot be reintroduced.

**Web side is clean** — no raw fallback in block or card partials; the `<pre>{{ json_encode(...) }}` instances are
labelled, collapsed admin/detail affordances, not unrecognised-type fallbacks.

**Separate bug found, worth fixing while here:** `resources/views/livewire/objects/show.blade.php:1518-1521` is
malformed Blade (`!!…!!` instead of `{!! … !!}`, and `$this - > object - > metadata`), so it renders as literal text
and the adjacent "Copy JSON" button copies that literal string.

No migration.

---

## PSEC-06 — Make Share capture authenticated, durable and truthful

**Validated: confirmed on both branches — but the audit blamed the wrong layer.** The **entitlements are correctly
aligned**: every target declares `$(AppIdentifierPrefix)co.cronx.sparkapp` (`Project.swift:5-10,25` and all nine
`.entitlements` files). The audit's "misaligned access group" is not the problem.

The problem is `ShareViewController.syncAccessToken()`
(`Extensions/SparkShare/Sources/ShareViewController.swift:167-180`), which is wrong three separate ways:

1. `kSecAttrAccessGroup` is the **literal, unexpanded** string `"$(AppIdentifierPrefix)co.cronx.sparkapp"` — build
   variables expand in `.entitlements` plists, never in Swift string literals. No such group exists at runtime.
2. `kSecAttrService` is `"co.cronx.sparkapp.accessToken"`, but the app writes under `"co.cronx.sparkapp.oauth"` /
   account `"primary"` (`KeychainTokenStore.swift:32-34`). Wrong service, and no `kSecAttrAccount` at all.
3. Even on a hit, the stored value is a JSON `AuthTokens` blob, so it would be sent as
   `Bearer {"accessToken":…`.

Consequences: `scheduleBackgroundImageUpload` (lines 106-135) opens with
`guard let token = syncAccessToken() else { return }` and therefore **never creates the upload task at all** — while
`shareImage`/`shareImageData` (lines 86-104) have _already_ shown "Photo saved to Spark." and called `complete()`
unconditionally, before any network call. The JPEG is written to `group.co.cronx.sparkapp/ShareUploads/<uuid>.jpg` and
nothing ever picks it up. The telemetry event even reports `outcome: .success` before `resume()`, on the path that
never runs.

Endpoints: `POST /bookmarks` **exists and matches** (`routes/mobile.php:219-221`). `POST check-ins/media` **exists and
the shape matches** (`routes/mobile.php:201-203`) but is unreachable for the reason above. `POST /notes` **does not
exist** — `notes` appears nowhere in `routes/mobile.php` or `routes/api.php` — so text sharing 404s, though it at least
fails loudly.

### Minimum viable change

1. **Delete `syncAccessToken()` and use the `KeychainTokenStore()` path already present in the same file** (line 8,
   used correctly by `APIClient` at lines 71 and 141). One deletion removes all three bugs; the working path is
   already there.
2. **Move the success toast and `complete()` after the response**, and show a real failure state otherwise.
3. **Route text shares to the working `/bookmarks` endpoint** rather than the nonexistent `/notes` — smaller than
   adding a server route, and `/bookmarks` is already the registered capture capability.
4. **Add `SparkShare` to a CI-built scheme** so this class of defect stops passing green.

No migration.

---

## PSEC-07 — Restore the native notification contract

**Validated: confirmed on both branches, and materially worse than recorded — the contract is currently
unsatisfiable.**

### The 428

Four routes require `If-Match` (`routes/mobile.php:163, 167, 171, 207`); `RequireIfMatch.php:42-45` returns **428**
when it is absent. The client never sends it — `NotificationsEndpoint.swift:14-26` and
`NotificationsPreferencesEndpoint.swift:10-13` are bare, on both branches. So **every shipped inbox control (mark read,
mark all read, delete) and the preferences save returns 428.**

But the client could not comply even if it tried:

- `GET /notifications` returns **no per-notification version** (`CompactNotificationResource.php:21-29` emits only
  `id/title/body/domain/is_read/received_at/entity`; the controller sets only `Last-Modified`), and there is **no
  `GET /notifications/{id}`** route to fetch the strong ETag from. `if-match:notification` is unsatisfiable.
- For `if-match:user`, `MeController` emits no explicit ETag, so the `etag` middleware supplies a **weak**
  `W/"md5(body)"` (`ETag.php:27`) which can never equal `ResourceVersion`'s strong `sha256` (`ResourceVersion.php:22-25`)
  → 412 rather than success.
- `GET /settings/notifications` **does** return a usable strong user ETag (`NotificationPreferencesController.php:28-29`),
  so preferences is the one case a client could fix alone — except that on `main` the revert removed `headers` from
  `Endpoint` entirely, so `APIClient` has no mechanism to send `If-Match` at all. That plumbing exists on the feature
  branch. **This is the one place the branch split matters.**

Also: reads go to `NotificationPreferencesController@show`, writes to `NotificationSettingsController@update`, with
**different validation** — the write side adds `required_unless:delivery_mode,work_hours` rules the read side never
describes.

### The APNs vocabulary

`ApnsChannel.php:94-96` sets the category to `getNotificationType()`, producing eleven snake_case values
(`integration_failed`, `test_push`, `migration_completed`, …). The client registers exactly five SCREAMING_CASE
categories (`SparkApp.swift:197-226`): `ANOMALY`, `DIGEST`, `INTEGRATION_FAILED`, `NEW_BOOKMARK`, `CALENDAR_EVENT`.
**Zero overlap** — `UNNotificationCategory` identifiers are case-sensitive, so even `integration_failed` /
`INTEGRATION_FAILED` does not match. Every action (`ACKNOWLEDGE`, `VIEW`, `REAUTH`, `SNOOZE`) is therefore inert, and
`response.actionIdentifier` is never inspected anyway.

Deep links are broken independently: the server nests everything under `userInfo["spark"]["deep_link"]`
(`ApnsChannel.php:102-119`); the client reads a flat `userInfo["spark.url"]` (`SparkApp.swift:138-152`) that is never
emitted, then routes via `UIApplication.shared.open` rather than the in-app `pendingRoute`. **Tapping any notification
does nothing.** (The silent companion push _is_ aligned and works.)

A third vocabulary exists: `NotificationPreferencesController::CATEGORIES` is the lowercase form of the client's five,
but only `integration_failed` corresponds to a real notification class — so **four of the five preference toggles gate
nothing, and the eleven real notification types cannot be gated at all.**

### Minimum viable change

Everything here is **server-side, which means it ships without an App Store release** — the decisive practical point.

1. **Unblock the shipped controls.** Drop `if-match` from `notifications/read-all` and `notifications/{id}/read`
   (`routes/mobile.php:163,167`). These are idempotent state transitions where a lost update is meaningless, so the
   precondition buys nothing and currently costs the entire feature. Keep it on `DELETE /notifications/{id}`, and add
   `version` to `CompactNotificationResource` so a client can actually satisfy it.
2. **Fix `if-match:user`** by emitting the strong `ResourceVersion` ETag from `MeController`, so the header the
   middleware demands is the one the read path returns.
3. **Align the APNs vocabulary server-side** — map the eleven notification types onto the five category identifiers
   the shipped client already registers, in `ApnsChannel`. This makes actions live today rather than after a release.
4. **Reconcile the preference taxonomy** with the notification types that actually exist.
5. **Client (proposal only, needs a release):** read `userInfo["spark"]["deep_link"]` and route through
   `pendingRoute`; inspect `response.actionIdentifier`.

No migration. Item 1 is a **product decision** as much as a code change — see below.

---

---

## NOTIF-03 — The notification preference taxonomy

**Validated: confirmed, and it made four of five toggles decorative.**

The mobile API named five categories — `anomaly`, `digest`, `integration_failed`, `new_bookmark`, `calendar_event` —
in `NotificationPreferencesController::CATEGORIES` and `NotificationSettingsController::CATEGORIES`. Only
`integration_failed` corresponds to a notification Spark ever sends.

That is not merely cosmetic, because `SparkNotification::via()` gates delivery on
`hasPushNotificationsEnabledForType($this->getNotificationType())` — keyed by the **real** type string. So the four
invented toggles wrote `push_types` keys nothing ever reads, and three types that are sent
(`cookie_expiry_warning`, `fetch_content_changed`, `fetch_multiple_failures`) had no toggle at all. The web settings
page kept its own hand-maintained list, which had fallen the same three types behind.

The client side was worse than unhelpful: `NotificationPreferences.Category` in SparkKit carried the same five cases,
and its decoder drops unrecognised keys — so the moment the server returned the real types, the Settings screen would
have rendered an empty Categories section and saved an empty set.

### Minimum viable change — shipped

`App\Notifications\NotificationCatalogue` is the single source of truth, enumerated from the eleven classes that
declare `getNotificationType()`. Four consumers derive from it: both mobile settings controllers, the web settings
page, and `ApnsChannel`'s category map. The iOS client mirrors it in `NotificationPreferences.Category` and a new
`PushCategory` enum used directly in `registerNotificationCategories()`.

The APNs categories are regrouped so each one's action set is honest:

| Category                | Actions         | Types                                                        |
| ----------------------- | --------------- | ------------------------------------------------------------ |
| `INTEGRATION_ATTENTION` | Reconnect, View | `integration_authentication_failed`, `cookie_expiry_warning` |
| `INTEGRATION_STATUS`    | View            | the six sync/import outcomes                                 |
| `SYSTEM`                | View            | `data_export_ready`, `system_maintenance`, `test_push`       |

`ACKNOWLEDGE` and `SNOOZE` are withdrawn along with the categories that offered them.

Drift protection is the point, so it is tested from both ends: two backend contract tests walk `app/Notifications`
reflectively in both directions (no catalogue entry without a class, no class without an entry), and SparkKitTests —
the layer CI can actually run — asserts both client raw-value sets against the backend lists.

**Three existing test files encoded the invented taxonomy as intended behaviour** and were rewritten against the
catalogue rather than re-hand-listed. That is the third time in this piece of work that a test has been found
asserting the defect; it is worth treating as a pattern rather than three coincidences.

---

## What shipped in this change

`claude/spark-product-priorities-b49ppr` implements the backend work, from `f6562fd` through the six
decision-driven commits that closed it out. **No migration was introduced anywhere.**

| Item             | Landed                                                                                                                                                                                                                            |
| ---------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PSEC-01          | `SparkAbility::DELEGABLE` + `canDelegate()`; `ApiTokensController::store` rewritten; `tokens:manage` gate on `routes/mobile.php`; explicit abilities at `routes/api.php` and the web settings form (now with a capability picker) |
| PSEC-02a         | `SentryMobileApiLogging` inverted to a metadata allowlist; `sensitive_log_keys()` extracted and wired into every Sentry hook; `plaintext` added to the key list                                                                   |
| PSEC-02b         | Ownership re-resolved at 20+ call sites across admin, Spotlight, receipts and tags; `OwnedTagQuery` extracted and shared with the mobile controller                                                                               |
| PSEC-03          | `POST /api/clear-card-cache` deleted                                                                                                                                                                                              |
| PSEC-04 (server) | `POST /api/v1/mobile/logout`                                                                                                                                                                                                      |
| PSEC-05 (web)    | malformed Blade in `objects/show.blade.php` repaired                                                                                                                                                                              |
| PSEC-07 (server) | If-Match dropped from the two idempotent notification routes; `version` on `CompactNotificationResource`; strong ETag from `MeController`; `ApnsChannel::CLIENT_CATEGORIES`                                                       |
| PSEC-08 phase 1  | three `encrypted` casts; `integrations:encrypt-credentials` backfill command; `logExcept()`; Hevy copy corrected                                                                                                                  |
| PSEC-01 sweep    | `tokens:revoke-wildcard` — inventory then revoke, access token and paired refresh token together                                                                                                                                  |
| PSEC-02b final   | `sense-check.blade.php` scoped, all 18 queries; orphan checks attributed through the surviving ownership path                                                                                                                     |
| PSEC-08 phase 2  | `EncryptedJsonSecrets` leaf-value cast on `auth_metadata` and `configuration`; backfill extended to both jsonb columns                                                                                                            |
| activity_log     | `RedactsLoggedProperties` on both models; `activity-log:redact-credentials` rewrites historical rows in place                                                                                                                     |
| NOTIF-03         | `NotificationCatalogue` as the single source of truth for both mobile controllers, the web settings page, `ApnsChannel`, and the iOS client's category registrations                                                              |

Fifteen test files added or rewritten, all using `#[Test]`.

### Deliberate behaviour changes worth knowing about

- **The legacy `POST /api/tokens/create` now requires an `abilities` array.** Existing callers sending only
  `token_name` receive 422. That is the containment: the endpoint's whole defect was minting `['*']` on request.
- **The web token form now requires selecting capabilities.** There is no "just give me a token" path any more.
- **`mcp:read` is not delegable.** It remains accepted for existing tokens as a legacy alias, but new tokens must
  name the capability they need.
- **Marking notifications read no longer takes a precondition.** See the decision list below — this is a contract
  change.
- **`GET`/`PATCH /settings/notifications` no longer offers `anomaly`, `digest`, `new_bookmark` or `calendar_event`,
  and now offers ten real types instead.** Breaking for any client that hard-codes the old set; `mobile_API.md` and
  the OpenAPI spec are updated, and the spec's `NotificationType` enum was verified against
  `NotificationCatalogue::configurableTypes()` rather than transcribed.
- **APNs categories are now `INTEGRATION_ATTENTION`, `INTEGRATION_STATUS` and `SYSTEM`.** A client that has not
  shipped the matching `UNNotificationCategory` registrations shows no action buttons — the same failure mode as
  before, so no worse, but the client change should ship together with this.
- **Three commands can touch production and none runs itself.** All take `--dry-run`; see the rollout order under
  Verification.

## Not implemented — proposal only

**iOS.** No Swift change was committed: this container has no Swift toolchain, so nothing could be compiled or
tested, and committing unverified Swift would be worse than leaving it. Each item above carries exact file and line
targets. Note `SparkShare` is absent from the `SparkApp` test scheme, so CI would not catch a regression in
PSEC-06 either — adding it is part of that fix.

**Decisions taken — all six are now closed:**

| #   | Question                                | Decision                             | Outcome                                                       |
| --- | --------------------------------------- | ------------------------------------ | ------------------------------------------------------------- |
| 1   | Wildcard-token revocation               | **Revoke all now**                   | `tokens:revoke-wildcard` — see PSEC-01                        |
| 2   | Sentry exposure response                | **Accept and move on**               | Recorded as an accepted risk under PSEC-02a                   |
| 3   | Plaintext credentials in `activity_log` | **Redact the values, keep the rows** | `RedactsLoggedProperties` + `activity-log:redact-credentials` |
| 4   | PSEC-08 phase 2                         | **Leaf-value cast, no migration**    | `EncryptedJsonSecrets` — see PSEC-08                          |
| 5   | Notification preference taxonomy        | **Rebuild from the real types**      | `NotificationCatalogue` — see NOTIF-03                        |
| 6   | `sense-check.blade.php`                 | **Scope to the signed-in user**      | See APO-06 under PSEC-02b                                     |

**Still standing, unchanged:**

- **Notification preconditions** — `if-match` was dropped from `read-all` and `{id}/read` because they are idempotent
  and the precondition cost the entire feature for no lost-update protection. That is a contract change and is
  reversible if you disagree.
- **Admin authority** — tenant-local (implemented, and what ADR 0003 says) versus a distinct audited global-operator
  role. The sense-check fix assumes tenant-local, consistent with every sibling page.
- **`DailyDigestReady` has no APNs path.** It is a real notification but declares no `getNotificationType()` and
  delivers over `WebPushChannel` only, so the client's old `DIGEST` category had no producer and has been withdrawn
  rather than kept hollow. Giving the digest a type string and an APNs path is a feature, not containment, so it is
  flagged here rather than folded in.

**Adjacent, and it gates the evidence for all of the above:** APO-05. **767 test methods across 90 files do not
run** under PHPUnit 13.1.14 because they carry only `@test` docblocks, and CI still cannot fail on a crash or a
zero-test run. Until that is fixed, "the regression tests pass" means less than it should.

## Verification

**The suite has been run.** `composer install` initially failed — `wire-elements/pro` is a licensed package served
from a private repository with no `auth.json` present — so a minimal local stub of the nine classes Spark actually
uses (plus the icon shims `config/wire-elements-pro.php` references) was built in a scratch directory,
`composer.lock`'s `dist` pointed at it, and Postgres 16 installed with PostGIS and pgvector.
**`composer.json` and `composer.lock` are unmodified in git** — the stub never enters the repository.

Results: **1805 tests, 8 errors, 34 failures — the same error and failure counts as `dev`, with 57 more tests
passing.** The failure _set_ is identical: no regressions. All 73 assertions across the ten containment test files
pass, and three canary checks (Spotlight scoping, admin bulk delete, telemetry token leak) confirmed those tests
go red against the pre-fix code. `duster fix` is clean.

Two caveats from the stub: `Unable to find component: [spotlight-pro]` fails on both branches, and the Spotlight
assertions exercise a stubbed palette API — the Eloquent queries under test are real, but the palette wiring is
not. Re-run under Sail with the real package before treating those two as settled.

Twelve pre-existing tests needed updating because they encoded the old behaviour; see the commit for detail. The
`TaskPipelineAdminTest` group is the notable one — its factory gave each execution a different owner from the
acting admin, so every assertion depended on the console showing another tenant's rows.

The commands below are what to run in Sail.

**Backend** — per CLAUDE.md, everything through Sail, minimum tests by filter:

```bash
vendor/bin/sail up -d && vendor/bin/sail composer install
vendor/bin/sail artisan test --filter=ApiTokensController      # PSEC-01
vendor/bin/sail artisan test --filter=SentryMobileApiLogging   # PSEC-02a
vendor/bin/sail artisan test tests/Feature/Admin               # PSEC-02b admin
vendor/bin/sail artisan test --filter=Spotlight                # PSEC-02b search
vendor/bin/sail artisan test --filter=Receipt                  # PSEC-02b receipts
vendor/bin/sail artisan test --filter=Notification             # PSEC-07
vendor/bin/sail artisan test --filter=IntegrationGroupCredentialEncryption   # PSEC-08 phase 1
vendor/bin/sail artisan test --filter=EncryptedJsonSecrets      # PSEC-08 phase 2
vendor/bin/sail artisan test --filter=RevokeWildcardTokens      # PSEC-01 sweep
vendor/bin/sail artisan test --filter=RedactActivityLogCredentials  # activity log
vendor/bin/sail artisan test --filter=SenseCheckTenancy         # PSEC-02b final gap
vendor/bin/sail artisan test --filter=NotificationContract      # NOTIF-03
vendor/bin/sail artisan test --filter=Logout                   # PSEC-04
vendor/bin/sail artisan test tests/Feature/Tags                # PSEC-02b tags
vendor/bin/sail bin duster fix                                 # required before finalising
```

New tests must use `#[Test]`, never `@test` — see the APO-05 note above. Then offer the full suite once targeted runs
pass.

**Manual checks the suite cannot make:**

- `POST /api/v1/mobile/api-tokens` with a real iOS OAuth session returns 403, not a wildcard token.
- The api-tokens `plaintext` appears nowhere in the `sentry_logs` channel output.
- `POST /api/clear-card-cache` returns 404/405; user A's flush no longer evicts user B's cache.
- After `POST /api/v1/mobile/logout`, the old bearer token returns 401.
- After the backfill, `SELECT access_token FROM integration_groups LIMIT 1` shows ciphertext **and** the integration
  still authenticates against its provider. Run against a staging copy first — this rewrites live credentials.
- Two-user pass on the Spotlight palette and the web tag catalogue.
- A real-device push whose category now matches: the action buttons appear — `INTEGRATION_ATTENTION` shows Reconnect,
  `INTEGRATION_STATUS` and `SYSTEM` show View.
- `php artisan tokens:revoke-wildcard --dry-run` against production, read the inventory, then run it for real. Every
  client holding a `*` token must then re-authenticate with explicit abilities from `SparkAbility::DELEGABLE`.
- Two-user pass on the admin sense-check page.

**Rollout order.** The commands are deliberately separate from the code, so production is only touched when you choose:

1. `integrations:encrypt-credentials` — safe at any time; the cast reads un-backfilled rows transparently.
2. `activity-log:redact-credentials` — rewrites historical audit rows in place.
3. `tokens:revoke-wildcard` — last, because it forces every client to re-authenticate.

All three take `--dry-run`, and all three are idempotent and resumable.

**iOS** — not verifiable here. Needs Xcode 27 + `tuist generate`, `swift test` in `Packages/SparkKit`, and
`xcodebuild ... test` on the `SparkApp` scheme.

**Branches:** `claude/spark-product-priorities-b49ppr` in both repos. CLAUDE.md requires gitmoji commit subjects (CI
derives version bumps from them); the `spark` branch is correctly off `dev`.
