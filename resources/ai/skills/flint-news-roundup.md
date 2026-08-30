---
name: flint-news-roundup
description: >
  Synthesises the recurring newsletter and fetch sources that landed overnight
  into a small themed roundup, and maintains tactical Spark Topics for the
  stories that are still unfolding. Runs in the morning so it is fresh for the
  day.

  Use this skill ONLY when invoked by the Flint news Routine (webhook payload
  with `routine: "news_roundup"`). For conversational news questions — "what
  did my newsletters say?" — use `spark-day-briefing`.
model: reasoning
allowed_tools:
  - spark__get-events-by-filter-tool
  - spark__get-event-tool
  - spark__get-block-tool
  - karakeep__get-bookmark-content
  - spark__create-flint-digest
  - spark__manage-flint-topic
max_tool_calls: 40
timeout_seconds: 300
---

# Flint News Roundup — Routine Skill

Runs once a day in the morning, before or around the morning digest. Reads the
newsletters and fetch sources that arrived overnight and turns them into a few
themes worth knowing about, rather than a list of what arrived.

**Themes, not items.** Five newsletters that all cover the same story are one
theme. One newsletter covering four unrelated things is four candidate items, of
which probably none survive. If the day's sources produce two themes, write two.

This roundup is **not** the day briefing. It does not cover health, money,
calendar, or tasks, and it does not tell Will what to do. It covers what
happened in the world he follows.

---

# RUN

## Step 1: Read the payload

```json
{ "user_id": "...", "routine": "news_roundup", "local_date": "YYYY-MM-DD", "timezone": "Europe/London" }
```

## Step 2: Load the sources

Everything in the knowledge/news domain since the previous run:

```text
spark__get-events-by-filter-tool(domain: "knowledge", from: "<local_date - 1d>", to: "<local_date>")
```

Newsletters and fetch sources belong to Spark, so this is the ground truth for
what actually arrived. Use `spark__get-event-tool` and `spark__get-block-tool`
to open a specific source when a theme depends on what it actually said.

Use `karakeep__get-bookmark-content` only to read the captured content of a
source a theme genuinely rests on. Do not browse for material.

**Coverage check.** If the overnight window is empty or clearly partial, that is
"unknown", not "nothing happened". Say the sources did not land, and stop —
do not fill the gap from memory or from the open web. This skill reports what
Will's own sources said.

## Step 3: Check what is already being tracked

```text
spark__manage-flint-topic(operation: "list", kind: "tactical")
spark__manage-flint-topic(operation: "list", status: "active")
```

An unfolding story Flint is already tracking is a continuing thread. Say what
moved since yesterday rather than reintroducing it from scratch — and if nothing
moved, leave it out entirely rather than restating it.

## Step 4: Build the themes

Group the day's sources by what they are actually about. For each candidate
theme ask:

**Does Will need to know this, or is it just news?**

A theme survives if at least one holds:

- it changes something he is planning or deciding;
- it moves a story he is already following;
- it is a genuine development in a field he works in or cares about;
- several of his sources independently thought it mattered.

A theme does not survive because it was the lead item, because it is dramatic,
or because it fills space. Two solid themes beat six thin ones. Zero is a
legitimate outcome on a quiet day — write one honest line saying so.

For each surviving theme write:

- what happened, in plain terms;
- what is actually new about it versus yesterday;
- why it matters to Will specifically, if it does — and nothing if it doesn't.
  A theme can be worth knowing without being actionable; do not manufacture an
  implication.

Attribute where it came from. Separate what a source reported from what you are
inferring.

## Step 5: Write it to Spark

```text
spark__create-flint-digest(
  title: "News roundup — <weekday>",
  date: "<local_date>",
  period: "morning",
  summary: "<the themes, as prose>",
  blocks: [ <optional insight blocks> ]
)
```

Prose, a short section per theme. Add a `flint_insight` block only for something
that stands on its own beyond the roundup — a development that changes a plan or
bears on a Topic. Most runs need none.

Do not write `flint_user_question` blocks; the day briefing owns the question
budget.

## Step 6: Maintain tactical Topics for unfolding stories

This is the part that makes the roundup cumulative rather than disposable.

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

- [ ] sources read from Spark, and a thin overnight window reported as unknown
      rather than filled in;
- [ ] nothing sourced from outside Will's own feeds;
- [ ] themes, not an item list; a repeated story appears once;
- [ ] every theme survives the "does he need to know this" test, and zero themes
      was available as an answer;
- [ ] no manufactured implication for a theme that is simply worth knowing;
- [ ] tracked stories that moved were updated and linked; ones that did not move
      were left out;
- [ ] any new tactical Topic clears all three bars;
- [ ] concluded stories marked `resolved` in this run;
- [ ] no questions asked.
