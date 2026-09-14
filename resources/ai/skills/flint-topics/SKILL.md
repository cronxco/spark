---
name: flint-topics
description: >
  Reviews the day's Flint digests and coaching activity and maintains Spark
  Topics — the long-lived strategic, thematic, and tactical threads Flint
  tracks over time. Touches, retires, and occasionally proposes topics, and
  links the digests that discussed them.

  Use this skill ONLY when invoked by the Flint topics Routine (webhook payload
  with `routine: "topics"`). For conversational topic work — Will asking what
  Flint is tracking, or asking it to start or drop a topic — use `flint-coach`,
  which reads and writes the same topics.
model: reasoning
allowed_tools:
  - spark__get-events-by-filter-tool
  - spark__get-latest-flint-digest
  - spark__manage-flint-topic
  - spark__complete-flint-run
required_success_tools: []
max_tool_calls: 40
timeout_seconds: 300
---

# Flint Topics — Routine Skill

Runs once a day, shortly after the evening digest. One job: keep the Topics in
Spark an accurate picture of what is actually going on in Will's life, using the
digests that have already been written rather than re-deriving anything.

**A Topic is a thing Flint is tracking over time**, not an observation about the
data. "Canada trip 2027" is a topic. "Will tracks his health data consistently"
is not — it is a statistic about the database, and the previous incarnation of
this job filled the UI with exactly that kind of noise. Nothing here is worth
writing unless a person would recognise it as something they have going on.

## The three kinds

| Kind | Horizon | Example | Ends when |
|---|---|---|---|
| `strategic` | Months to years | A 2027 Canada trip; a three-week work trip to Australia in November; moving house | It happens, or Will drops it |
| `thematic` | Ongoing, no end date | Getting back to running; how much weight readiness should carry; managing sleep debt | It stops being live for months |
| `tactical` | Days to weeks | A boiler replacement; a long weekend away; an unfolding news story | It resolves |

**All three kinds are meant to be used.** A topics list that is entirely `tactical` is
not a sign of restraint — it means the job is only noticing things already in motion
and missing the longer threads underneath them. If every live topic resolves inside a
fortnight, Step 6 is being applied too narrowly; re-read it.

## Statuses

| Status | Meaning |
|---|---|
| `active` | Live now. Flint should notice new evidence about it. |
| `dormant` | Real, but nothing to say right now. Wakes at `next_review_at`. |
| `resolved` | It happened, or the question was answered. |
| `expired` | It went nowhere and is no longer worth watching. |

`resolved` and `expired` are both endings — the difference is whether it
concluded or fizzled. Neither is a failure state; a topic list that only ever
grows is a broken topic list.

---

# RUN

## Step 1: Read the payload

The Routine fires with:

```json
{ "user_id": "...", "routine": "topics", "local_date": "YYYY-MM-DD", "timezone": "Europe/London", "run_token": "<opaque>" }
```

Work in `local_date`, in `timezone`.

## Step 2: Load current topics

```text
spark__manage-flint-topic(operation: "list")
```

Returns every topic with `id`, `title`, `content`, `kind`, `status`,
`first_seen_at`, `last_touched_at`, `next_review_at`, `origin`.

Hold this in mind for the whole run. Every decision below is about these
records — creating a fourth topic that restates one already on the list is the
most common way this job goes wrong.

## Step 3: Load today's evidence

```text
spark__get-latest-flint-digest(date: "<local_date>")
```

Then the previous six days, so a thread is visible rather than a single day's
noise:

```text
spark__get-events-by-filter-tool(service: "flint", action: "had_summary", from_date: "<local_date - 6d>", to_date: "<local_date>")
```

Read each digest's `summary`, its `flint_insight` and `flint_editorial_note`
blocks, and — importantly — any `flint_user_question` blocks **with a non-null
`answer`**. An answered question is Will telling you something directly and
outranks anything inferred from the data.

If the day has no digest, do not invent evidence. Do Step 4 (reviews and
expiries, which are calendar-driven) and stop.

### Walk the answered questions deliberately

Do not just read past them. **Take each answered `flint_user_question` from the window
in turn and ask: does this answer name a thing with a horizon?** A trip, a decision, a
commitment, a stance Will has taken. If it does, it is a topic candidate and it has
already cleared bar 2 in Step 6 — Will said it directly.

This is where the job has been weakest. On 9 September Will answered that a £6,977.79
flight was a work trip to Perth, Adelaide and Canberra plus holiday in Sydney, 27
November to 16 December. Three weeks, two countries' worth of logistics, stated in his
own words — and no topic was created, while a four-day weekend became one. The bar was
met; nobody looked.

### Watch for dated commitments

When the evidence names **a future date on which Will has to do something**, that is a
topic with a built-in review date, whichever kind it is. "The return leg gets added on
24 September" is a commitment; so is a renewal, a deadline, a booking window opening.

Create or update the topic with `next_review_at` set to that date. Nothing else in the
system will remember it, and a commitment mentioned once in a digest and never again is
a commitment that gets missed.

## Step 4: Wake, retire, and expire

Before considering anything new, deal with what already exists.

**Dormant topics due for review** — `status` is `dormant` and `next_review_at`
is on or before `local_date`:

- Still real and now live → `status: "active"`, clear `next_review_at`.
- Still real but not yet → keep `dormant`, push `next_review_at` out to the
  next date it would plausibly matter.
- No longer real → `expired`.

**Active tactical topics that have gone quiet** — nothing has touched them for
14 days: `expired`, unless the topic is waiting on a known date, in which case
`dormant` with that date as `next_review_at`.

**Active thematic or strategic topics that have gone quiet** — nothing for 30
days: `dormant` with a `next_review_at` you can justify. Do not expire a
strategic topic for silence; a 2027 trip is quiet for most of 2026 and that is
correct.

**Anything the evidence says has concluded** → `resolved`. An answered question
that closes a thread, a booking made, a decision taken.

**A topic whose `next_review_at` is today or tomorrow** stays `active` and must be
visible to the next briefing — that is the whole point of setting the date. Make sure
its `content` says plainly what is due and when, in the first sentence, so the digest
routine reading the topics list cannot miss it.

## Step 5: Touch the topics today's digest actually discussed

For each existing topic, ask: **did today's digest give me something new about
this?** Not "was it mentioned" — new.

If yes:

```text
spark__manage-flint-topic(
  operation: "update",
  id: "<topic id>",
  content: "<rewritten summary>",
  related_event_id: "<the digest event id>",
  run_token: "<run_token from the trigger payload>"
)
```

Add `related_block_id` instead when one specific block is the thing that touched
it — an insight or an answered question — and the digest as a whole isn't.

**`content` is the current understanding, not a log.** Rewrite it in full each
time so it reads as an accurate paragraph or two about where this stands today.
Do not append dated bullets; the mention history is already kept for you by the
`discussed_in` links, and the Spark UI shows it.

A good summary answers: what is this, where does it stand, what is it waiting
on, and what would change it. Keep it under about 200 words.

If a topic was mentioned but nothing changed, link it (`related_event_id`) and
leave `content` alone. Linking is cheap; rewriting an unchanged summary is
churn.

## Step 6: Propose new topics — sparingly

You may create topics without being asked. That freedom is the reason to be
strict about it.

**Zero new topics is the normal outcome of a run.** Most days add nothing.

Create one only if **all** of these hold:

1. It is a thing in Will's life, not a property of his data.
2. It has appeared across **at least three separate days** of evidence, or Will
   has stated it directly in an answered question.
3. It will still be recognisable in a month — you can name what would count as
   it progressing, and what would count as it ending.
4. It is not already covered by an existing topic. Check the list from Step 2
   again before writing.

Explicitly do **not** create topics for:

- consistency or coverage observations ("tracks health data consistently",
  "reads regularly") — these describe the database, not a life;
- a single interesting day;
- a correlation with no consequence attached;
- anything phrased as advice rather than as a thread ("should sleep more");
- a restatement of a digest insight that was already made and read.

When you do create one:

```text
spark__manage-flint-topic(
  operation: "create",
  title: "<short, stable, human name>",
  kind: "strategic|thematic|tactical",
  content: "<what this is and where it stands>",
  origin: "digest_inference",
  related_event_id: "<the digest that prompted it>",
  run_token: "<run_token from the trigger payload>"
)
```

Use `origin: "conversation"` only when the topic comes from something Will said
— an answered question, or a coaching session.

Titles are names, not sentences: "Canada trip 2027", "Boiler replacement", "5k
time". Something Will would use to refer to the thing. They are stable — the
Spark UI keys on them, and renaming a topic breaks the thread visually. Rewrite
`content` freely; rename `title` only when the old name has become wrong.

Set `next_review_at` on creation for anything you already know is a
wake-me-later topic.

## Step 7: Confirm persisted output, then stop

If this run created or updated at least one topic, complete it with the ID from
the final successful write:

```text
spark__complete-flint-run(
  run_token: "<run_token from the trigger payload>",
  output_id: "<persisted topic id>"
)
```

Do not call completion with an unchanged topic merely to make the run appear
successful. A run with no durable topic change remains accepted, which
accurately records that the routine ran without claiming persisted output.

There is no digest to write and no notification to send. The Topics tab in Spark
is the output. Do not create a `flint_editorial_note`, do not write to Outline,
and do not summarise the run anywhere — a silent run that changed nothing is a
correct run.

---

# Checklist

- [ ] existing topics loaded before anything else was decided;
- [ ] dormant topics past `next_review_at` were woken, pushed out, or expired;
- [ ] quiet tactical topics expired; quiet thematic/strategic topics made dormant
      with a justified review date, never expired for silence alone;
- [ ] concluded topics marked `resolved`;
- [ ] every topic today's digest genuinely advanced was updated and linked;
- [ ] `content` rewritten as current understanding, not appended to as a log;
- [ ] every answered question in the window was checked for a thing with a horizon;
- [ ] any dated commitment in the evidence has a topic with `next_review_at` on that date;
- [ ] topics due for review lead with what is due, so the briefing can see it;
- [ ] any new topic clears all four bars in Step 6, and no new topic restates an
      existing one;
- [ ] zero new topics was considered and is an acceptable outcome;
- [ ] a run that wrote a topic passed that topic's ID to `complete-flint-run`;
- [ ] nothing was written outside Topics.
