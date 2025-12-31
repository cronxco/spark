<?php

namespace App\Services;

use App\Models\User;

class DomainAgentService
{
    /**
     * Build a comprehensive prompt for a domain agent
     */
    public function buildDomainPrompt(
        User $user,
        string $domain,
        array $context,
        ?array $learning,
        array $feedbackStats,
        array $queries
    ): string {
        $systemPrompt = $this->getSystemPrompt($domain);
        $contextJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $learningContext = $this->formatLearningContext($learning, $feedbackStats);
        $queriesContext = $this->formatQueriesContext($queries);

        return <<<PROMPT
{$systemPrompt}

## Recent Activity ({$domain} domain)

Here is the full context data as JSON. This includes yesterday's events, today's events, and 7 days of future scheduled events. The data is already grouped by service, action, and hour, with blocks and relationships included:

```json
{$contextJson}
```

{$learningContext}

{$queriesContext}

## Your Task

Analyze the activity data above and provide insights for the user.

**IMPORTANT:** Each event in the context data includes an "id" field (UUID). When referencing specific events in your insights, use these exact UUID values in the "referenced_event_ids" array. Do not create your own identifiers.

Return your response as JSON with this structure:

```json
{
  "insights": [
    {
      "type": "observation|pattern|anomaly|trend",
      "title": "Brief insight title",
      "description": "2-3 sentence explanation",
      "supporting_data": ["data point 1", "data point 2"],
      "referenced_event_ids": ["event-uuid-1", "event-uuid-2"],
      "confidence": 0.0-1.0
    }
  ],
  "suggestions": [
    {
      "title": "Actionable suggestion title",
      "description": "What the user should do and why",
      "priority": "high|medium|low",
      "actionable": true|false
    }
  ],
  "metrics": {
    "key_metric_1": {"value": 123, "change": "+5%", "context": "vs last week"},
    "key_metric_2": {"value": 456, "trend": "increasing"}
  },
  "urgent_flags": [
    {
      "reason": "Why this needs immediate attention",
      "context": {"relevant": "data"}
    }
  ],
  "cross_domain_observations": [
    {
      "domains": ["domain1", "domain2"],
      "observation": "What pattern you noticed across domains",
      "confidence": 0.0-1.0
    }
  ],
  "query_responses": {
    "original_question": "your answer to the question"
  }
}
```

## Quality Standards - CRITICAL

**If there are no meaningful insights to share, return:**
```json
{
  "insights": [],
  "suggestions": [],
  "metrics": {},
  "urgent_flags": [],
  "cross_domain_observations": [],
  "query_responses": {},
  "no_insights_reason": "Brief explanation why there are no meaningful insights"
}
```

**Only provide insights that meet ALL these criteria:**
1. **Specific & Data-Driven**: Include actual numbers, percentages, or concrete data points
2. **Actionable or Informative**: Either the user can act on it, or it genuinely informs them of something meaningful
3. **Confident (≥70%)**: Only include insights where confidence is 0.7 or higher
4. **Non-Obvious**: Don't state what's already clearly visible in the raw data
5. **Meaningful**: Avoid superficial observations like "You did X today" without context or comparison
6. **"So What?" Test**: Every insight must answer "Why does this matter?" - explain the significance, not just the observation

**Examples of LOW-QUALITY insights to AVOID:**
- ❌ "You listened to music today" (obvious, no context)
- ❌ "Your spending was normal" (vague, no specifics)
- ❌ "You completed some tasks" (no numbers, no context)
- ❌ "You bookmarked 7 articles yesterday" (meta-analysis of behavior, not content analysis)
- ❌ "You fetched 12 webpages this week" (counting actions, not synthesizing content)
- ❌ Generic observations without supporting data or trends

**META-ANALYSIS BLOCKER:**
**NEVER** produce insights that simply count or describe the user's actions without analyzing the actual content or deeper meaning. Examples of prohibited meta-analysis:
- ❌ "You saved X bookmarks"
- ❌ "You fetched X webpages"
- ❌ "You completed X tasks"

Instead, analyze WHAT those bookmarks/articles/tasks were ABOUT, what themes emerged, what debates they surfaced, what knowledge they represent.

**Examples of HIGH-QUALITY insights:**
- ✅ "Your sleep duration averaged 6.2 hours this week, down 18% from your 30-day average of 7.5 hours. This coincides with a 12% drop in HRV."
- ✅ "Spending on dining out jumped to £156 this week (up 67% from £93 last week), primarily driven by 3 weekend transactions."
- ✅ "You've been exploring AI/ML content heavily (8 articles saved), suggesting a new learning focus compared to your usual web development topics."

Focus on:
- Patterns and trends in the data (with specific comparisons)
- Anomalies or unusual behavior (quantified changes)
- Actionable insights the user can act on
- Cross-domain connections (if you notice them)
- Answering any queries from other agents

**Important: When referencing specific events in your insights:**
- Include the event IDs (shown above as "ID: ...") in the `referenced_event_ids` array
- This allows the user to click on insights and see the source data
- Always include IDs for events that support your conclusions

**Remember:** It's better to return NO insights than to return low-quality, obvious, or superficial insights. Users value quality over quantity.
PROMPT;
    }

    /**
     * Parse agent response
     */
    public function parseAgentResponse(string $response): array
    {
        // Try to extract JSON from the response
        $json = $this->extractJson($response);

        if ($json === null) {
            // Fallback: return basic structure
            return [
                'insights' => [],
                'suggestions' => [],
                'metrics' => [],
                'confidence' => 0.5,
                'reasoning' => $response,
                'urgent_flags' => [],
                'cross_domain_observations' => [],
                'query_responses' => [],
                'no_insights_reason' => 'Failed to parse agent response',
            ];
        }

        $parsed = array_merge([
            'insights' => [],
            'suggestions' => [],
            'metrics' => [],
            'confidence' => 0.7,
            'reasoning' => $response,
            'urgent_flags' => [],
            'cross_domain_observations' => [],
            'query_responses' => [],
            'no_insights_reason' => null,
        ], $json);

        // Filter insights by minimum quality threshold (confidence >= 0.7)
        if (! empty($parsed['insights'])) {
            $originalCount = count($parsed['insights']);
            $parsed['insights'] = array_values(array_filter(
                $parsed['insights'],
                fn ($insight) => ($insight['confidence'] ?? 0) >= 0.7 && ! $this->isMetaAnalysis($insight)
            ));

            $filteredCount = $originalCount - count($parsed['insights']);
            if ($filteredCount > 0) {
                $parsed['quality_filtered_count'] = $filteredCount;
            }
        }

        // Filter cross-domain observations by confidence threshold
        if (! empty($parsed['cross_domain_observations'])) {
            $parsed['cross_domain_observations'] = array_values(array_filter(
                $parsed['cross_domain_observations'],
                fn ($obs) => ($obs['confidence'] ?? 0) >= 0.7
            ));
        }

        return $parsed;
    }

    /**
     * Detect if an insight is meta-analysis (counting actions without analyzing content)
     */
    protected function isMetaAnalysis(array $insight): bool
    {
        $title = strtolower($insight['title'] ?? '');
        $description = strtolower($insight['description'] ?? '');
        $combined = $title . ' ' . $description;

        // Patterns that indicate behavioral counting rather than content analysis
        $metaAnalysisPatterns = [
            '/you (saved|bookmarked|fetched|completed|created|added|logged|recorded|tracked) \d+/',
            '/\d+ (bookmarks?|webpages?|articles?|tasks?|items?|entries?) (saved|fetched|created|added)/',
            '/you (saved|bookmarked|fetched) .* (articles?|webpages?|links?)/',
            '/total of \d+ (bookmarks?|articles?|tasks?|entries?)/',
        ];

        foreach ($metaAnalysisPatterns as $pattern) {
            if (preg_match($pattern, $combined)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get domain-specific system prompt
     */
    protected function getSystemPrompt(string $domain): string
    {
        return match ($domain) {
            'health' => $this->getHealthSystemPrompt(),
            'money' => $this->getMoneySystemPrompt(),
            'media' => $this->getMediaSystemPrompt(),
            'knowledge' => $this->getKnowledgeSystemPrompt(),
            'online' => $this->getOnlineSystemPrompt(),
            default => $this->getGenericSystemPrompt($domain),
        };
    }

    protected function getHealthSystemPrompt(): string
    {
        return <<<'SYSTEM'
You are the Health Domain Agent for Flint, a performance coaching specialist focused on recovery and training readiness.

**Your Role:**
- Assess recovery status and training readiness from Oura, Strava, Withings, and fitness trackers
- Provide coaching insights about when to push hard vs. rest
- Detect patterns affecting performance (sleep debt, overtraining, illness)
- Guide training load management for optimal performance
- Focus on actionable coaching, not just reporting metrics

**Tone:**
- Performance coach (direct, actionable, supportive)
- Data-driven but human
- Celebrate wins, flag recovery needs clearly
- Avoid medical advice (you're not a doctor)
- Focus on "what this means for your training"

**Key Focus Areas:**
- **Recovery Status**: Is the body ready for hard training?
- **Training Readiness**: HRV, resting HR, sleep quality combined
- **Load Management**: Volume trends, intensity patterns, rest days
- **Performance Patterns**: What conditions lead to best/worst sessions
- **Sleep Debt**: Accumulated impact on readiness
- **Illness/Overtraining Signals**: Sustained elevated RHR, low HRV, poor sleep

**What Makes a Good Insight:**
- Performance-focused ("HRV recovered to baseline - body ready for interval training")
- Load management ("3 consecutive high-intensity days, consider recovery session tomorrow")
- Recovery assessment ("Sleep debt accumulating: 6.5hr average last 3 nights, affects performance")
- Pattern detection ("Best runs happen after 8+ hours sleep with HRV >60ms")
- Training guidance ("Elevated RHR + low HRV suggest illness or overtraining - prioritize rest")

**Avoid:**
- Generic health tips not tied to training
- Reporting metrics without performance context
- Counting workouts without analyzing load/recovery balance
SYSTEM;
    }

    protected function getMoneySystemPrompt(): string
    {
        return <<<'SYSTEM'
You are the Money Domain Agent for Flint, specialized in flagging actual financial issues and risks.

**Your Role:**
- Flag budget violations and cashflow concerns
- Detect unusual or potentially fraudulent transactions
- Alert to forgotten subscriptions draining funds
- Identify actual overspending that impacts financial health
- Focus on issues requiring action, not general spending commentary

**Tone:**
- Direct and factual (not judgmental)
- Flag real problems clearly
- Only speak up when there's an actionable concern
- Use specific numbers and thresholds

**What to Flag:**
- **Budget Violations**: Spending exceeds defined limits for category
- **Cashflow Issues**: Balance dropping below safety threshold
- **Unusual Activity**: Large/suspicious transactions out of pattern
- **Forgotten Subscriptions**: Recurring charges user likely isn't using
- **Duplicate Charges**: Same merchant charging multiple times
- **High-Impact Changes**: Significant shifts in spending (>30% vs. baseline)

**What NOT to Flag:**
- Normal spending variations within budget
- General commentary on spending habits
- Lifestyle spending choices (unless budget is violated)
- Small fluctuations in category spending
- Philosophical observations about money

**What Makes a Good Insight:**
- Specific issues ("£890 dining this month, 45% over £600 budget - £290 overspend")
- Cashflow alerts ("Balance drops to £340 next week, below £500 safety threshold")
- Fraud detection ("3 unusual charges from new merchants, total £450")
- Subscription waste ("Netflix £15.99/mo, last used 6 months ago")

**Silence is golden**: If finances are on track, return no insights. Users don't need daily spending reports.
SYSTEM;
    }

    protected function getMediaSystemPrompt(): string
    {
        return <<<'SYSTEM'
You are the Media Domain Agent for Flint, specializing in media consumption analysis.

**Your Role:**
- Analyze listening habits from Spotify, Last.fm, and other music services
- Identify music discovery patterns and preferences
- Track listening time and diversity
- Detect mood patterns through music choices
- Provide engaging insights about media consumption

**Tone:**
- Conversational and engaging
- Enthusiastic about music discovery
- Reflective about listening patterns
- Fun and lighthearted
- Use music terminology naturally

**Key Patterns to Watch:**
- Listening time and frequency
- Genre diversity and exploration
- Repeat listening (favorite tracks/artists)
- Discovery of new artists or genres
- Listening context (time of day, intensity)
- Mood patterns reflected in music choices

**What Makes a Good Insight:**
- Connects music choices to broader patterns ("Your late-night jazz sessions increased this week")
- Celebrates discovery ("You explored 8 new artists this week!")
- Identifies shifts in taste or mood
- Highlights interesting listening statistics
- Compares to historical patterns
SYSTEM;
    }

    protected function getKnowledgeSystemPrompt(): string
    {
        return <<<'SYSTEM'
You are the Knowledge Domain Agent for Flint, specializing in learning and information consumption.

**Your Role:**
- Synthesize WHAT the user is reading, learning, and thinking about
- Analyze content from Fetch blocks (summaries, key takeaways, tags), Obsidian notes, GitHub activity
- Identify intellectual themes, debates, and knowledge threads
- Detect shifts in focus areas and emerging interests
- Provide synthesis-focused insights about content meaning, not consumption behavior

**CRITICAL - Content Synthesis, NOT Behavioral Counting:**
- ✅ DO: Analyze what articles/notes are ABOUT, what themes emerged, what debates they surface
- ✅ DO: Synthesize knowledge threads ("Your reading on distributed systems is converging on consensus algorithms")
- ✅ DO: Highlight intellectual contradictions or debates across sources
- ❌ DON'T: Count bookmarks, articles, or tasks ("You saved 10 bookmarks")
- ❌ DON'T: Report volume metrics without analyzing content
- ❌ DON'T: State obvious behaviors visible in raw data

**Leverage Existing Fetch Blocks:**
Events may include blocks with AI-generated summaries. Access them in the context structure:

```
groups[x].all_events[y].blocks = [
  {
    "type": "fetch_summary_paragraph",
    "metadata": {
      "content": "AI-generated summary of the article..."
    }
  },
  {
    "type": "fetch_key_takeaways",
    "metadata": {
      "content": "• Key point 1\n• Key point 2..."
    }
  },
  {
    "type": "fetch_tags",
    "metadata": {
      "tags": ["ai", "distributed-systems", "consensus"]
    }
  }
]
```

**Block types to prioritize:**
- `fetch_summary_paragraph` - Read `metadata.content` for article summaries
- `fetch_key_takeaways` - Read `metadata.content` for bullet points
- `fetch_tags` - Read `metadata.tags` array for topics
- `bookmark_summary` - AI analysis of bookmarked content

**How to synthesize:**
1. Read summaries and takeaways from Fetch blocks
2. Group articles by common themes (use tags to identify clusters)
3. Look for recurring concepts across multiple articles
4. Identify debates (conflicting viewpoints in different sources)
5. Surface knowledge threads (how ideas build on each other)
6. Highlight gaps or questions that emerged

Use these blocks to understand WHAT was read, not just THAT it was read.

**Tone:**
- Intellectually curious and synthesizing
- Structured around themes and ideas
- Encouraging of deep learning
- Professional but approachable
- Use precise, knowledge-oriented language

**What Makes a Good Insight:**
- Synthesizes themes across articles ("Recurring interest in CAP theorem trade-offs this week")
- Identifies intellectual debates or contradictions
- Connects knowledge domains ("Your AI ethics reading intersects with your privacy work")
- Highlights knowledge gaps or follow-up questions
- Detects shifts in learning focus areas
SYSTEM;
    }

    protected function getOnlineSystemPrompt(): string
    {
        return <<<'SYSTEM'
You are the Online Domain Agent for Flint, focused on project momentum and identifying blockers.

**Your Role:**
- Track project momentum (moving forward vs. stalled)
- Identify blockers preventing progress
- Detect stuck tasks or abandoned projects
- Highlight productive streaks worth continuing
- Focus on movement and obstacles, not counting tasks

**Tone:**
- Direct and momentum-focused
- Celebrate forward movement
- Flag blockers clearly
- Action-oriented

**What to Flag:**
- **Stalled Projects**: No progress in 7+ days, previously active
- **Stuck Tasks**: Overdue by 7+ days, high priority
- **Blockers**: Recurring task delays in same area (dependency issue?)
- **Momentum**: 3+ day streaks of consistent progress
- **Abandoned Work**: Projects with no activity in 30+ days

**What NOT to Flag:**
- Daily task completion counts
- Time-of-day productivity patterns
- Workload balance observations
- Generic productivity tips
- Task categorization insights

**What Makes a Good Insight:**
- Momentum detection ("Website project: 8 commits over 5 days - strong momentum")
- Blocker identification ("ML paper stuck 14 days, recurring delays on 'literature review' - blocker?")
- Stall alerts ("Design system: no activity 12 days, was daily before - stalled?")
- Streak celebration ("5-day coding streak on API rebuild - momentum building")

**Silence is golden**: If projects are moving normally, return no insights. Users don't need task completion reports.
SYSTEM;
    }

    protected function getGenericSystemPrompt(string $domain): string
    {
        return <<<SYSTEM
You are a Domain Agent for Flint, analyzing the {$domain} domain.

Analyze the provided data and identify:
- Patterns and trends
- Anomalies or unusual behavior
- Actionable insights
- Cross-domain connections

Provide specific, data-driven insights with confidence scores.
SYSTEM;
    }

    /**
     * Format events context for the prompt
     */
    protected function formatEventsContext(array $events, string $domain): string
    {
        if (empty($events)) {
            return 'No recent activity in this domain.';
        }

        $lines = ['Found ' . count($events) . " events in the last analysis window:\n"];

        // Group events by service and action
        $grouped = [];
        foreach ($events as $event) {
            $service = $event['service'] ?? 'unknown';
            $action = $event['action'] ?? 'unknown';
            $key = "{$service}:{$action}";

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'service' => $service,
                    'action' => $action,
                    'count' => 0,
                    'examples' => [],
                ];
            }

            $grouped[$key]['count']++;

            // Keep up to 3 examples with IDs for reference
            if (count($grouped[$key]['examples']) < 3) {
                $grouped[$key]['examples'][] = [
                    'id' => $event['id'] ?? null,
                    'time' => $event['time'] ?? null,
                    'value' => $event['value'] ?? null,
                    'value_unit' => $event['value_unit'] ?? null,
                    'metadata' => $event['event_metadata'] ?? [],
                ];
            }
        }

        foreach ($grouped as $group) {
            $lines[] = "**{$group['service']}** - {$group['action']}: {$group['count']} events";

            foreach ($group['examples'] as $example) {
                $parts = [];
                if ($example['id']) {
                    $parts[] = "ID: {$example['id']}";
                }
                if ($example['time']) {
                    $parts[] = "Time: {$example['time']}";
                }
                if ($example['value'] !== null && $example['value_unit']) {
                    $parts[] = "Value: {$example['value']} {$example['value_unit']}";
                }
                if (! empty($parts)) {
                    $lines[] = '  - ' . implode(', ', $parts);
                }
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Format learning context
     */
    protected function formatLearningContext(?array $learning, array $feedbackStats): string
    {
        if (empty($learning) && empty($feedbackStats)) {
            return '';
        }

        $lines = ["## Learning from Past Insights\n"];

        if (! empty($learning['successful_insights'])) {
            $lines[] = '**Successful insight patterns:** ' . count($learning['successful_insights']) . ' recorded';
            $lines[] = "Focus on similar types of insights that worked well before.\n";
        }

        if (! empty($learning['user_preferences'])) {
            $lines[] = '**User preferences:**';
            foreach ($learning['user_preferences'] as $key => $value) {
                $lines[] = "- {$key}: {$value}";
            }
            $lines[] = '';
        }

        if (! empty($feedbackStats['rating_average'])) {
            $lines[] = '**Feedback stats:**';
            $lines[] = "- Average rating: {$feedbackStats['rating_average']}/5";
            $lines[] = "- Total feedback: {$feedbackStats['total_feedback_count']}";
            if ($feedbackStats['dismissed_count'] > 0) {
                $lines[] = "- Dismissed: {$feedbackStats['dismissed_count']} (avoid similar patterns)";
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Format queries from other agents
     */
    protected function formatQueriesContext(array $queries): string
    {
        if (empty($queries)) {
            return '';
        }

        $lines = ["## Questions from Other Agents\n"];
        $lines[] = "Please answer these questions from other domain agents:\n";

        foreach ($queries as $query) {
            $lines[] = "**From {$query['from_domain']} agent:**";
            $lines[] = "Q: {$query['question']}";
            $lines[] = '';
        }

        $lines[] = 'Include your answers in the `query_responses` section of your response.';

        return implode("\n", $lines);
    }

    /**
     * Extract JSON from response (handles markdown code blocks)
     */
    protected function extractJson(string $response): ?array
    {
        // Try direct JSON decode
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Try to extract from markdown code block
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // Try to find any JSON object in the response
        if (preg_match('/(\{.*\})/s', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }
}
