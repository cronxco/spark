# Flint Improvement Proposal: From Data Reporter to Insight Partner

## Executive Summary

Flint currently suffers from three critical issues that prevent it from being genuinely useful:
1. **Dry, superficial insights** - Observations that merely restate obvious facts without deeper meaning
2. **Duplicative content** - Same observations repeated across morning/afternoon digests
3. **Verbose, rambling digests** - Run-on narratives that obscure key takeaways

This proposal outlines a comprehensive redesign to transform Flint from a data reporter into an insightful partner that helps users understand what matters and why.

## Problem Analysis

### Current Issues (With Examples)

#### 1. Superficial Observations (Meta-Analysis Instead of Content Synthesis)
**Current output:**
> "You fetched/bookmarked 7 webpages yesterday (2025-12-29) and only 1 today (2025-12-30), a decline of ~85.7%. That suggests either a short pause in active article capture or a shift from discovery to deeper work (notes/outlines) today."

**Problems:**
- Tells you ABOUT your behavior, not ABOUT the content you're consuming
- Obvious statement of what the data shows (you can count your own bookmarks)
- Speculative conclusion without evidence
- No actionable insight
- Percentage calculation adds false precision
- **FUNDAMENTALLY WRONG APPROACH**: User wants synthesis of *what* they're reading, not *how much*

**What the user actually wants:**
> "📚 Emerging research focus: AI infrastructure economics
>
> Your recent reading explores the capital intensity of AI training (£100M+ per frontier model) and inference costs. Three articles converge on a key tension: current LLM architectures don't scale economically for mass deployment.
>
> Key debate: Will efficiency gains (quantization, distillation) outpace demand growth, or do we need architectural breakthroughs? Two researchers argue for neuromorphic approaches; one suggests we're hitting a Moore's Law equivalent for transformers.
>
> → Worth watching: Groq's deterministic architecture and Google's TPU v5 economics"

#### 2. Duplication Across Digests
**Morning insight:**
> "Apple Health reported a maximum heart rate of 200 bpm for 2025-12-29 while Oura's max heart rate that day was 147 bpm; average heart-rate measures are otherwise in the mid-60s to low-90s. This large discrepancy suggests either a sensor spike/artifact in Apple Health or an unusually high exertion/arrhythmia episode — check workout timestamps and how you felt at that moment."

**Afternoon insight (same day):**
> "Apple Health shows a maximum heart rate of 200 bpm on 2025-12-29 while that day's average heart rate values were much lower — this large spike stands out relative to surrounding metrics and may be an exercise peak or a sensor artifact."

**Problems:**
- Same observation repeated with slight rewording
- No new information or analysis in afternoon version
- Wastes user's time reading duplicate content

#### 3. Overly Verbose Digests
**Current headline (241 words!):**
> "Today shows you in a deliberate setup phase: you're recovering well from heavy training, quietly optimizing your money systems, and sharpening your learning focus around AI, infrastructure, and geopolitics. On the health side, your body is bouncing back strongly from a high-volume leg session, even though there was a slight uptick in night-time breathing disturbance. Combined with the 29 Dec HR spike and HRV drop, this points to a possible short-term overreach rather than anything systemic, so a small adjustment in training load and closer monitoring over the next few days is wise. Financially, things look calm and systematic. Automated micro-savings are ticking along (around £7.48 moved into pots over two days), and your mix of a BA Amex rewards card plus entertainment subscriptions (~£39) suggests you're structuring everyday spending to earn incremental value while still enjoying your downtime. The big outlier is the £251k 'Gift from Dad' pot: it sits alongside your current reading on AI, infra, and markets, creating a clear knowledge–capital loop where what you learn could meaningfully shape how you deploy that capital. Your knowledge work today tilted heavily toward geopolitics and defense, but reading volume dropped sharply versus yesterday. That's not necessarily bad—today looks more like a consolidation day than a big push. Overall, low financial strain plus decent recovery suggests health fluctuations are mostly about training load and physiology, not money stress. The theme for tomorrow: protect your recovery, formalize the big financial picture, and bring your learning volume back to a sustainable, steady level."

**Problems:**
- Single run-on paragraph attempting to synthesize everything
- Buried actionable items ("protect your recovery")
- Too much detail for a headline
- Exhausting to read
- No clear hierarchy of importance

## Fundamental Shift: From Behavioral Tracking to Content Synthesis

### The Core Problem

Flint currently operates as a **behavior tracker** when it should be a **knowledge synthesizer**.

**Current approach (WRONG):**
- "You bookmarked 7 articles" → Meta-analysis of behavior
- "You spent £156 on dining" → Numerical summary
- "You completed 12 tasks" → Activity counting

**Correct approach:**
- **Knowledge domain**: Synthesize the *content* of articles, identify themes, extract key arguments
- **Health domain**: Interpret the *meaning* of metrics in context (training load, recovery status)
- **Money domain**: Understand spending *patterns* and their implications, not just totals
- **Media domain**: Identify musical themes, mood patterns, discovery trends
- **Online domain**: Surface project momentum, blockers, meaningful progress

### Domain-Specific Transformations

#### Knowledge Domain: From Activity Counter to Research Assistant

**NEVER DO THIS:**
- ❌ "You bookmarked 7 articles on AI" (counting)
- ❌ "Your reading volume declined 85.7%" (meta-analysis)
- ❌ "You're reading about geopolitics" (obvious categorization)

**ALWAYS DO THIS:**
- ✅ Read the actual content of bookmarked articles
- ✅ Extract key arguments, findings, and claims
- ✅ Identify themes and connections across articles
- ✅ Highlight debates, contradictions, or converging evidence
- ✅ Surface actionable insights or implications
- ✅ Connect to user's other interests or activities

**Example transformation:**

❌ **Before:**
> "You've been exploring AI/ML content heavily (8 articles saved), suggesting a new learning focus compared to your usual web development topics."

✅ **After:**
> "🧠 AI Economics & Infrastructure Themes
>
> Your reading this week explores three interconnected questions:
>
> 1. **Capital requirements**: Training GPT-4-class models now costs $100M+. Two articles argue this creates a natural oligopoly; one counters that open-source distillation democratizes access.
>
> 2. **Inference economics**: Current LLMs cost $0.01-0.10 per query at scale. For consumer apps to work, this needs to drop 10-100x. Article from a16z suggests MoE architectures as the path.
>
> 3. **Geopolitical implications**: Your bookmarked Foreign Affairs piece connects this to the chip export controls—argues AI infrastructure becomes as strategically important as energy infrastructure.
>
> **Key tension**: All three themes point to the same question: Is AI infrastructure a natural monopoly, or can we engineer around it?
>
> **Connects to**: Your £251k capital deployment question—infrastructure plays vs. application layer?"

#### Health Domain: From Metric Reporter to Performance Coach

**NEVER DO THIS:**
- ❌ "Your HRV was 65 today" (just reporting the number)
- ❌ "Heart rate averaged 145 bpm during workout" (obvious from device)

**ALWAYS DO THIS:**
- ✅ Interpret metrics in context of training load
- ✅ Identify recovery status and readiness
- ✅ Flag performance trends or concerns
- ✅ Connect to other life domains (sleep, stress, workload)

**Example:**

✅ **After:**
> "💪 Recovery Status: Green Light for Training
>
> HRV rebounded to 68 (from 58 post-workout), RHR dropped to 52 (baseline: 54). Your body has fully processed the Dec 29 leg session.
>
> Combined with 8.1 hrs sleep and low respiratory rate, all systems show readiness for another hard session. The earlier HR spike is explained by Strava data (HIIT intervals).
>
> → Safe to hit legs again today, or pivot to upper body if you prefer"

#### Money Domain: Flag Financial Issues Only

**NEVER DO THIS:**
- ❌ "You spent £156 on dining" (obvious from bank statement)
- ❌ Cross-domain connections ("spending correlates with reading patterns")
- ❌ Behavioral psychology without evidence

**ONLY FLAG:**
- ✅ Budget violations (if budgets configured)
- ✅ Cashflow warnings (projected to go negative)
- ✅ Unusual transactions (potential fraud)
- ✅ Forgotten subscriptions (recurring charges for unused services)

**Most of the time: Return ZERO insights** (and that's okay)

**Example:**

✅ **When there's an actual issue:**
> "⚠️ Cashflow warning
> Current spending pace projects £180 deficit by month-end. Three large transactions this week: £450 XYZ Ltd, £280 dining, £120 subscriptions.
> → Review discretionary spending or adjust budget"

✅ **When everything is fine:**
> [No Money insights - nothing requiring attention]

## Data Filtering Ground Rules

**Skip entirely:**
- Empty daynotes (no content = no insights)
- Outline documents (personal notes, not insight-worthy)
- Any data source with zero meaningful events

**Only surface insights when there's something genuinely worth saying.**

## Proposed Solutions

### 1. Use Existing Fetch Content for Knowledge Synthesis

**Fetch plugin already provides:**
- Full article text extraction
- Article summaries
- Metadata (title, author, publication, date)

**What Knowledge domain needs to do:**
- Access Fetch summaries for bookmarked articles
- Group related articles by theme
- Synthesize key points across articles
- Surface debates and tensions
- Present content clearly

**No new ContentSynthesisService needed - leverage what exists.**

**Example prompt for Knowledge domain:**
```markdown
You have access to these bookmarked articles with Fetch summaries:

Article 1: "Groq's Deterministic Architecture"
Summary: [Fetch summary]

Article 2: "AI Training Economics"
Summary: [Fetch summary]

Article 3: "Chip Export Controls"
Summary: [Fetch summary]

Synthesize:
1. What themes connect these articles?
2. What key debates or questions emerge?
3. What should the user know from this reading?

Present as: Title, key points from each article, connecting theme/tension, actionable takeaway.
NO behavioral meta-analysis ("you read X articles").
```

### 2. Weather Integration (NEW - Practical Utility)

Add genuinely useful weather warnings using Met Office DataHub API.

**Service implementation:**
```php
class WeatherService
{
    public function getForecast(float $lat, float $lon): array
    {
        // Call Met Office DataHub API
        // GET https://data.hub.api.metoffice.gov.uk/point/hourly
        // ?latitude={lat}&longitude={lon}
        // Return next 24 hours forecast
    }

    public function getNotableWeather(array $forecast): ?array
    {
        // Only flag if weather is notable:
        // - Heavy rain/storms
        // - Temperature extremes (>28°C or <2°C)
        // - High winds (>30mph)
        // - Poor visibility

        // Return null if weather unremarkable
    }
}
```

**Usage:**
- Get user's latest location from check-ins
- Fetch hourly forecast for next 24 hours
- Flag notable weather in morning digest
- Make it actionable

**Example insights:**
- ✅ "⛈️ Heavy rain 2-4 PM - reschedule outdoor run or pack waterproofs"
- ✅ "🌡️ Peak 32°C today - hydrate well before workout, consider indoor training"
- ✅ "❄️ Sub-zero temps tonight - layer up for evening commute"
- ❌ "It will be 18°C and cloudy" (unremarkable, skip it)

### 3. Insight Quality Framework

#### A. The "So What?" Test
Every insight must answer three questions:
1. **What?** - What happened (data)
2. **So what?** - Why it matters (context/meaning)
3. **Now what?** - What to do about it (action)

**Example transformation:**

❌ **Before:**
> "You fetched 7 webpages yesterday and 1 today, a decline of 85.7%."

✅ **After:**
> "📚 Shifted from discovery to depth
>
> After a week of heavy reading (7+ articles/day), you've switched to consolidation mode with just 1 bookmark today. This is a healthy pattern—suggests you're processing rather than hoarding.
>
> → No action needed; this is how learning should work"

#### B. Minimum Viability Criteria

Only surface an insight if it meets **at least two** of these criteria:

1. **Actionable** - User can do something specific with this information
2. **Surprising** - Reveals a non-obvious pattern or connection
3. **Meaningful** - Has real impact on health, productivity, or wellbeing
4. **Timely** - Relevant to current context or requires immediate attention
5. **Comparative** - Shows meaningful change from baseline (>20% deviation)

**Quality filters:**
- Confidence score ≥ 0.7 (raised from 0.6)
- Must reference specific data points (numbers, dates, comparisons)
- Cannot be derivable from a single data point alone
- Must pass human readability test (conversational, not robotic)

#### C. Insight Categories & Templates

**Pattern Insight** (recurring behavior):
```
🔄 [Pattern Name]
[2-sentence description with specific data]
→ [Single actionable takeaway or "This is working well"]
```

**Anomaly Insight** (unusual event):
```
⚠️ [Anomaly Name]
[What's different + context/comparison]
→ [Recommended action or investigation]
```

**Trend Insight** (directional change):
```
📈/📊 [Trend Name]
[Direction + magnitude + timeframe]
→ [Whether to reinforce or correct]
```

**Connection Insight** (cross-domain):
```
🔗 [Domains]: [Connection Name]
[How they're related with evidence]
→ [What this means for behavior]
```

### 2. Deduplication Strategy

#### A. Temporal Insight Memory

**Implementation:**
- Store hash of each insight's core observation (domain + key metrics + conclusion)
- Before surfacing an insight, check if similar insight was shown in past 24 hours
- If duplicate detected:
  - **Option 1:** Skip entirely if no new information
  - **Option 2:** Show "Update" variant with only the delta

**Example:**

Morning insight:
> "⚠️ Heart rate spike investigation needed
> Apple Health logged 200 bpm max HR on Dec 29, while Oura recorded 147 bpm. Your typical max is 165 bpm during hard efforts.
> → Check your workout log around 2-3 PM on Dec 29—likely a sensor glitch or you pushed harder than usual"

Afternoon (if same data):
> [SUPPRESSED - no new information]

Afternoon (if new data available):
> "📊 Update: Heart rate spike
> Strava confirms a HIIT session at 2:17 PM on Dec 29 with avg HR 178 bpm—explains the Apple Health spike. Not a sensor error.
> → All clear; normal training response"

#### B. Cross-Digest Similarity Detection

**Algorithm:**
1. Extract key entities from each insight (metrics, dates, services, conclusions)
2. Calculate semantic similarity score
3. If similarity > 85%, mark as duplicate
4. Apply temporal rules:
   - Same day, different period: Skip if no new data
   - Different day: OK to repeat if part of ongoing pattern (max 1x/week)

### 3. Digest Redesign

#### A. Structure Overhaul

**Current:** Single rambling narrative
**Proposed:** Scannable, hierarchical structure

```markdown
# Daily Digest - [Date] [AM/PM]

## 🎯 Today's Theme
[Single sentence capturing the essence - max 15 words]

## 📊 Key Insights (Top 3)
1. [Most important insight with icon]
2. [Second most important]
3. [Third most important]

## ✅ Wins
- [Positive pattern or achievement]
- [Another win if applicable]

## ⚠️ Watch Points
- [Area needing attention - only if actionable]

## 🎬 For Tomorrow
- [Top priority action]
- [Secondary action if needed]

---
*[N] insights analyzed • [M] patterns detected • [X] actions recommended*
```

#### B. Writing Style Guidelines

**Tone:**
- Conversational but not cutesy
- Direct and clear
- Supportive without being patronizing
- Data-informed but not data-obsessed

**Rules:**
- Max 2 sentences per insight
- One idea per bullet point
- Use numbers sparingly (only when they add meaning)
- Avoid percentages unless >20% change
- Never say "suggests" or "might" - be definitive or don't say it
- Active voice only

**Example transformation:**

❌ **Before:**
> "Financially, things look calm and systematic. Automated micro-savings are ticking along (around £7.48 moved into pots over two days), and your mix of a BA Amex rewards card plus entertainment subscriptions (~£39) suggests you're structuring everyday spending to earn incremental value while still enjoying your downtime."

✅ **After:**
> "💰 Financial systems on autopilot
> Your automated savings and reward card setup is working quietly in the background—no action needed."

#### C. Headline Formula

**Current:** Attempts to synthesize everything into one paragraph
**Proposed:** Single sentence following this template:

```
[Primary domain status] + [notable pattern or anomaly] + [emotional valence]
```

**Examples:**
- ✅ "Strong recovery day after heavy training, with knowledge work shifting to consolidation mode"
- ✅ "Sleep disruption affecting productivity, but financial systems running smoothly"
- ✅ "Balanced day across all domains with emerging interest in geopolitical content"
- ❌ "Today shows you in a deliberate setup phase: you're recovering well from..." (too long!)

### 4. Agent Prompt Refinement

#### A. Domain Agent Prompts

**Add to every domain agent prompt:**

```markdown
## Quality Bar - READ THIS FIRST

You are held to an extremely high standard. Most observations should be DISCARDED.

Only surface an insight if:
1. It would genuinely change the user's behavior or understanding
2. It reveals something non-obvious from the data
3. It's actionable or meaningfully informative

**REJECT these types of observations:**
- Obvious facts visible in raw data ("You did X today")
- Vague speculation ("This might suggest...")
- Low-confidence patterns (confidence < 0.7)
- Generic wellness advice not tied to specific data
- Percentage changes < 20% (unless critically important)

**If you have nothing meaningful to say, return an empty insights array.**
It is BETTER to return zero insights than one mediocre insight.

## Output Format

Each insight MUST follow this structure:
```json
{
  "type": "pattern|anomaly|trend|connection",
  "icon": "emoji representing the insight type",
  "title": "3-5 word title",
  "observation": "What happened (1 sentence, specific data)",
  "meaning": "Why it matters (1 sentence, context)",
  "action": "What to do (1 sentence) or 'No action needed'",
  "confidence": 0.7-1.0,
  "supporting_data": ["specific metric 1", "specific metric 2"],
  "referenced_event_ids": ["uuid-1", "uuid-2"]
}
```
```

#### B. Cross-Domain Synthesizer Prompt

**Key changes:**
- Require minimum 0.75 confidence for cross-domain observations (up from 0.6)
- Must demonstrate causal relationship or strong correlation
- Cannot simply note that two things happened on the same day
- Must include specific mechanism connecting the domains

**Example:**

❌ **Rejected:**
> "Poor sleep (6.2 hrs) and high spending (£156) both occurred this week"
> - Not causally linked, just coincidental

✅ **Accepted:**
> "Sleep dropped to 6.2 hrs/night this week (from 7.5 hr baseline), corresponding with 12% HRV decrease. This preceded a 40% drop in task completion rate, suggesting fatigue is impacting productivity."
> - Clear causal chain with specific metrics

#### C. Digest Generation Prompt

**Replace current verbose prompt with:**

```markdown
You are creating a daily digest that busy people will actually read.

## Constraints
- Headline: 15 words maximum
- Key insights: Top 3 only (more is noise)
- Each insight: 2 sentences maximum
- Total digest: Readable in < 90 seconds

## Writing Rules
1. Be definitive (remove "suggests", "might", "could be")
2. Lead with impact, not data
3. One idea per bullet
4. Use specific numbers only when they add meaning
5. Skip obvious observations entirely

## Structure
Your response must follow this exact JSON structure:
{
  "headline": "Single sentence under 15 words capturing today's essence",
  "theme": "2-3 word theme (e.g., 'Recovery Day', 'High Output', 'Consolidating')",
  "top_insights": [
    {
      "icon": "emoji",
      "title": "3-5 words",
      "description": "Max 2 sentences",
      "action_needed": "Brief action or 'None'"
    }
  ],
  "wins": ["Concise win 1", "Concise win 2"],
  "watch_points": ["Only if actionable"],
  "tomorrow_focus": ["Top 1-2 priorities"]
}

Remember: **Brevity is respect for the reader's time.**
```

### 5. Technical Implementation Plan

#### Phase 0: Context Metrics Enhancement (Week 1) - ✅ **COMPLETE**

**Status:** Fully implemented and tested (2025-12-30)

**Problem:** Domain agents receive raw events but proposal examples require statistics like "down 18% from 30-day average." LLMs are terrible at arithmetic and aggregation.

**Solution:** Leverage existing `MetricStatistic` and `MetricTrend` models and embed within events.

See detailed specification in [CONTEXT_METRICS_PROPOSAL.md](./CONTEXT_METRICS_PROPOSAL.md)

**What was done:**

1. **✅ Metrics embedded within events:**
   - Added `loadMetricsForEvents()` method to pre-load statistics
   - Added `formatMetricsForEvent()` method to format metrics structure
   - Metrics embedded directly in event JSON (not separate array)
   - Includes: baseline (mean/min/max/stddev), normal_bounds, vs_baseline, vs_baseline_pct, is_anomaly, recent_trends

2. **✅ Leverages existing infrastructure:**
   - Uses `MetricStatistic` model (min, max, mean, stddev, normal bounds)
   - Uses `MetricTrend` model (weekly/monthly/quarterly trends, anomalies)
   - Requires 30+ days data, 10+ events minimum
   - Background jobs keep metrics updated

3. **✅ Performance optimized:**
   - Single query per unique metric (service::action::unit)
   - Metrics cached by key for entire timeframe
   - Trends filtered to last 7 days, unacknowledged only

**Tests added:**
- 8 comprehensive tests for metrics embedding
- Tests cover: presence, fallback, calculations, anomalies, trends

**Files changed:**
- `app/Services/AssistantContextService.php` - Metrics embedding
- `tests/Unit/AssistantContextServiceTest.php` - Metrics tests

**Why this came first:**
- Enables accurate comparisons in all domains
- Makes proposal examples achievable
- Prevents LLM hallucination of statistics
- Required for quality insights

#### Phase 1: Quality Filters & Data Filtering (Week 1) - ✅ **COMPLETE**

**Status:** Fully implemented and tested (2025-12-31)

**What was done:**

1. **✅ Data filtering rules implemented:**
   - Added `shouldExcludeByContent()` method to filter low-value events
   - Filters empty Obsidian daynotes (< 50 chars after stripping HTML)
   - Filters Outline plugin documents (auto-generated structure)
   - Maintains daynotes with substantial content

2. **✅ `DomainAgentService.php` updated:**
   - Added `isMetaAnalysis()` method to detect behavioral counting
   - Raised confidence threshold from 0.6 to 0.7
   - Added "So What?" test to quality criteria (criterion #6)
   - Added META-ANALYSIS BLOCKER section with examples
   - Zero insights now acceptable (better than noise)

3. **✅ `parseAgentResponse()` enhanced:**
   - Filters insights with confidence < 0.7
   - Filters meta-analysis patterns (bookmarks, tasks, webpages)
   - Tracks filtered count via `quality_filtered_count` field
   - Combined filtering: confidence AND meta-analysis check

4. **✅ Knowledge domain prompt updated:**
   - Emphasizes content synthesis over behavioral counting
   - Explicit DO/DON'T examples for content analysis
   - References existing Fetch blocks (summaries, key takeaways, tags)
   - Focuses on WHAT user reads, not HOW MUCH

**Tests added:**
- 5 tests for meta-analysis detection patterns
- 2 tests for confidence threshold filtering
- 3 tests for content-based event filtering
- All tests verify proper allowance of valid insights

**Files changed:**
- `app/Services/DomainAgentService.php` - Quality filtering logic
- `app/Services/AssistantContextService.php` - Content filtering
- `tests/Unit/Services/DomainAgentServiceTest.php` - Quality tests
- `tests/Unit/AssistantContextServiceTest.php` - Content filter tests

#### Phase 2: Weather Integration (Week 1-2)
1. **Create `WeatherService.php`:**
   - Integrate Met Office DataHub API
   - Fetch hourly forecast for user location
   - Flag notable weather only (rain, extremes, storms)
   - Return null if weather unremarkable

2. **Add to morning digest generation:**
   - Get user's latest location from check-ins
   - Call WeatherService
   - Surface actionable weather warnings
   - Skip if weather is normal

#### Phase 3: Knowledge Domain Synthesis (Week 2)

**REVISED UNDERSTANDING:** Fetch summaries are already in the context! Events include blocks, and Fetch creates 5 summary blocks (`fetch_summary_paragraph`, `fetch_key_takeaways`, etc.) attached to bookmark events.

**No context service changes needed.** Just update the prompt:

1. **Update Knowledge domain agent prompt:**
   - Explicitly show how to access block metadata from context
   - Example: `groups[x].all_events[y].blocks` contains summary blocks
   - Instruct to read `metadata.content` from `fetch_summary_paragraph` and `fetch_key_takeaways` blocks
   - Group articles by theme using topics from `metrics.content.top_topics_7d`
   - Synthesize across related articles
   - Surface debates and key questions
   - **CRITICAL:** NO behavioral counting ("you read X articles")
   - **CRITICAL:** YES content synthesis ("Your reading explores X theme...")

#### Phase 4: Deduplication (Week 2-3)
1. **Create `InsightDeduplicationService.php`:**
   - Implement insight hashing
   - Add temporal memory (24hr cache)
   - Build similarity detection algorithm

2. **Update `AgentOrchestrationService.php`:**
   - Check for duplicates before creating blocks
   - Implement update/suppress logic
   - Add duplicate tracking metrics

#### Phase 5: Digest Redesign (Week 3)
1. **Update digest generation prompt:**
   - New scannable structure (Theme, Top 3 Insights, Wins, Watch Points)
   - 15-word headline maximum
   - Max 2 sentences per insight
   - Include weather warnings if notable

2. **Update digest response parsing:**
   - Handle new structure
   - Create appropriate blocks

3. **Update Blade templates:**
   - New presentation format
   - Weather warning display

#### Phase 6: Domain-Specific Refinement (Week 3-4)
1. **Health domain**: Performance coaching prompts (recovery status, training readiness)
2. **Money domain**: Only flag actual financial issues (budget violations, cashflow warnings)
3. **Media domain**: If insights are low-value, consider reducing priority
4. **Online domain**: Project momentum and blockers only
5. **Drop or minimize cross-domain synthesizer**: Most connections are artificial

#### Phase 7: Digest Tab UI Implementation (Week 4)
1. **Implement Digest tab in `/flint` route (currently placeholder):**
   - Replace "Your most recent AI-generated digest will appear here" with actual implementation
   - Display latest digest in scannable format (Theme, Top 3 Insights, Wins, Watch Points, Tomorrow Focus)
   - Weather warnings prominently displayed if present
   - Archive view of past 7-30 days of digests
   - Individual insight cards with expand/collapse
   - Feedback buttons on each insight (helpful/dismiss)

2. **Data loading:**
   - Query most recent digest blocks from database
   - Parse structured digest data
   - Handle empty state (no digests yet)

3. **Archive functionality:**
   - Calendar view or list of past digests
   - Quick preview on card
   - Click to expand full digest

#### Phase 8: Monitoring & Iteration (Week 5-6)
1. Add insight quality metrics dashboard
2. Track user engagement (read time, feedback)
3. Monitor duplication rate
4. Track content synthesis quality (theme accuracy, insight relevance)
5. Collect user feedback
6. Iterate based on data

### 6. Success Metrics

**Quality Metrics:**
- Average insight confidence score > 0.75
- Zero duplicate insights within 24hr window
- Digest read time < 90 seconds
- Insight-to-action ratio > 0.6 (60% of insights have clear actions)
- **Content synthesis rate**: Knowledge insights reference actual article content (not just "you read X")
- **Meta-analysis rejection rate**: >90% of "you did X" insights filtered out

**Engagement Metrics:**
- User feedback rating > 4.0/5.0
- Dismissed insight rate < 10%
- Digest open rate > 80%
- Time spent on insights > 2 min/day
- **Content relevance**: User acts on synthesized insights (clicks through to articles, takes notes)

**System Metrics:**
- Insights generated per domain: 0-3 (quality over quantity)
- Digest length: < 200 words
- Processing time: < 30 seconds per digest
- **Zero-insight rate**: 40-60% of domains per day (most days, most domains have nothing meaningful to say)

### 7. Example: Before & After

#### Before

**Knowledge Insight (Morning - Behavioral Meta-Analysis):**
> "You fetched/bookmarked 7 webpages yesterday (2025-12-29) and only 1 today (2025-12-30), a decline of ~85.7%. That suggests either a short pause in active article capture or a shift from discovery to deeper work (notes/outlines) today."

**Health Insight (Morning - Duplicative):**
> "Apple Health reported a maximum heart rate of 200 bpm for 2025-12-29 while Oura's max heart rate that day was 147 bpm; average heart-rate measures are otherwise in the mid-60s to low-90s. This large discrepancy suggests either a sensor spike/artifact in Apple Health or an unusually high exertion/arrhythmia episode — check workout timestamps and how you felt at that moment."

**Health Insight (Afternoon, same day - Duplicate):**
> "Apple Health shows a maximum heart rate of 200 bpm on 2025-12-29 while that day's average heart rate values were much lower — this large spike stands out relative to surrounding metrics and may be an exercise peak or a sensor artifact."

**Digest Headline (241 words - Too verbose):**
> "Today shows you in a deliberate setup phase: you're recovering well from heavy training, quietly optimizing your money systems, and sharpening your learning focus around AI, infrastructure, and geopolitics. On the health side, your body is bouncing back strongly from a high-volume leg session, even though there was a slight uptick in night-time breathing disturbance..." [continues for 241 words]

#### After

**Knowledge Insight (Morning - Content Synthesis):**
> "🧠 AI Infrastructure Economics: Emerging Debate
>
> Your recent articles converge on a critical question: Can AI scale economically? Three pieces explore this:
>
> 1. Training costs now exceed $100M per frontier model (per your Semianalysis bookmark). Two authors argue this creates natural oligopoly.
> 2. Inference economics: Current LLM queries cost $0.01-0.10 at scale—needs 10-100x reduction for consumer viability (a16z analysis).
> 3. Geopolitical angle: Your Foreign Affairs piece connects chip export controls to AI infrastructure becoming as strategic as energy infrastructure.
>
> **Key tension**: Is AI infrastructure a natural monopoly, or can we engineer around it (open-source distillation vs. architectural breakthroughs)?
>
> → Connects to your £251k capital question: infrastructure layer vs. application bets?"

**Health Insight (Morning - Contextual):**
> "⚠️ Heart rate discrepancy
> Dec 29 shows 200 bpm max on Apple Health vs 147 bpm on Oura (your typical max during hard efforts is ~165 bpm). Cross-check your Strava log around 2-3 PM to see if you did a hard interval session.
> → Investigate to rule out sensor error"

**Health Insight (Afternoon, same day - Suppressed or Updated):**
> [SUPPRESSED - awaiting Strava cross-reference]

**Or, if new data available:**
> "✅ Update: Heart rate spike resolved
> Strava confirms HIIT session at 2:17 PM Dec 29, avg HR 178 bpm. Apple Health spike was legitimate.
> → No action needed"

**Digest:**
```markdown
# Daily Digest - Dec 30 AM

## 🎯 Today's Theme
Recovery & Deep Research

## 📊 Key Insights
1. 🧠 **AI infrastructure economics debate**
   Your reading explores a key tension: $100M+ training costs suggest natural oligopoly, but open-source advocates argue distillation democratizes access. Connects to your capital deployment question.

2. ✅ **Strong recovery from Dec 29 workout**
   HRV rebounding, RHR normalizing—body has processed the leg session. Green light for training today.

3. ⛈️ **Heavy rain this afternoon**
   Forecast shows 15mm rainfall 2-4 PM. Reschedule outdoor run or pack waterproofs.

## ✅ Wins
- Recovery metrics all trending positive
- Deep dive into AI economics yielding clear questions

## 🎬 For Tomorrow
- Continue light-to-moderate training to lock in recovery
- Consider: infrastructure vs. application layer for capital deployment

---
*8 insights analyzed • 3 themes identified • 2 cross-domain connections*
```

## Risks & Mitigations

### Risk 1: Over-filtering leads to no insights
**Mitigation:**
- Set minimum 1 insight per domain per day if any events exist
- Log all filtered insights for review
- Adjust thresholds based on feedback

### Risk 2: Users want more detail
**Mitigation:**
- Add "expand" option for each insight
- Provide raw data view for power users
- A/B test verbosity levels

### Risk 3: Deduplication too aggressive
**Mitigation:**
- Allow manual override in settings
- Track suppressed insights
- Provide "show suppressed" option

## Open Questions for Discussion

1. **Content Depth:** How deep should article synthesis go? Full multi-paragraph analysis vs. bullet-point summaries?

2. **Article Selection:** Should Flint synthesize ALL bookmarked articles or filter to the most substantial/relevant ones?

3. **Frequency:** Should we reduce digest frequency (e.g., once per day instead of AM/PM) to reduce duplication risk?

4. **Customization:** Should users be able to configure:
   - Verbosity level (concise/moderate/detailed)
   - Domains to emphasize (e.g., prioritize Knowledge over Media)
   - Content synthesis depth

5. **Learning:** How should Flint learn from user feedback? Explicit ratings, implicit engagement signals (clicks, time spent), or both?

6. **Proactivity:** Should Flint proactively ask clarifying questions (e.g., "I see a HR spike—did you do a hard workout?") or just note the discrepancy?

7. **Article Retention:** How long should article content be cached for synthesis? 7 days? 30 days? User-configurable?

## Next Steps

1. **Review & discuss** this proposal
2. **Prioritize** which phases to tackle first
3. **Prototype** new insight format with real data
4. **A/B test** old vs new approach with sample digests
5. **Implement** in phases with monitoring at each step

## Appendix: Insight Type Catalog

| Type | Icon | When to Use | Example |
|------|------|-------------|---------|
| Pattern | 🔄 | Recurring behavior over 3+ occurrences | "🔄 Consistent bedtime: 10:30 PM ± 15 min for 6 days straight" |
| Anomaly | ⚠️ | Deviation >2σ from baseline | "⚠️ Spending spike: 3x your weekly average on dining" |
| Trend | 📈📉 | Directional change over 7+ days | "📉 Sleep declining: 7.5→6.8→6.2 hrs over 3 weeks" |
| Connection | 🔗 | Cross-domain relationship | "🔗 Health→Productivity: Poor sleep week → 40% fewer tasks completed" |
| Win | ✅ | Positive achievement or milestone | "✅ 7-day workout streak—longest this quarter" |
| Investigation | 🔍 | Data discrepancy requiring input | "🔍 Conflicting data: Verify Dec 29 workout intensity" |

---

**Document Version:** 1.0
**Date:** 2025-12-30
**Author:** Claude (Flint Improvement Working Group)
**Status:** Draft for Review
