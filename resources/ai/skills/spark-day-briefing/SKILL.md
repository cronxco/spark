---
name: spark-day-briefing
description: >
  Generates a structured daily briefing from the Spark MCP day summary tool,
  enriched with the Outline day note plan and Fastmail context. Follows up with
  an interactive check-in conversation, then appends a Reflections section to
  the day note. Use this skill whenever the user asks what Spark says about their
  day, wants a daily summary, asks about their health/activity/spending/reading
  for a given day, or says anything like "what happened today", "run my day
  briefing", "how was my day", "what does Spark tell me", "what did my
  newsletters say", or "check in with me". Also use when the user asks about
  specific domains (health, money, media, reading, news) for a particular day.
  Always invoke this skill for any Spark day context request — even if the
  question feels simple, the data parsing and interpretation is non-trivial and
  this skill ensures correct results.
---
 
# Spark Day Briefing Skill
 
Produces a daily briefing from the Spark MCP `get-day-summary-tool`, written in the
**Politico Playbook** editorial style defined in the Spark Briefing Writing Styleguide
(fetched fresh each run from Outline). Enriched with the Outline day note (planned
schedule) and relevant Fastmail context. Covers health, activity, money, and media —
leading with anything anomalous. Time-aware: fetches multiple days depending on time
of day to support planning.
 
After presenting the briefing, runs a short interactive check-in (2–3 questions,
calibrated to the data and time of day), then writes a **Reflections** section back
to the day note.
 
**Spark deep-links**: Whenever a UUID is surfaced in tool output, format it as a
deep-link: `https://spark.beta.cronx.co/events/{UUID}`. Apply inline throughout the
briefing — transactions, newsletter items, health events, workouts — wherever a UUID
is available. Do not manufacture or approximate UUIDs.
 
---
 
## Step 0: Determine time context
 
Before fetching anything, call `user_time_v0` to get the current local time.
 
**If `user_time_v0` is unavailable or returns no result**, do not guess or proceed —
use `ask_user_input_v0` to ask:
 
```
Question: "What time is it for you right now?"
Options: ["Morning (before 12:00)", "Afternoon (12:00–18:00)", "Evening (after 18:00)"]
type: single_select
```
 
Wait for the answer before continuing. Use the response to set the time window below.
 
Then determine the **time window** to fetch:
 
| Time of day | Dates to fetch | Focus |
|---|---|---|
| **Morning** (before 12:00) | yesterday + today | Review yesterday, plan today |
| **Afternoon** (12:00–18:00) | yesterday + today + tomorrow | Full context + plan ahead |
| **Evening** (after 18:00) | today + tomorrow | Wrap up today, preview tomorrow |
 
If the user explicitly requests a specific date or window, honour that instead.
 
---
 
## Step 0b: Fetch the Briefing Style Guide
 
**Always fetch this before writing the briefing.** Use `Docs:list_documents` with the
document ID directly — do **not** use `web_fetch` (it will fail with a permissions error):
 
```
Docs:list_documents(
  query: "spark briefing writing styleguide"
)
```
 
The style guide document ID is: `586576f8-7bc5-49db-a48f-db664710ba91`
 
The `list_documents` response returns the full document content in the `data` field as
a structured JSON object. Read the text content in full before proceeding to Step 7.
 
The style guide is the **authoritative source** for briefing format and tone. It
supersedes any structural templates in this skill file. Key things it covers:
 
- Morning vs evening format differences (DRIVING THE DAY vs cheat sheet opening)
- Section header conventions (all-caps editorial labels, inline bold sub-headers)
- Voice and tone rules (dry, specific, no wellness language, British English)
- Recurring structural labels: `DRIVING THE DAY`, `[DAY] CHEAT SHEET`,
  `WHAT YOU'VE BEEN UP TO`, `WHAT I'VE BEEN READING FOR YOU`, `THE NUMBER`,
  `COMING UP`, `TOMORROW'S WORLD`
- Language rules and what to avoid
**If the fetch fails**, proceed with the inline guidance in this skill as a fallback,
but tell Will the style guide couldn't be loaded.
 
---
 
## Step 1: Fetch the day note(s) from Outline
 
Before fetching Spark data, look up the relevant day note(s) from Outline. This gives
you the **planned schedule** to compare against actuals.
 
For each date in the time window, search the Spark collection for the matching document:
 
```
Docs:list_documents(
  collectionId: "5622670a-e725-437d-b747-a17905038df8",
  query: "YYYY-MM-DD"   # e.g. "2026-03-18"
)
```
 
From the matching document, extract the structured table fields if present:
- **Location** — where Will is/was
- **Travel** — any journeys planned (flights, commute, etc.)
- **Morning / Afternoon / Evening / Overnight** — planned activities per slot
Store the day note document ID — you'll need it later to append Reflections.
 
If no day note exists for a date, note this but don't block the briefing.
 
---
 
## Step 1b: Fetch weather for relevant location(s)
 
After reading the day note(s), extract the **Location** field for each date in the window.
 
- If the location is blank or missing, default to **Croydon / South London** (lat: 51.3762, lon: -0.0982).
- If a location is found (e.g. "Buenos Aires", "Rio de Janeiro", "Mendoza"), geocode it
  mentally and call `weather_fetch` with appropriate coordinates.
- For multi-day windows, fetch weather for each distinct location (e.g. today may be
  travelling — use tomorrow's destination for tomorrow's weather).
Use the weather data in two places:
1. **Briefing**: Include a brief weather line in the relevant day section (today/tomorrow).
   Format: `🌤️ Weather: [condition], [temp]°C, [brief note if relevant — e.g. "rain likely afternoon"]`
2. **Day note Reflections** (Step 9): Include actual conditions for the day if it's a
   retrospective entry.
Keep weather brief — one line per day is enough unless conditions are notable (storms,
extreme heat, etc).
 
---
 
## Step 2: Fetch relevant Fastmail context
 
Search Fastmail for emails from the past 24 hours that are contextually relevant to
the day. Use `CronxMCP:fastmail__search_emails` or `CronxMCP:fastmail__get_recent_emails`.
 
Look for:
- Flight confirmations, check-in emails, or travel updates
- Booking confirmations or reservation reminders
- Any email flagged as important/urgent
If the day note contains travel details (flight number, destination), use those as
search terms to find matching emails — this is more reliable than a broad recency fetch.
 
Keep this lightweight — you're looking for context that might affect interpretation of
the day's data or tomorrow's planning. Don't surface routine newsletters or receipts
(those come through Spark's `receipt` service already).
 
---
 
## Step 3: Fetch the Spark data
 
**Primary tool: `spark__get-day-summary-tool`**
 
This is the main data source for the briefing. It returns a compact, pre-aggregated
summary with structured domain sections, baseline comparisons, and anomaly detection.
 
Call it once with all dates in the time window:
 
```
spark__get-day-summary-tool(
  dates: ["yesterday", "today"]           # or whatever the window requires
)
```
 
The response fits in context — no file-dump parsing needed.
 
**Response structure:**
- Single date → returns an object with `date`, `sync_status`, `sections`, `anomalies`
- Multiple dates → returns an array of those objects, one per date
Each `sections` object contains domain-keyed data:
- `sections.health` → sleep_score, readiness_score, sleep_duration, heart_rate, spo2,
  stress, resilience, hrv, cardiovascular_age, vo2_max (each with value, vs_baseline_pct,
  is_anomaly, and contributors/stages where applicable)
- `sections.activity` → steps, distance_km, active_energy_kcal, exercise_minutes,
  flights_climbed, stand_hours, resting_heart_rate, workouts[] (each with type, calories,
  duration_seconds, distance)
- `sections.money` → transactions[] (merchant, amount, currency), total_spend, receipts[]
- `sections.media` → listening_sessions[] (start, end, track_count, top_artist, description)
- `sections.knowledge` → outline_note_exists, outline_notes[], fetched_content[], newsletters[]
  - `fetched_content[]` — saved bookmarks/web pages: title, url, summary, key_takeaways[], tldr
  - `newsletters[]` — auto-fetched newsletter content: title, from, tldr, summary, key_takeaways[]
  - Both types have LLM-generated summaries and takeaways — treat as substantive content, not a list of links
The `anomalies` array at the top level lists all flagged anomalies with metric name,
current value, baseline value, deviation, direction, and `streak_days`.
 
**Data staleness check:** Check `sync_status` for each service. If Apple Health has
`"coverage": "partial"` and a `coverage_note`, mention this to the user — data may be
incomplete. This replaces the old heuristic of checking for implausibly low values.
If `newsletter` or `fetch` show 0 events in `sync_status`, note briefly that reading
content may not have synced yet — don't block the briefing.
 
### When to use other Spark tools
 
The day summary is sufficient for the standard briefing. Use the other new tools for
follow-up questions or deeper dives:
 
- **`spark__get-service-status-tool`** — When you need to check whether data has synced
  for a specific date (e.g. "has Apple Health caught up yet?"). More detailed than the
  `sync_status` field in the summary.
- **`spark__get-metric-trend-tool`** — When an anomaly warrants context ("is this a
  one-off or a trend?"). Accepts flexible date ranges: `"7_days_ago"`, `"last_7_days"`,
  `"last_month"`, ISO dates. Returns daily values with baseline comparison and trend
  direction. Use this for questions like "how has my sleep been lately?"
- **`spark__get-baselines-tool`** — When you need raw baseline stats (mean, stddev,
  normal bounds, sample size) for one or more metrics. Useful for putting a value in
  context. Omit the metrics parameter to discover all available metrics.
- **`spark__get-events-by-filter-tool`** — When you need exact-match event lists rather
  than semantic search. E.g. "all Monzo transactions this week", "Oura sleep scores last
  7 days". Filters by service, action, date range. More reliable than `search-events-tool`
  for structured queries.
- **`spark__acknowledge-anomaly-tool`** — When Will wants to dismiss a recurring anomaly
  (e.g. "SpO2 has been low because of altitude — suppress it"). Accepts a note and an
  optional `suppress_until` date.
- **`spark__get-day-context-tool`** — The old full-payload tool. Only use as a fallback
  if you need raw event-level detail that the summary doesn't include (e.g. individual
  Spotify tracks, Untappd check-in details, Oura sleep stage blocks). Be aware: response
  is large and will likely be stored to file. If you need to use it, parse with the
  pattern in the "Legacy parsing" appendix.
---
 
## Step 4: Parse — no longer needed for standard briefings
 
The `get-day-summary-tool` returns structured, context-friendly JSON. No file-dump
parsing is required.
 
**If you fall back to `get-day-context-tool`**, the response will likely be stored to
file. See the "Legacy parsing" appendix at the end of this skill for the parsing pattern.
 
---
 
## Step 5: Interpret by domain
 
### 🫀 Health (Oura)
 
Key metrics to always surface from `sections.health`:
- **Sleep score** — report `score` and `vs_baseline_pct`. Good: ≥80. Flag if <70.
  Surface notable contributors (any <75% or =100%).
- **Sleep duration** — convert `duration_seconds` to hours:minutes. Surface stages
  if interesting (very low REM, high awake time, etc).
- **Readiness score** — report `score` and `vs_baseline_pct`. Flag if <70. Surface
  notable contributors (especially Resting Heart Rate, HRV Balance, Recovery Index).
- **Stress level** — scale 1–3 (1=calm, 3=stressed). Flag if 3.
- **Resilience level** — scale 1–5. Note if unusually high or low vs baseline.
- **SpO2** — flag if <96%. Check `anomalies` array for `streak_days` — if 3+
  consecutive days, flag prominently, not as a footnote.
- **HRV** — report value in ms and vs_baseline_pct. Lower than baseline = flag.
- **Cardiovascular age** — mention if notably above/below actual age (Will is 35).
- **VO2 max** — brief mention with trend direction.
### 🏃 Activity (Apple Health)
 
From `sections.activity`:
- **Step count** — value and vs_baseline_pct
- **Walking/running distance** — `distance_km`, in km
- **Active energy** — `active_energy_kcal`, in kcal
- **Exercise time** — `exercise_minutes`, in minutes
- **Workouts** — from `workouts[]`: type, duration (convert `duration_seconds` to
  minutes), calories, distance. Combine multiple walks if needed.
- **Flights climbed** — brief mention
- **Stand hours** — brief mention if low
Skip unless anomalous: walking asymmetry, double support %, stair speeds, step length,
physical effort score, basal energy, audio exposure, respiratory rate.
 
### 💷 Money (GoCardless + Receipt)
 
From `sections.money`:
- List each transaction from `transactions[]`: merchant name (cleaned up), amount,
  currency. Note `total_spend` for the day.
- Receipts from `receipts[]`: merchant, amount.
- Note anomalies or unusual patterns.
**Merchant cleanup**: "MARKS AND SPENCER  PURLEY WAY" → "M&S Purley Way", etc.
 
### 🎵 Media (Spotify + Untappd)
 
From `sections.media`:
- **Listening sessions**: Summarise from `listening_sessions[]` — time range, track
  count, top artist, description.
- **Note**: The summary provides session-level data. If Will asks for individual track
  detail, fall back to `get-events-by-filter-tool` with `service: "spotify"` or
  `get-day-context-tool`.
- **Untappd**: If present in sessions or summary, surface beer names and ratings.
  For individual check-in detail, use `get-events-by-filter-tool` with
  `service: "untappd"`.
### 📰 Reading & News (Newsletter + Fetch)
 
From `sections.knowledge.newsletters[]` and `sections.knowledge.fetched_content[]`.
 
**Goal**: Surface what Will should actually know or take away — not a catalogue of what
arrived. Will hasn't read these yet. Treat them as an edited news briefing, not a receipt.
 
**Rendering approach:**
 
1. **Scan all items first.** Check for thematic overlap — if two newsletters and a fetched
   article all cover the same story (e.g. Iran/oil prices, a UK policy shift), synthesise
   them into one section rather than listing them separately.
2. **Lead with the most significant item.** Use editorial judgement: geopolitical risk,
   economic news, or anything with direct relevance to Will's interests or finances takes
   priority. Pull 2–3 of the most substantive `key_takeaways` from that item (or the
   synthesised cluster), enough to actually convey the substance.
3. **Fast-scan the rest.** For each remaining item: source name + `tldr` only, on one
   line. No need to expand unless a takeaway is genuinely surprising or actionable.
4. **Never use "you received" or "you saved" framing.** Just surface the content.
   Source names (Politico, The Economist, London Centric) are attribution, not the point.
5. **Synthesise across sources when themes overlap.** Lead with:
   "Multiple sources this morning cover [topic]:" then give the synthesised picture.
   Don't repeat the same story three times from three different angles.
**Getting full text if needed**: The day summary includes LLM-generated summaries only.
For full content, first retrieve event IDs:
  `spark__get-events-by-filter-tool(service: "newsletter", from_date: "today")`
  `spark__get-events-by-filter-tool(service: "fetch", from_date: "today")`
Then call `spark__get-event-tool(id: ...)` for the full payload of a specific item.
Only do this if Will asks to dig deeper into a specific piece.
 
---
 
## Step 6: Anomaly prioritisation
 
Use the top-level `anomalies` array from each day's summary. Each anomaly includes:
- `metric` — canonical identifier
- `display_name` — human-readable (may need cleanup: "Had Sleep Score" → "Sleep Score")
- `type` — `anomaly_high` or `anomaly_low`
- `direction` — `up` or `down`
- `current_value` and `baseline_value`
- `deviation` — number of standard deviations from baseline
- `streak_days` — consecutive days this anomaly has been flagged
Prioritisation:
 
1. **Persistent trend (streak_days ≥ 3)** → Flag prominently in health section.
   Offer to run `get-metric-trend-tool` for full context, or suggest using
   `acknowledge-anomaly-tool` if Will has already explained it.
2. **High deviation (≥ 3 stddev), single day** → Note prominently inline.
3. **Moderate anomaly (< 3 stddev), single day** → Note inline, briefly.
4. **Above-baseline (positive direction)** → Usually positive, mention briefly.
   Exception: cardiovascular age above baseline is negative.
**Cross-reference with day note**: If a metric anomaly aligns with something in the
day note (overnight flight, travel day, long evening out), connect the dots explicitly.
E.g., "Readiness is low — unsurprising given the overnight flight noted in your plan."
 
**Display name cleanup**: Strip "Had " prefix from display names. "Had Sleep Score" →
"Sleep Score", "Had Spo2" → "SpO2", "Did Workout" → "Workout", etc.
 
---
 
## Step 6b: Editorial planning (Flint thinks before writing)
 
**This step happens entirely before any briefing copy is written.** Flint takes stock
of all available data, makes deliberate editorial decisions, and does any additional
research needed. The goal: walk into Step 7 with a clear plan, not a vague intention.
 
### 6b-i: Score each domain
 
Mentally rate each domain as **Rich / Adequate / Thin / Empty**:
 
| Domain | Rich | Adequate | Thin | Empty |
|---|---|---|---|---|
| **Health** | Clear anomalies or notable story | Solid data, nothing remarkable | Partial sync, low signal | No Oura data |
| **Activity** | Workout logged, or notable step count | Routine movement | Low data, Apple Health lagging | No activity data |
| **Money** | Unusual transaction or pattern | Normal spend day | One routine transaction | No spend |
| **Media** | Interesting Spotify session or Untappd check-in | Background listening | Vague session data | Nothing |
| **Knowledge** | Multiple newsletters + fetched content with substance | 1–2 items with decent tldr | Summaries only, no depth | Nothing synced |
| **Day note** | Rich plan with context | Basic schedule | Sparse / a few lines | Empty or missing |
 
### 6b-ii: Decide the lede
 
Pick the single most important thing about today. This is non-negotiable — Flint
commits to a lede before writing. Candidates in rough priority order:
 
1. A dominant logistical or contextual fact (travel, a race, major event)
2. A significant health anomaly (especially if streak_days ≥ 3)
3. A meaningful divergence between plan and data
4. The best knowledge item, if health/activity is quiet
5. "QUIETLY SOLID" — if genuinely nothing notable
**Write the lede decision down internally.** e.g. *"Lede: low readiness post-travel.
Health carries the morning. Knowledge section is secondary."*
 
### 6b-iii: Identify what needs extra research
 
Based on the domain scores, decide whether to fetch additional data. Rules:
 
**Knowledge depth (most common trigger):**
- If **Knowledge is Rich** AND **Health/Activity is Thin or Adequate** → fetch full
  text of the single most substantive item:
  ```
  spark__get-events-by-filter-tool(service: "newsletter" OR "fetch", from_date: "...", to_date: "...")
  spark__get-event-tool(id: "...")
  ```
  This turns a summary into a proper `WHAT I'VE BEEN READING FOR YOU` section with
  real substance. Don't fetch everything — pick the one item most worth Will's time.
**Activity detail:**
- If a workout appears in `sections.activity` but duration/distance/type is vague →
  fetch detail: `spark__get-events-by-filter-tool(service: "apple_health", action: "did_workout")`
- If Spotify sessions show "Mixed tracks" or generic labels → fetch track detail:
  `spark__get-events-by-filter-tool(service: "spotify", from_date: "...", to_date: "...")`
**Anomaly context:**
- If any anomaly has `streak_days ≥ 3` AND it hasn't been acknowledged → fetch trend:
  `spark__get-metric-trend-tool(metric: "...", from: "7_days_ago")`
  Use this to determine whether to flag it prominently or suggest acknowledging it.
**Hard limits on research:** Maximum **2 additional tool calls** in this step. If
more would genuinely help, note it in the editorial log but proceed with what you have.
Don't let research delay the briefing indefinitely.
 
### 6b-iv: Check for pre-briefing questions
 
Pre-briefing questions are **rare** — only ask if the answer would materially change
the lede or a major section, and you genuinely can't proceed without it.
 
Triggers that justify a pre-briefing question:
- Day note is empty AND it's a morning briefing AND the day looks unusual (no plan = no `COMING UP`)
- A health anomaly is ambiguous and context would change the framing significantly
  (e.g. "Is the low readiness because of a late night, or did something happen?")
If firing a pre-briefing question, use `ask_user_input_v0` with a brief context
sentence before the options. Max 1 question. Wait for the answer before proceeding.
 
If no pre-briefing question is needed (the common case), skip directly to Step 7.
 
### 6b-v: Draft the editorial plan
 
Before writing, Flint notes internally (this feeds the editorial log in Step 7b):
 
```
LEDE: [what leads and why]
SECTIONS: [which sections run, in what order, and approximate weight]
RESEARCH DONE: [any extra fetches, what they added]
THIN AREAS: [anything being deliberately kept brief or omitted]
QUESTIONS DEFERRED TO CHECK-IN: [what the post-briefing check-in will focus on]
```
 
---
 
## Step 7: Write the briefing
 
**The style guide fetched in Step 0b is the authoritative format reference.** Follow
it precisely — structure, header conventions, voice, tone, and section labels all
come from there. Do not use emoji section headers or markdown subheadings in
Playbook-style sections.
 
### Key structural reminders (from the style guide — read the full guide, don't rely on this summary)
 
**Morning edition:** Opens with `Good [day] morning.` then straight into `DRIVING THE DAY`
— one deeply threaded lede story, the single most important thing about today. No
cheat sheet. Additional sections follow: secondary stories, `WHAT YOU'VE BEEN UP TO`,
`WHAT I'VE BEEN READING FOR YOU`, `THE NUMBER`, `COMING UP`.
 
**Evening edition:** Opens with `Good [day] evening.` then `[DAY] CHEAT SHEET` — tight
bullet-dash digest, each item on its own line (never collapsed into a paragraph).
Then the main story, supporting sections, `TOMORROW'S WORLD`.
 
**Afternoon edition:** Treat as a morning edition format but broaden the scope
(yesterday recap + today + tomorrow preview).
 
**Section header hierarchy:**
1. All-caps editorial label (e.g. `QUIETLY SOLID —`) — frames the story, never just labels it
2. All-caps bold inline story header (e.g. **BODY REGISTERING THE WEEK:**) — runs directly into prose, no line break
3. Title/sentence case bold inline sub-items (e.g. **On the plus side:**) — same line rule
**Spark deep-links:** Embed inline wherever a UUID is available from tool output.
Format: `[descriptive text](https://spark.beta.cronx.co/events/{UUID})`. Apply to
transactions, newsletter items, health events, workouts. If no UUID is returned for
a metric (e.g. aggregated Oura scores), don't link it.
 
**Weather:** Weave into the relevant day section in one line — not a standalone header.
 
**Omit** any section with no data. If a section is thin, one line and move on.
 
### Step 7b: Append the editorial log
 
After the briefing copy, append a collapsed editorial note using a `<details>` block.
This is for Will if he wants to understand Flint's reasoning — it should not be needed
if the briefing is good. Keep it tight: 4–6 lines, no waffle.
 
```html
<details>
<summary>Editorial note</summary>
 
**Lede:** [what led and why — one line]
**Research:** [any extra fetches — what was pulled and what it added, or "none"]
**Thin:** [anything kept brief or omitted, and why]
**Check-in focus:** [what the post-briefing questions will cover]
 
</details>
```
 
If no extra research was done and nothing was omitted, the log can be minimal:
*"Standard run. Lede: [X]. No extra research. Check-in: [Y]."*
 
---
 
## Step 8: Interactive check-in
 
After the briefing, shift to a short check-in using the `ask_user_input_v0` tool.
**Ask 2–3 questions in a single widget call — no more.**
 
> **REQUIRED: Always use `ask_user_input_v0` for check-in questions. Never ask them
> in prose. If you find yourself writing "Q:" or a question mark in plain text after
> the briefing, stop and use the widget instead.**
 
### Principles
 
- **Draw on the editorial plan from Step 6b.** The planning stage noted which questions
  to defer to the check-in. Start there — don't re-derive from scratch.
- **Anchor every question to a specific data point or event.** Never ask "how did the
  day feel overall?" or any other generic question. Every question must reference
  something concrete from the briefing: a metric value, a flagged anomaly, a transaction,
  a workout, a plan. If you genuinely can't find a specific anchor, use the catch-all.
- **Calibrated to time of day:**
  - Morning → how yesterday felt + today's intention
  - Afternoon → how the day is going vs. plan + anything to shift
  - Evening → how today actually went + one forward-looking question
- **Connect to the day note** where possible.
- **Max one health question, one planning/intention question**
- If the day is unremarkable, use a single catch-all question.
### Widget format
 
Use `ask_user_input_v0` with 2–3 questions. Choose the right type per question:
 
- **Yes/no or short closed questions** → `single_select` with 2–4 options
- **Questions with a few distinct answers** → `single_select` or `multi_select`
- **Open / catch-all** → still use `single_select` but include "Something else / I'll type it" as an option, or follow up in prose if needed
**Important**: `ask_user_input_v0` doesn't support free-text input — structure options
thoughtfully so Will can answer meaningfully with a tap. Always include an escape hatch
option like "All good / nothing to add" or "I'll mention it below" where relevant.
 
### Example widget shapes (generate from actual data — don't use verbatim)
 
**Sleep question:**
```
"Your sleep score was solid but you woke up twice — did you feel rested?"
Options: ["Yes, felt good", "So-so", "Pretty tired", "Didn't notice the wake-ups"]
```
 
**Activity question:**
```
"Steps were well below your usual today — was that intentional?"
Options: ["Yes, rest day", "Just a quiet day", "Tried to move more but didn't", "Didn't notice"]
```
 
**Planning question:**
```
"You've got [X] tomorrow — anything you want to flag?"
Options: ["All sorted", "Need to prep a few things", "A bit uncertain about it", "Nothing to add"]
```
 
**Anomaly question:**
```
"SpO2 has been flagged low for 3 days running — want to acknowledge this or keep tracking?"
Options: ["Acknowledge it (travel/altitude)", "Keep tracking it", "Not sure, let's watch"]
```
 
**Catch-all:**
```
"Anything worth capturing that the data wouldn't show?"
Options: ["Nope, all captured", "Had a good moment worth noting", "Something was off today", "I'll add a note below"]
```
 
**Reading/news hook (use if a significant item appeared in newsletters or fetched content):**
```
"The [source] piece on [topic] seemed worth flagging — want to dig in?"
Options: ["Yes, tell me more", "No, I'll read it later", "Not for me"]
```
 
Wait for Will's response before proceeding to Step 9.
 
---
 
## Step 9: Write Reflections to the day note
 
After Will responds, compile a **Reflections** section and write it to the correct
day note using `Docs:update_document`.
 
### Which day to target
 
The target day is always the **most recent completed day** — the one whose data you
have the fullest picture of:
 
| Briefing type | Target day |
|---|---|
| **Morning** (before 12:00) | **Yesterday** — unless an Evening Reflections already exists there |
| **Afternoon** (12:00–18:00) | **Yesterday** — unless an Evening Reflections already exists there |
| **Evening** (after 18:00) | **Today** |
 
**Morning/afternoon exception**: If yesterday's note already has an Evening Reflections
section (from a briefing run last night), don't duplicate it — write a Morning
Reflections to **today's** note instead.
 
### Whether to append or replace
 
Check whether the target note already contains a Reflections section by reading its
content:
 
- **No existing Reflections** → use `editMode: "append"`
- **Existing Reflections that matches this briefing type** (e.g. you're running an
  evening briefing and there's already an Evening Reflections) → use `editMode: "replace"`
  on the full document text, updating only the Reflections block in place
- **Existing Reflections from a different session** (e.g. an earlier morning entry
  exists and you're now writing an evening one) → use `editMode: "append"` to add
  alongside it — both entries are worth keeping
### Format
 
```markdown
 
---
 
## Reflections
 
*[Day, date, time-of-day label — e.g. "Wednesday 18 March, evening" or "Thursday 27 March, morning"]*
 
[1–2 sentence narrative summary — **required** unless the day was genuinely
unremarkable and the data tells the whole story. Second person, past tense, based
on data + Will's answers. This paragraph is what makes the Reflections worth reading
in a year's time — don't skip it. E.g., "A solid recovery day after the overnight
flight — energy low in the morning but an afternoon walk helped. Readiness back to
baseline."]
 
### 🌤️ Weather
[Condition, temp, brief note — e.g. "Overcast, 14°C, dry all day"]
 
### 🫀 Health & Activity
| Metric | Value | vs Baseline |
|--------|-------|-------------|
| Sleep score | [n] | [+n% / −n% / on par] |
| Readiness | [n] | [+n% / −n% / on par] |
| HRV | [n] ms | [+n% / −n% / on par] |
| Resting HR | [n] bpm | [+n% / −n% / on par] |
| Steps | [n,nnn] | [+n% / −n% / on par] |
| Distance | [n.n] km | [+n% / −n% / on par] |
| Active energy | [n] kcal | [+n% / −n% / on par] |
| Exercise | [n min / workout type] | [+n% / −n% / on par] |
 
*Flags: [Any anomalies — include streak_days if >1. E.g. "SpO2 low 3rd consecutive day (96.2%, baseline 98.1%)", or "none"]*
 
### 💷 Money
[Notable spend with total, or "Nothing unusual"]
 
### 🗒️ Notes
[Will's own words, lightly edited, if anything worth preserving was said]
```
 
Omit **Notes** if Will didn't say anything worth preserving.
Omit **Weather** if no weather data was fetched.
The health table should only include rows where data is available — don't leave blank cells.
"vs Baseline" values come from `vs_baseline_pct` in the summary response.
 
**If no day note exists:** Offer to create one with just the Reflections section,
nested under the correct parent for that month.
 
**If Will skipped the check-in:** Still offer a data-only Reflections with no Notes.
 
---
 
## Step 10: Cross-day planning insights
 
Look for these patterns across the full window and surface any that apply:
 
- **Recovery**: Low readiness + yesterday's hard effort or travel → suggest lighter day
- **High readiness**: Flag as good day for demanding work or a harder run
- **Sleep trajectory**: Declining over 2+ days → flag; late night → suggest earlier wind-down.
  Use `get-metric-trend-tool` with `from: "7_days_ago"` on `oura.sleep_score` if
  the summary shows declining scores across the window.
- **Activity load**: High effort + low readiness → rest suggestion; 2+ low-activity days → note it
- **Spending**: Multiple unusual transactions → note pattern, not just list
- **Fastmail → tomorrow**: Travel or booking email → factor into tonight's prep suggestion
- **Running continuity**: Recent run → note recovery needs; no run in several days → mention it.
  Use `get-events-by-filter-tool` with `service: "apple_health"`, `action: "did_workout"`
  to check recent workout history if needed.
- **Persistent anomalies**: If any anomaly has `streak_days ≥ 3`, proactively suggest
  acknowledging it with `acknowledge-anomaly-tool` if Will has a known explanation
  (travel, illness, etc), or suggest investigating with `get-metric-trend-tool`.
Each insight should be **actionable** — a concrete suggestion, not just an observation.
 
---
 
## Step 11: Re-run pattern
 
If asked to re-run, call `spark__get-day-summary-tool` again for the same dates.
Check `sync_status` — if Apple Health `coverage` has changed from `"partial"` to
something fuller, or event counts have increased, note what's new.
 
For a more detailed sync check, use `spark__get-service-status-tool` for the target date.
It returns per-service event counts, last event times, and coverage notes.
 
---
 
## Common services reference
 
| Service | Domain | What it tracks |
|---|---|---|
| `oura` | health | Sleep, readiness, stress, SpO2, HRV, activity score, resilience, cardiovascular age |
| `apple_health` | health/activity | Steps, distance, HR, HRV, SpO2, energy, workouts, walking metrics |
| `spotify` | media | Track-by-track listening history with artist/album |
| `untappd` | media | Beer check-ins with ratings and brewery |
| `gocardless` | money | Bank/card transactions |
| `receipt` | money | Email receipts with line items |
| `fetch` | knowledge | Saved web bookmarks with LLM-generated summaries and key takeaways |
| `newsletter` | knowledge | Auto-fetched newsletter content with LLM-generated summaries and key takeaways |
| `outline` | knowledge | Day notes from Outline wiki |
| `fastmail` | context | Time-sensitive emails — travel, bookings, reminders |
 
---
 
## New Spark MCP tools reference
 
| Tool | Purpose | When to use |
|---|---|---|
| `get-day-summary-tool` | Compact pre-aggregated day summary with baseline comparisons and anomalies | **Primary tool for all briefings.** Single call replaces old multi-step parse. |
| `get-service-status-tool` | Sync status and data coverage per service for a date | Checking if data has synced, verifying Apple Health lag |
| `get-metric-trend-tool` | Daily metric values over a date range with baseline comparison | "How has my sleep been?", investigating anomaly trends |
| `get-baselines-tool` | Raw baseline stats (mean, stddev, normal bounds) for metrics | Putting a specific value in context, discovering available metrics |
| `get-events-by-filter-tool` | Exact-match event filtering by service/action/date range | "All Monzo transactions this week", "recent workouts" |
| `acknowledge-anomaly-tool` | Dismiss a recurring anomaly with optional note and suppression | When Will explains an anomaly (travel, illness) and wants to stop seeing it |
| `get-day-context-tool` | Full raw event payload (legacy) | Only when individual event/block detail is needed (track lists, sleep stages, etc) |
 
---
 
## Known quirks
 
- **GoCardless tags** are generic (card number, `pending`). Don't auto-categorise
  beyond what the merchant name makes obvious.
- **Apple Health coverage**: The `sync_status` in the summary now includes a
  `coverage` field and `coverage_note` for Apple Health. Use this instead of
  guessing from low values. If `coverage: "partial"`, mention it; don't flag
  activity metrics as anomalous when they're just incomplete.
- **Oura sleep detail**: The summary includes `sleep_duration` with `stages`
  (deep, light, REM, awake, latency, etc). For most briefings this is sufficient.
  Only fall back to `get-day-context-tool` if you need individual sleep block data.
- **Spotify detail**: The summary provides session-level aggregates
  (`listening_sessions[]`). For individual track lists, use
  `get-events-by-filter-tool(service: "spotify", from_date: "...", to_date: "...")`
  or fall back to `get-day-context-tool`.
- **Untappd detail**: Similar to Spotify — summary may not include individual check-ins.
  Use `get-events-by-filter-tool(service: "untappd")` for full detail.
- **Anomaly display names** from the API have a "Had " or "Did " prefix that should
  be stripped for readability. "Had Sleep Score" → "Sleep Score", "Had Spo2" → "SpO2".
- **Metric identifier flexibility**: The new tools accept shorthand identifiers.
  `oura.sleep_score` resolves to `oura.had_sleep_score.percent`. You don't need
  the full canonical form.
- **Multi-date summary returns an array**: When `dates` has multiple values, the
  response is a JSON array. Single date returns a single object.
- **Outline day note structure varies** — some days have a full table, others are
  sparse or empty. Don't assume fields exist; check before rendering.
- **Fastmail search** can be noisy. Use specific terms from the day note (flight
  number, destination, booking ref) when available — more reliable than broad recency.
---
 
## Appendix: Legacy parsing (get-day-context-tool fallback)
 
If you need to use `get-day-context-tool` for event-level detail, the response will
almost always be too large for context and will be stored to file:
```
Tool result too large for context, stored at /mnt/user-data/tool_results/<filename>.json
```
 
Parse with this pattern:
 
```python
import json, sys
 
raw = json.load(sys.stdin)          # loads a list
data = json.loads(raw[0]['text'])   # the actual day context dict
 
# data now has: date, timezone, event_count, service_breakdown, groups
```
 
Summary parse:
 
```bash
cat <filepath> | python3 -c "
import json, sys
raw = json.load(sys.stdin)
data = json.loads(raw[0]['text'])
print('Date:', data.get('date'))
print('Events:', data.get('event_count'))
print('Services:', json.dumps(data.get('service_breakdown'), indent=2))
print()
for g in data.get('groups', []):
    first = g.get('first_event', {})
    val = first.get('value')
    unit = first.get('unit', '')
    target = first.get('target', {}).get('title', '')
    time_str = first.get('time', '')
    is_anomaly = (first.get('metrics') or {}).get('is_anomaly', False)
    n_trends = len((first.get('metrics') or {}).get('recent_trends', []))
    flag = ' ⚠️' if is_anomaly else ''
    trend_flag = f' [trend:{n_trends}]' if n_trends else ''
    print(f'  [{g[\"service\"]}] {g[\"action\"]} x{g[\"count\"]} | {target} | {val} {unit} | {time_str[:16]}{flag}{trend_flag}')
"
```
 
Targeted detail parse (Oura, Untappd, Spotify):
 
```bash
cat <filepath> | python3 -c "
import json, sys
raw = json.load(sys.stdin)
data = json.loads(raw[0]['text'])
 
for g in data.get('groups', []):
    if g['service'] in ['oura', 'untappd']:
        first = g.get('first_event', {})
        print(f'=== [{g[\"service\"]}] {g[\"action\"]} ===')
        for b in first.get('blocks', []):
            print(f'  {b[\"title\"]}: {b.get(\"value\")} {b.get(\"unit\",\"\")}')
        metrics = first.get('metrics') or {}
        print(f'  vs_baseline: {metrics.get(\"vs_baseline_pct\")}%')
        for t in metrics.get('recent_trends', []):
            print(f'  trend: {t}')
 
for g in data.get('groups', []):
    if g['service'] == 'spotify':
        for ev in g.get('all_events', []):
            t = ev.get('target', {})
            print(f'{ev[\"time\"][:16]} | {t.get(\"title\")} | {t.get(\"content\",\"\")[:60]}')
"
```