<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;

class AssistantPromptingService
{
    private const MAX_RETRIES = 3;

    private const RETRY_DELAY = 2; // seconds

    public function __construct(
        private AssistantContextService $contextService
    ) {}

    /**
     * Generate digest using OpenAI with structured block output
     */
    public function generateDigest(User $user, string $period, ?Carbon $baseDate = null): array
    {
        $baseDate = $baseDate ?? now();

        // Get Flint integration for configuration
        $flintIntegration = Integration::where('user_id', $user->id)
            ->where('service', 'flint')
            ->first();

        if (! $flintIntegration) {
            throw new RuntimeException('Flint integration not found for user');
        }

        // Generate context
        $context = $this->contextService->generateContext($user, $baseDate, $flintIntegration);

        // Build prompts
        $systemPrompt = $this->buildSystemPrompt($period);
        $userPrompt = $this->buildUserPrompt($context, $period);

        // Call OpenAI with retry logic
        return $this->callOpenAI($user, $systemPrompt, $userPrompt, $period);
    }

    /**
     * Generate a response using OpenAI for agent tasks
     */
    public function generateResponse(string $prompt, array $options = []): string
    {
        $model = $options['model'] ?? config('services.openai.models.gpt5_mini');
        $userId = $options['user_id'] ?? null;
        $context = $options['context'] ?? [];
        $maxCompletionTokens = $options['max_completion_tokens'] ?? 2000;
        $temperature = $options['temperature'] ?? 1;

        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRIES) {
            try {
                if ($userId) {
                    log_integration_api_request(
                        'flint',
                        'chat.completions',
                        'openai/chat/completions',
                        [],
                        array_merge(['attempt' => $attempt + 1], $context),
                        $userId
                    );
                }

                // Start Sentry AI request span
                $messages = [['role' => 'user', 'content' => $prompt]];
                $aiSpan = start_ai_request_span($model, $messages, [
                    'temperature' => $temperature,
                    'max_completion_tokens' => $maxCompletionTokens,
                ]);

                $response = OpenAI::chat()->create([
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => $temperature,
                    'max_completion_tokens' => $maxCompletionTokens,
                ]);

                // Finish AI request span with token usage
                $usage = $response->usage ? $response->usage->toArray() : [];
                $finishReason = $response->choices[0]->finishReason ?? null;
                finish_ai_request_span($aiSpan, $usage, $finishReason);

                if ($userId) {
                    log_integration_api_response(
                        'flint',
                        'chat.completions',
                        'openai/chat/completions',
                        200,
                        json_encode($response->toArray()),
                        [],
                        $userId
                    );
                }

                return $response->choices[0]->message->content;

            } catch (Exception $e) {
                $lastException = $e;
                $attempt++;

                Log::warning('Flint agent OpenAI call failed', [
                    'user_id' => $userId,
                    'context' => $context,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < self::MAX_RETRIES) {
                    sleep(self::RETRY_DELAY * $attempt);
                }
            }
        }

        throw new RuntimeException(
            'Failed to generate agent response after ' . self::MAX_RETRIES . ' attempts: ' .
            $lastException->getMessage()
        );
    }

    /**
     * Build system prompt with domain-specific tone guidance
     */
    private function buildSystemPrompt(string $period): string
    {
        $timeContext = $period === 'morning'
            ? 'This is a morning digest (06:15). Focus on yesterday\'s activities and today\'s preparation.'
            : 'This is an afternoon digest (17:15). Focus on today\'s activities and tomorrow\'s preparation.';

        $toneGuidance = $this->getDomainToneGuidance();

        return <<<SYSTEM
You are Flint, an AI assistant that generates insightful daily digests for users based on their activity data from various integrated services.

{$timeContext}

{$toneGuidance}

**Your Task**: Generate a structured digest with the following components:

1. **headline** (string): A single compelling sentence summarizing the most important theme or insight from the day. Make it engaging and personal.

2. **key_points** (array of strings): Exactly 5 key points covering different domains/services. Each point should be concise (1-2 sentences) and highlight notable activities, patterns, or metrics.

3. **actions_required** (array of objects): Tasks or actions the user should take. Each object has:
   - `title` (string): Short action title
   - `description` (string): Why this action matters
   - `priority` (string): "high", "medium", or "low"
   - `suggested_due_date` (string|null): ISO date if time-sensitive

4. **things_to_be_aware_of** (array of objects|null): Proactive alerts about unusual patterns, potential issues, or important upcoming events. Each object has:
   - `title` (string): Alert title
   - `description` (string): What the user should know
   - `severity` (string): "info", "warning", or "alert"
   - `related_service` (string|null): Service name if applicable

   **Return null if nothing noteworthy to highlight.**

5. **insight** (object): One deep insight connecting multiple data points. Has:
   - `title` (string): Insight headline
   - `content` (string): 2-3 sentences explaining the insight
   - `supporting_data` (array of strings): Specific data points that support this insight

6. **suggestion** (object): One actionable suggestion for improvement or optimization. Has:
   - `title` (string): Suggestion title
   - `content` (string): 2-3 sentences explaining the suggestion
   - `actionable` (boolean): true if this could be automated in the future
   - `automation_hint` (string|null): If actionable=true, describe what automation could do

**Output Format**: Return ONLY valid JSON matching this exact structure:

```json
{
  "headline": "string",
  "key_points": ["string", "string", "string", "string", "string"],
  "actions_required": [
    {
      "title": "string",
      "description": "string",
      "priority": "high|medium|low",
      "suggested_due_date": "YYYY-MM-DD or null"
    }
  ],
  "things_to_be_aware_of": [
    {
      "title": "string",
      "description": "string",
      "severity": "info|warning|alert",
      "related_service": "string or null"
    }
  ] or null,
  "insight": {
    "title": "string",
    "content": "string",
    "supporting_data": ["string", "string"]
  },
  "suggestion": {
    "title": "string",
    "content": "string",
    "actionable": boolean,
    "automation_hint": "string or null"
  }
}
```

**Important**:
- Always return exactly 5 key_points
- actions_required can be empty array if no actions needed
- things_to_be_aware_of should be null (not empty array) if nothing to highlight
- Focus on patterns, insights, and connections between different data sources
- Be specific with numbers and metrics when available
SYSTEM;
    }

    /**
     * Domain-specific tone guidance
     */
    private function getDomainToneGuidance(): string
    {
        return <<<'TONE'
**Tone Guidelines by Domain:**

- **Knowledge Domain** (Fetch, Obsidian, GitHub): Factual, informative, structured. Focus on content summaries, key insights, and actionable information.
- **Health Domain** (Oura, Strava, Withings): Coaching and supportive. Encourage positive habits, contextualize metrics, provide gentle guidance.
- **Money Domain** (Monzo): Conversational and matter-of-fact. Summarize spending patterns, highlight unusual activity.
- **Media Domain** (Spotify, Last.fm): Conversational and engaging. Reflect on listening habits, discover patterns.
- **Online Domain** (Todoist): Task-focused and practical. Highlight completions, upcoming deadlines.

**Overall Digest Tone**: Unified and cohesive, but shift tone naturally when discussing each domain.
TONE;
    }

    /**
     * Build user prompt with context data
     */
    private function buildUserPrompt(array $context, string $period): string
    {
        $timeframe = $period === 'morning'
            ? "Yesterday: {$context['yesterday']['date']}\nToday: {$context['today']['date']}"
            : "Today: {$context['today']['date']}\nTomorrow: {$context['tomorrow']['date']}";

        $formattedContext = $this->formatContextForPrompt($context, $period);

        return <<<USER
Generate a {$period} digest based on the following user activity data:

**Timeframe:**
{$timeframe}

**Activity Data:**
{$formattedContext}

Please analyze this data and generate a structured digest following the JSON format specified in the system prompt.
USER;
    }

    /**
     * Format context data for the prompt
     */
    private function formatContextForPrompt(array $context, string $period): string
    {
        $formatted = [];

        if ($period === 'morning') {
            // Morning: yesterday + today preview
            if (! empty($context['yesterday']['groups'])) {
                $formatted[] = "**Yesterday ({$context['yesterday']['date']}):**";
                $formatted[] = $this->formatDayData($context['yesterday']);
            }

            if (! empty($context['today']['groups']) || ! empty($context['today']['service_breakdown'])) {
                $formatted[] = "\n**Today ({$context['today']['date']}):**";
                $formatted[] = $this->formatDayData($context['today']);
            }
        } else {
            // Afternoon: today + tomorrow preview
            if (! empty($context['today']['groups'])) {
                $formatted[] = "**Today ({$context['today']['date']}):**";
                $formatted[] = $this->formatDayData($context['today']);
            }

            if (! empty($context['tomorrow']['groups']) || ! empty($context['tomorrow']['service_breakdown'])) {
                $formatted[] = "\n**Tomorrow ({$context['tomorrow']['date']}):**";
                $formatted[] = $this->formatDayData($context['tomorrow']);
            }
        }

        return implode("\n", $formatted);
    }

    /**
     * Format a single day's data
     */
    private function formatDayData(array $dayData): string
    {
        $lines = [];

        // Service breakdown
        if (! empty($dayData['service_breakdown'])) {
            $lines[] = "\nService Summary:";
            foreach ($dayData['service_breakdown'] as $service => $count) {
                $lines[] = "  - {$service}: {$count} events";
            }
        }

        // Sample event groups (limit to 5 per service to control token usage)
        if (! empty($dayData['groups'])) {
            $lines[] = "\nKey Activities:";
            $groupedByService = [];

            foreach ($dayData['groups'] as $group) {
                $groupedByService[$group['service']][] = $group;
            }

            foreach ($groupedByService as $service => $groups) {
                $sampled = array_slice($groups, 0, 3);
                foreach ($sampled as $group) {
                    $summary = $group['summary'];
                    $lines[] = "  - {$summary}";

                    // Include first event details if not condensed
                    if (! $group['is_condensed'] && ! empty($group['first_event'])) {
                        $event = $group['first_event'];
                        if (! empty($event['blocks'])) {
                            foreach (array_slice($event['blocks'], 0, 2) as $block) {
                                if (! empty($block['content'])) {
                                    $preview = substr($block['content'], 0, 100);
                                    $lines[] = "      [{$block['type']}] {$preview}...";
                                }
                            }
                        }
                    }
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Call OpenAI API with retry logic and error handling
     */
    private function callOpenAI(User $user, string $systemPrompt, string $userPrompt, string $period): array
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRIES) {
            try {
                log_integration_api_request(
                    'flint',
                    'chat.completions',
                    'openai/chat/completions',
                    [],
                    ['period' => $period, 'attempt' => $attempt + 1],
                    $user->id
                );

                // Start Sentry AI request span
                $model = config('services.openai.models.gpt5_mini');
                $messages = [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ];
                $aiSpan = start_ai_request_span($model, $messages, [
                    'temperature' => 1,
                    'max_completion_tokens' => 2000,
                ]);

                $response = OpenAI::chat()->create([
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => 1,
                    'max_completion_tokens' => 2000,
                    'response_format' => ['type' => 'json_object'],
                ]);

                // Finish AI request span with token usage
                $usage = $response->usage ? $response->usage->toArray() : [];
                $finishReason = $response->choices[0]->finishReason ?? null;
                finish_ai_request_span($aiSpan, $usage, $finishReason);

                log_integration_api_response(
                    'flint',
                    'chat.completions',
                    'openai/chat/completions',
                    200,
                    json_encode($response->toArray()),
                    [],
                    $user->id
                );

                $content = $response->choices[0]->message->content;
                $decoded = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new RuntimeException('Invalid JSON response from OpenAI: ' . json_last_error_msg());
                }

                return $decoded;

            } catch (Exception $e) {
                $lastException = $e;
                $attempt++;

                Log::warning('Flint OpenAI call failed', [
                    'user_id' => $user->id,
                    'period' => $period,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < self::MAX_RETRIES) {
                    sleep(self::RETRY_DELAY * $attempt);
                }
            }
        }

        throw new RuntimeException(
            'Failed to generate digest after ' . self::MAX_RETRIES . ' attempts: ' .
            $lastException->getMessage()
        );
    }
}
