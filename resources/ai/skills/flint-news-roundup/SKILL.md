---
name: flint-news-roundup
description: >
  Takes the three stories out of Will's own newsletter and fetch sources that
  are actually worth his attention, and writes each one up properly — what
  happened, where his sources disagree, and what to watch next. Deeper on the
  news than the day briefing has room to be. Maintains tactical Spark Topics for
  the stories still unfolding.

  Use this skill ONLY when invoked by the Flint news Routine (webhook payload
  with `routine: "news_roundup"`). For conversational news questions — "what
  did my newsletters say?" — use `spark-day-briefing`.
model: reasoning
allowed_tools:
  - spark__get-day-summary-tool
  - spark__get-latest-flint-digest
  - spark__get-event-tool
  - spark__get-block-tool
  - karakeep__get-bookmark-content
  - spark__create-flint-digest
  - spark__manage-flint-topic
required_success_tools:
  - spark__create-flint-digest
max_tool_calls: 60
timeout_seconds: 600
---

# Flint News Roundup — Routine Skill

Runs once a day in the morning. Reads the newsletters and fetch sources that
arrived since the last run and writes up **three stories worth caring about**,
in enough depth to be worth reading on their own.

This is not the day briefing, and it is not a shorter version of it. The day
briefing's news section answers *"what happened in the world, briefly"* in a
paragraph. This answers a different question: **of everything Will's sources
carried, which three matter, and what is actually going on with them.** If this
run produces something the morning digest could have said in one line, it has
failed.

It does not cover health, money, calendar or tasks, and it does not tell Will
what to do.

**Everything comes from Will's own sources.** Spark holds the newsletters and
fetches; that is the whole evidence base. Do not browse for material, do not
reach for the open web, and do not supplement from memory — including for
background you are confident about. A story you cannot support from his sources
is a story you do not run.

---

# RUN

## Step 1: Read the payload

The trigger sends this as an extra turn on the run:

```json
{ "user_id": "...", "routine": "news_roundup", "local_date": "YYYY-MM-DD", "timezone": "Europe/London", "period": "morning", "idempotency_key": "...", "run_token": "<opaque>" }
```

Use `local_date` and `timezone` as given. If — and only if — no payload arrived,
say so in the editorial note in Step 6 and fall back to the current date in
Europe/London; do not silently paper over it.

## Step 2: Load the sources

```text
spark__get-day-summary-tool(dates: ["<local_date - 1d>", "<local_date>"], domains: ["knowledge"])
```

This is the whole load. It returns, per date, a `knowledge` section holding
`newsletters[]`, `fetched_content[]` and `bookmarks[]` — each already carrying
`tldr`, `summary`, `key_takeaways` and an `event_id` — plus a `sync_status`
block with per-service `event_count` and `last_event_time`.

Two days rather than one, because the roundup runs in the morning and a source
that landed yesterday afternoon has not been covered by any previous run.

**Work from these summaries.** They are what the depth in Step 5 is built from,
and they are the reason this fits in one call. Do not load raw events to get
started.

**Opening an original.** `spark__get-event-tool(id: "<event_id>")` returns the
full cleaned text of a source — tens of thousands of characters for a long
newsletter, enough to crowd out the rest of the run. Use it **at most twice**,
and only when a story's framing genuinely turns on the original wording rather
than on what the summary says about it. `spark__get-block-tool` fetches a single
block from an event you have already opened.

`karakeep__get-bookmark-content` reads the captured content of something Will
saved, when a story rests on it.

## Step 3: Establish coverage from the data, not from impression

Read the numbers before forming any view of whether it was a quiet news day.

| What you observe | What it means | What to do |
|---|---|---|
| The call errors, or returns no `knowledge` section | **Flint is broken, not the news** | Stop. Go to Step 6 and report the failure, naming the tool and the error. Never describe this as a quiet day. |
| `newsletters`, `fetched_content` and `bookmarks` all empty, and `sync_status` shows zero `newsletter` and `fetch` events | A genuinely empty window | Go to Step 6 and say so in one line. |
| Sources present | Normal | Continue. |
| Some present, but a service's `last_event_time` is well before the window's end | Partial coverage | Continue, and name the gap in the roundup. |

Record the counts you actually saw — they go into the editorial note in Step 6.

**A failed load must never render as "nothing happened."** The two are
indistinguishable in the finished prose and only one of them is your fault, so
the distinction has to be made here, from the data, while you can still see it.

## Step 4: Check what is already being tracked

```text
spark__manage-flint-topic(operation: "list", status: "active")
spark__get-latest-flint-digest(date: "<local_date - 1d>")
```

The Topics list says which threads are live. Yesterday's digest says what has
already been told to Will — a story you covered yesterday needs *what moved*,
not a reintroduction.

## Step 5: Choose three stories, and go deep

Group what arrived by what it is actually about. Five newsletters covering the
same story are one candidate; one newsletter covering four things is four.

**Rank the candidates** by these, strongest first:

1. **His sources disagree about it.** Two of his publications reaching different
   conclusions from the same events is the richest thing available and the thing
   the day briefing structurally cannot fit. Rank it first whenever it is real.
2. **It moves a story Flint already tracks** as a Topic.
3. **Several of his sources independently thought it mattered** — coverage
   across the feed is genuine signal about significance within it.
4. **It is a real development in a field he works in or cares about.**
5. **It bears on something he is planning or deciding.**

Take the top three. **Aim for three every run** — this is a selection job, not a
survival test, and the interesting judgement is *which three*, not *how many
clear the bar*. Do not pad a third slot with something that only got in because
it was a lead item, was dramatic, or filled space; if the day genuinely only
carried two stories worth reading, run two and say why in one line. Fewer than
two means saying plainly that the day was thin, not stretching what there was.

**Write each of the three like this.** Roughly 150–250 words each — long enough
to be a real read, short enough to be read.

- **What happened**, in plain terms, assuming he has not read any of it.
- **Who said what.** Attribute by publication. Where his sources diverge, say so
  explicitly and say what the disagreement is actually about — different
  evidence, different time horizon, or different politics. This is the section
  that earns the skill its existence; do not flatten four sources into one
  consensus voice.
- **What is new** since yesterday's roundup, if it ran, or since the story last
  appeared.
- **Why it matters to him**, if it does. A story can be worth knowing without
  being actionable — say that rather than manufacturing an implication. Never
  invent a connection to his work or plans.
- **What to watch next** — the specific thing that would change the picture,
  with a date if his sources gave one.

Keep the line between reporting and inference visible throughout. "Playbook
reported X" and "which suggests Y" are different claims and must read
differently.

## Step 6: Write it to Spark

```text
spark__create-flint-digest(
  run_token: "<run_token from the trigger payload>",
  title: "News roundup — <weekday>",
  date: "<local_date>",
  period: "morning",
  summary: "<the three stories, as prose, one section each>",
  blocks: [ <editorial note, plus any insight blocks> ]
)
```

Pass `run_token` unchanged. It makes a retry return the original digest instead
of writing a second one, and it is what attributes the digest to this routine so
it gets its own place in the app rather than being folded into the morning
briefing.

Cite what you used: put the `event_id`s a story draws on in that block's
`referenced_event_ids` so Will can open the sources.

**Always include a `flint_editorial_note` block** recording, in a few lines:

- the source counts from Step 3 — newsletters, fetches, bookmarks;
- any coverage gap, and the service it was in;
- the candidates considered and why the three chosen beat the rest;
- whether a trigger payload arrived;
- anything you opened in full, and why it was worth the budget.

This block is the only way a bad run is diagnosable after the fact. A roundup
that reports empty or partial coverage **must** carry it.

Add a `flint_insight` block only for something that stands on its own beyond the
roundup — a development that changes a plan or bears on a Topic. Most runs need
none.

Do not write `flint_user_question` blocks; the day briefing owns the question
budget.

Call this tool on every run, including a failed load or an empty window. In
those cases the summary states the limitation plainly and must not imply that
nothing happened.

## Step 7: Maintain tactical Topics for unfolding stories

This is what makes the roundup cumulative rather than disposable.

**For a story already tracked as a Topic** that moved today:

```text
spark__manage-flint-topic(
  operation: "update",
  id: "<topic id>",
  content: "<rewritten current understanding>",
  related_event_id: "<the event_id from create-flint-digest>"
)
```

Rewrite `content` as where the story stands now — not a diary of every update.
The link history records the days it moved.

**For a new story** — create a tactical Topic only when it clears the bar:

1. It has run across **at least three separate days** of Will's sources;
2. It is genuinely unresolved — there is a next thing to happen;
3. It is not already covered by an existing Topic.

```text
spark__manage-flint-topic(
  operation: "create",
  title: "<short, stable name>",
  kind: "tactical",
  content: "<what this is and where it stands>",
  origin: "digest_inference",
  related_event_id: "<the event_id from create-flint-digest>"
)
```

A single day's big headline is not a Topic. Most stories peak and vanish inside
a week, and a Topics list full of last month's headlines is worse than none.

**When a tracked story concludes** — the election is called, the deal closes,
the thing ships — mark it `resolved` in the same run. Do not leave it for
`flint-topics` to expire on a timeout; you are the one who can see it ended.

---

# Checklist

- [ ] coverage established from `sync_status` counts and section contents, not
      from impression;
- [ ] a failed load reported as a failure naming the tool — never as a quiet day;
- [ ] three stories, or a stated reason for fewer;
- [ ] each one written at depth, with sources attributed by publication and any
      disagreement between them made explicit;
- [ ] nothing sourced from outside Will's own feeds, including background;
- [ ] no manufactured implication for a story that is simply worth knowing;
- [ ] `what to watch next` on every story;
- [ ] `referenced_event_ids` set on the blocks that draw on sources;
- [ ] editorial note written, with source counts and the selection reasoning;
- [ ] at most two originals opened in full;
- [ ] `run_token` passed through to `create-flint-digest`;
- [ ] tracked stories that moved were updated and linked; ones that did not move
      were left out;
- [ ] any new tactical Topic clears all three bars;
- [ ] concluded stories marked `resolved` in this run;
- [ ] no questions asked.
