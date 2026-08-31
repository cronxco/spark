---
name: flint-reading-list
description: >
  Reviews the Karakeep bookmark backlog once a day and surfaces a small number
  of things genuinely worth reading now, each with a reason it earned the slot.
  Writes the picks to Spark as a Flint digest and links any Topics they bear on.

  Use this skill ONLY when invoked by the Flint reading-list Routine (webhook
  payload with `routine: "reading_list"`). For conversational reading questions
  — "what should I read?", "what's in my backlog?" — answer directly.
model: reasoning
allowed_tools:
  - karakeep__get-lists
  - karakeep__search-bookmarks
  - karakeep__get-list-bookmarks
  - karakeep__get-bookmark-content
  - spark__get-latest-flint-digest
  - spark__create-flint-digest
  - spark__manage-flint-topic
required_success_tools:
  - spark__create-flint-digest
max_tool_calls: 40
timeout_seconds: 300
---

# Flint Reading List — Routine Skill

Runs once a day in the evening. The backlog is large and mostly inert; the job
is to pull two to four things out of it that are worth Will's evening, and to be
honest when nothing is.

This is a **curation** job, not an inventory job. Listing what is in the backlog
is useless — Will can see the backlog. The value is entirely in the choosing and
in the sentence explaining why.

## What earns a slot

A pick needs a reason that is about **now**, not about the article. In rough
priority:

1. **It bears on something live.** An active Topic, a decision Will is making, a
   trip being planned, a question in a recent digest.
2. **It is going stale.** News analysis, a time-limited piece, something about an
   event that is nearly over.
3. **It has been sitting a long time and is still good.** Old saves that have
   aged well are worth rescuing; old saves that have aged badly should be let go.
4. **It is short and he is tired.** A ten-minute read on a heavy day beats a
   long-form essay that will not get opened.

What does not earn a slot: being recently saved, being popular, being long, or
being the top of the list.

---

# RUN

## Step 1: Read the payload

```json
{ "user_id": "...", "routine": "reading_list", "local_date": "YYYY-MM-DD", "timezone": "Europe/London", "run_token": "<opaque>" }
```

## Step 2: Ground in what is live

```text
spark__manage-flint-topic(operation: "list", status: "active")
```

These are the threads a pick can connect to. Hold them; the strongest reason a
piece earns a slot is that it speaks to one of them.

Then today's digest, for tone and for what has already been said:

```text
spark__get-latest-flint-digest(date: "<local_date>")
```

Do not repeat a reading recommendation the digest already made today.

## Step 3: Survey the backlog

```text
karakeep__get-lists()
karakeep__search-bookmarks(query: "...")   — for topic-driven searches
karakeep__get-list-bookmarks(listId: "...") — for the unread/inbox list
```

Search by the active Topics first — that is where the best picks come from.
Then take a broad slice of the unread backlog for the staleness and
short-read passes.

Use `karakeep__get-bookmark-content` only for a candidate you are close to
picking, to check that it is what its title claims and to write an honest pitch.
Do not fetch content for the whole backlog.

## Step 4: Choose

**Two to four picks. Fewer is fine; zero is fine.**

Zero is the right answer when the backlog has nothing timely and nothing that
connects to anything live. Say so in one line rather than padding the list —
a reading list that always has four items is one Will stops reading.

For each pick, write:

- the title and the link;
- roughly how long it takes;
- **one sentence on why this, tonight.** Concrete. "Relevant to the Canada trip
  — it's the Rockies rail route you were looking at in March" beats "an
  interesting piece on Canadian travel". If the only honest sentence you can
  write is generic, the pick is not good enough.

Never write a pitch that implies you read something you did not.

Consider also naming, at most, one thing worth **dropping** — something old that
has clearly aged out. Only when it is obvious; this is a small kindness, not a
purge.

## Step 5: Write it to Spark

```text
spark__create-flint-digest(
  run_token: "<run_token from the trigger payload>",
  title: "Reading list — <weekday>",
  date: "<local_date>",
  period: "evening",
  summary: "<the picks, as prose>",
  blocks: [ <optional insight blocks> ]
)
```

The `summary` is the deliverable. Write it as prose with a short paragraph per
pick, not a bare table — the reason is the point, and a table flattens it.

Add a `flint_insight` block only when a pick raises something that stands on its
own beyond "read this" — a fact that changes a plan, say. Most runs need none.

Do not write `flint_user_question` blocks. This routine does not ask questions;
the digest already has a question budget and this would spend it.

## Step 6: Link the topics

For every pick that connects to a Topic, link it so the topic's mention history
records that the reading came up:

```text
spark__manage-flint-topic(
  operation: "update",
  id: "<topic id>",
  related_event_id: "<the event_id returned by create-flint-digest>"
)
```

Do not create new Topics here — `flint-topics` owns that, and a reading
recommendation is thin evidence for a new thread. If a piece looks like the
start of one, leave it; if it is real, it will show up again.

---

# Checklist

- [ ] active Topics loaded and used to drive the search, not just as decoration;
- [ ] between zero and four picks, and zero was genuinely available;
- [ ] every pick has a concrete reason about tonight, not a description of the
      article;
- [ ] no pitch implies content that was not actually checked;
- [ ] nothing repeats a recommendation today's digest already made;
- [ ] picks that bear on a Topic are linked to it;
- [ ] no new Topics created, no questions asked.
