# Quick log intents (contract v1)

The CMD tab sends one line of text. The app (PCC, on device) guesses what it is for; the server applies the rules that
must not depend on a model, enforces the "ask before saving" threshold, and writes only what the user settled on.
No migrations: a note for Flint is an existing `flint_note` object.

Both endpoints sit under `/api/v1/mobile`, need `data:write`, and are covered by the mobile API feature flag.

## Intents

| `intent` | Meaning | Written by the server? |
|----------|---------|------------------------|
| `log` | Something that happened. Structured drafts (wine, beer, TV) go to `POST /log`; any other line goes there as `kind: reflection` | No. The client calls `POST /log` |
| `flint_note` | A note for Flint | Yes, on commit |
| `command` | An action from the registry | No. The client runs the action |
| `search` | Open search | No |
| `unsure` | Not decided. Classify only; never committed | No |

## Rules the server decides

1. `@flint` anywhere in the text is always `flint_note` (confidence 1). It is never matched as a person. The note body is the text without the handle.
2. A leading `/` is `command` and a leading `?` is `search` (confidence 1). The prefix is stripped from `text`.
3. A note for Flint is never inferred from a hint: without `@flint` a `flint_note` hint becomes `unsure`.
4. Otherwise the client `hint` is accepted only at or above `ios.quick_log.confidence_threshold` (default 0.7, env `IOS_QUICK_LOG_CONFIDENCE_THRESHOLD`). Below it, or with no hint, the result is `unsure` and `needs_choice` is true. Nothing is saved until the user picks.
5. Empty text, or a bare prefix or `@flint` with nothing after it, is `unsure`.

## `POST /log/intents/classify`

Writes nothing. Safe to call on every pause in typing.

```json
{ "text": "coffee with @dan", "hint": { "intent": "log", "confidence": 0.92 } }
```

`hint` is optional. Response:

```json
{
  "intent": "log",
  "confidence": 0.92,
  "needs_choice": false,
  "reason": "client_hint",
  "candidates": [],
  "text": "coffee with @dan",
  "people": [{ "name": "dan", "state": "likely", "matches": [{ "id": "…", "name": "Daniel" }] }]
}
```

- `reason`: `flint_handle`, `prefix`, `client_hint`, `low_confidence`, `no_hint`, `empty`, `empty_flint_note`, `empty_prefixed`.
- `candidates`: only when `needs_choice` is true. Intents to offer, the client's guess first.
- `text`: what to act on (handle or prefix removed).
- `people`: `PersonMatcher` result for each `@mention` except reserved handles (`existing`, `likely`, `ambiguous`, `new`). Partial names match (`@dan` reaches Daniel).

## `POST /log/intents`

Commits the intent the user settled on.

```json
{
  "idempotency_key": "2f1c…",
  "text": "@flint stop asking about sleep",
  "intent": "flint_note",
  "suggested_intent": "flint_note",
  "confidence": 1,
  "occurred_at": "2026-10-24T11:30:00Z"
}
```

- `idempotency_key` (required, same charset as `POST /log`) and `occurred_at` (required, not in the future) must be identical on a retry. The offline queue stamps both when the line is typed.
- `suggested_intent` is what `classify` returned (`unsure` or omitted when the user picked from the choice).
- `@flint` in the text with any `intent` other than `flint_note` is a 422.
- `flint_note` also needs `flint:write`; without it the response is 403 and nothing is saved.

Responses:

- `flint_note`: `201` first time, `200` on replay, `{ "status": "saved", "intent": "flint_note", "created": true, "note": { …same payload as GET /flint/notes… } }`.
- `log`, `command`, `search`: `200 { "status": "client_action", "intent": "…" }`. Nothing is written; the client carries on (for `log`, `POST /log` with its structured draft).

## Telemetry

Every commit (a replayed note excepted) logs `quick_log.intent` with: `suggested`, `chosen`, `source` (`accepted`, `override`, `picked`), `overridden`, a confidence bucket (0.1 steps), a text length bucket (20 characters) and the mention count. The typed text is never logged. Override rate is the quality metric.

## Generic lines: `kind: reflection`

A `log` line that PCC cannot turn into wine, beer, TV, cinema or a board game is sent to `POST /log` as a reflection:

```json
{ "kind": "reflection", "idempotency_key": "…", "body": "coffee with Dan, good chat about the move", "with": ["Dan"], "occurred_at": "…" }
```

It creates a `reflection` document object (the text is its content) and a `logged_reflection` event ("Logged a Reflection", domain `knowledge`). `title` is optional and defaults to the first line, cut to 80 characters. `body` is required (max 10000) and only valid for this kind. `with`, `occurred_at`, `idempotency_key` and `dry_run` behave as for the other kinds. Nothing is queued for enrichment.

## Decisions

- `@flint` is reserved and cannot be overridden to another intent.
- The confidence threshold is 0.7 (70%). Lower-confidence input requires a choice before saving.

## Linking people on `POST /log`

`with` names people exactly (or creates them). To link people `classify` already matched (`@dan` is Daniel), send their ids as `with_ids` (UUIDs, max 10). Ids that are not the user's people are ignored, and an id is never duplicated by a matching name in `with`. Send `with` only for people nobody matched.
