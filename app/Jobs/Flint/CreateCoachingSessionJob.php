<?php

namespace App\Jobs\Flint;

use App\Models\MetricTrend;
use App\Models\User;
use App\Services\AssistantPromptingService;
use App\Services\PatternLearningService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateCoachingSessionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120; // 2 minutes

    public function __construct(
        public User $user,
        public MetricTrend $anomaly
    ) {}

    public function handle(
        PatternLearningService $patternLearning,
        AssistantPromptingService $prompting
    ): void {
        Log::info('[Flint] [COACHING] Creating coaching session', [
            'user_id' => $this->user->id,
            'anomaly_id' => $this->anomaly->id,
        ]);

        $metricStatistic = $this->anomaly->metricStatistic;

        if (! $metricStatistic) {
            Log::warning('[Flint] [COACHING] Anomaly has no metric statistic, skipping', [
                'anomaly_id' => $this->anomaly->id,
            ]);

            return;
        }

        // Find existing patterns that might explain this anomaly
        $patternSuggestions = $patternLearning->suggestExplanations($this->user, $this->anomaly);

        // Generate AI questions for this anomaly
        $aiQuestions = $this->generateAiQuestions($prompting, $patternSuggestions);

        // Create the coaching session
        $session = $patternLearning->createCoachingSession(
            $this->user,
            $this->anomaly,
            $aiQuestions,
            $patternSuggestions
        );

        Log::info('[Flint] [COACHING] Created coaching session', [
            'user_id' => $this->user->id,
            'session_id' => $session->id,
            'anomaly_id' => $this->anomaly->id,
            'questions_count' => count($aiQuestions),
            'suggestions_count' => count($patternSuggestions),
        ]);
    }

    /**
     * Generate AI-powered questions for this anomaly.
     */
    protected function generateAiQuestions(
        AssistantPromptingService $prompting,
        array $patternSuggestions
    ): array {
        $metricStatistic = $this->anomaly->metricStatistic;
        $anomalyContext = $this->buildAnomalyContextForPrompt();

        $suggestionsText = '';
        if (! empty($patternSuggestions)) {
            $suggestionsText = "\n\nPreviously learned patterns that might be relevant:\n";
            foreach (array_slice($patternSuggestions, 0, 3) as $suggestion) {
                $suggestionsText .= "- {$suggestion['suggestion']} (confirmed {$suggestion['confirmations']} time(s))\n";
            }
        }

        $prompt = <<<PROMPT
You are a health coach assistant helping a user understand a health metric anomaly.

**Anomaly Details:**
- Metric: {$anomalyContext['metric_name']}
- Service: {$anomalyContext['service']}
- Type: {$anomalyContext['type_label']}
- Current Value: {$anomalyContext['current_value']} (baseline: {$anomalyContext['baseline_value']})
- Deviation: {$anomalyContext['deviation_percent']}% from normal
- Detected: {$anomalyContext['detected_at']}
{$suggestionsText}

**Your Task:**
Generate 2-3 thoughtful, open-ended questions to help understand what might have caused this anomaly.

**Guidelines:**
- Questions should be conversational and empathetic
- Focus on lifestyle factors, recent changes, or activities
- Be specific to the metric type (sleep, heart rate, activity, etc.)
- If patterns are suggested, incorporate them subtly without leading the user
- Keep questions concise (max 1-2 sentences each)

Return your response as a JSON array of questions:

```json
[
  "Question 1?",
  "Question 2?",
  "Question 3?"
]
```
PROMPT;

        try {
            $response = $prompting->generateResponse($prompt, [
                'model' => 'gpt-4.1-mini',
                'user_id' => $this->user->id,
                'context' => [
                    'agent_type' => 'coaching_question_generator',
                    'anomaly_id' => $this->anomaly->id,
                ],
            ]);

            return $this->parseQuestionsFromResponse($response);
        } catch (Exception $e) {
            Log::warning('[Flint] [COACHING] Failed to generate AI questions, using fallback', [
                'error' => $e->getMessage(),
            ]);

            return $this->getFallbackQuestions();
        }
    }

    /**
     * Build anomaly context for the prompt.
     */
    protected function buildAnomalyContextForPrompt(): array
    {
        $metricStatistic = $this->anomaly->metricStatistic;

        return [
            'metric_name' => $metricStatistic->getDisplayName(),
            'service' => $metricStatistic->service,
            'type_label' => $this->anomaly->getTypeLabel(),
            'current_value' => number_format((float) $this->anomaly->current_value, 2),
            'baseline_value' => number_format((float) $this->anomaly->baseline_value, 2),
            'deviation_percent' => $metricStatistic->mean_value > 0
                ? round(((float) $this->anomaly->deviation / (float) $metricStatistic->mean_value) * 100, 1)
                : 0,
            'detected_at' => $this->anomaly->detected_at->diffForHumans(),
        ];
    }

    /**
     * Parse questions from AI response.
     */
    protected function parseQuestionsFromResponse(string $response): array
    {
        // Try direct JSON decode
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return array_slice($decoded, 0, 3);
        }

        // Try to extract from markdown code block
        if (preg_match('/```(?:json)?\s*(\[.*?\])\s*```/s', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return array_slice($decoded, 0, 3);
            }
        }

        // Try to find any JSON array
        if (preg_match('/(\[.*?\])/s', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return array_slice($decoded, 0, 3);
            }
        }

        return $this->getFallbackQuestions();
    }

    /**
     * Get fallback questions if AI generation fails.
     */
    protected function getFallbackQuestions(): array
    {
        $metricName = $this->anomaly->metricStatistic?->getDisplayName() ?? 'this metric';
        $direction = $this->anomaly->getDirection();

        $directionWord = $direction === 'up' ? 'higher' : 'lower';

        return [
            "Your {$metricName} was {$directionWord} than usual recently. Can you think of anything that might have contributed to this?",
            'Have there been any changes to your routine, sleep schedule, or stress levels lately?',
            'Is there anything specific you remember about the past few days that might explain this?',
        ];
    }
}
