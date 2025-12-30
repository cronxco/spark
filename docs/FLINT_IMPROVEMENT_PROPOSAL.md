# Flint Improvement Proposal: From Data Reporter to Insight Partner

## Executive Summary

Flint currently suffers from three critical issues that prevent it from being genuinely useful:
1. **Dry, superficial insights** - Observations that merely restate obvious facts without deeper meaning
2. **Duplicative content** - Same observations repeated across morning/afternoon digests
3. **Verbose, rambling digests** - Run-on narratives that obscure key takeaways

This proposal outlines a comprehensive redesign to transform Flint from a data reporter into an insightful partner that helps users understand what matters and why.

## Problem Analysis

### Current Issues (With Examples)

#### 1. Superficial Observations
**Current output:**
> "You fetched/bookmarked 7 webpages yesterday (2025-12-29) and only 1 today (2025-12-30), a decline of ~85.7%. That suggests either a short pause in active article capture or a shift from discovery to deeper work (notes/outlines) today."

**Problems:**
- Obvious statement of what the data shows
- Speculative conclusion without evidence
- No actionable insight
- Percentage calculation adds false precision

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

## Proposed Solutions

### 1. Insight Quality Framework

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

#### Phase 1: Quality Filters (Week 1)
1. Update `DomainAgentService.php`:
   - Add insight validation method
   - Implement "So What?" test logic
   - Raise confidence threshold to 0.7
   - Add minimum viable criteria checks

2. Update `parseAgentResponse()`:
   - Add quality scoring function
   - Filter out low-quality insights before storage
   - Log rejected insights for analysis

#### Phase 2: Deduplication (Week 1-2)
1. Create `InsightDeduplicationService.php`:
   - Implement insight hashing
   - Add temporal memory (24hr cache)
   - Build similarity detection algorithm

2. Update `AgentOrchestrationService.php`:
   - Check for duplicates before creating blocks
   - Implement update/suppress logic
   - Add duplicate tracking metrics

#### Phase 3: Digest Redesign (Week 2)
1. Update digest generation prompt in `AgentOrchestrationService.php`
2. Modify `parseDigestResponse()` to handle new structure
3. Update `GenerateDailyDigestJob.php` to create blocks for new format
4. Update Blade templates for new presentation

#### Phase 4: Prompt Engineering (Week 2-3)
1. Rewrite all domain agent prompts
2. Update cross-domain synthesizer prompt
3. Refine action prioritization prompt
4. A/B test old vs new prompts

#### Phase 5: Monitoring & Iteration (Week 3-4)
1. Add insight quality metrics dashboard
2. Track user engagement (read time, feedback)
3. Monitor duplication rate
4. Collect user feedback
5. Iterate based on data

### 6. Success Metrics

**Quality Metrics:**
- Average insight confidence score > 0.75
- Zero duplicate insights within 24hr window
- Digest read time < 90 seconds
- Insight-to-action ratio > 0.6 (60% of insights have clear actions)

**Engagement Metrics:**
- User feedback rating > 4.0/5.0
- Dismissed insight rate < 10%
- Digest open rate > 80%
- Time spent on insights > 2 min/day

**System Metrics:**
- Insights generated per domain: 0-3 (quality over quantity)
- Digest length: < 200 words
- Processing time: < 30 seconds per digest

### 7. Example: Before & After

#### Before

**Health Insight (Morning):**
> "Apple Health reported a maximum heart rate of 200 bpm for 2025-12-29 while Oura's max heart rate that day was 147 bpm; average heart-rate measures are otherwise in the mid-60s to low-90s. This large discrepancy suggests either a sensor spike/artifact in Apple Health or an unusually high exertion/arrhythmia episode — check workout timestamps and how you felt at that moment."

**Health Insight (Afternoon, same day):**
> "Apple Health shows a maximum heart rate of 200 bpm on 2025-12-29 while that day's average heart rate values were much lower — this large spike stands out relative to surrounding metrics and may be an exercise peak or a sensor artifact."

**Digest Headline:**
> "Today shows you in a deliberate setup phase: you're recovering well from heavy training, quietly optimizing your money systems, and sharpening your learning focus around AI, infrastructure, and geopolitics. On the health side, your body is bouncing back strongly from a high-volume leg session, even though there was a slight uptick in night-time breathing disturbance..." [continues for 241 words]

#### After

**Health Insight (Morning):**
> "⚠️ Heart rate discrepancy
> Dec 29 shows 200 bpm max on Apple Health vs 147 bpm on Oura (your typical max during hard efforts is ~165 bpm). Cross-check your Strava log around 2-3 PM to see if you did a hard interval session.
> → Investigate to rule out sensor error"

**Health Insight (Afternoon, same day):**
> [SUPPRESSED - awaiting Strava cross-reference]

**Or, if new data available:**
> "✅ Update: Heart rate spike resolved
> Strava confirms HIIT session at 2:17 PM Dec 29, avg HR 178 bpm. Apple Health spike was legitimate.
> → No action needed"

**Digest:**
```markdown
# Daily Digest - Dec 30 AM

## 🎯 Today's Theme
Recovery & Consolidation

## 📊 Key Insights
1. ✅ **Strong recovery metrics**
   Body bouncing back well from Dec 29 leg session—HRV rising, RHR normalizing. Light day or rest recommended.

2. 📚 **Learning mode shift**
   After a week of heavy reading, you're in consolidation mode (1 bookmark vs usual 7+). Healthy pattern.

3. ⚠️ **Heart rate data conflict**
   Apple Health vs Oura discrepancy on Dec 29—cross-check Strava to verify.

## ✅ Wins
- Recovery trending positive after heavy training
- Financial systems running on autopilot

## 🎬 For Tomorrow
- Keep training load light to lock in recovery
- Verify heart rate spike with workout data

---
*12 insights analyzed • 3 patterns detected • 2 actions recommended*
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

1. **Personality:** How much personality should Flint have? Should it be more neutral/professional or more conversational/friendly?

2. **Frequency:** Should we reduce digest frequency (e.g., once per day instead of AM/PM) to reduce duplication risk?

3. **Customization:** Should users be able to configure verbosity level (concise/moderate/detailed)?

4. **Learning:** How should Flint learn from user feedback? Explicit ratings, implicit engagement signals, or both?

5. **Proactivity:** Should Flint proactively ask clarifying questions (e.g., "I see a HR spike—did you do a hard workout?") or just note the discrepancy?

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
