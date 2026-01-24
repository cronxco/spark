<?php

namespace App\Jobs\Flint;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\MetricTrend;
use App\Models\User;
use App\Services\AssistantPromptingService;
use App\Services\FlintBlockCreationService;
use App\Services\PatternLearningService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCoachingResponseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180; // 3 minutes

    public function __construct(
        public User $user,
        public EventObject $coachingSession,
        public string $userResponse
    ) {}

    public function handle(
        PatternLearningService $patternLearning,
        AssistantPromptingService $prompting,
        FlintBlockCreationService $blockCreation
    ): void {
        Log::info('[Flint] [COACHING] Processing coaching response', [
            'user_id' => $this->user->id,
            'session_id' => $this->coachingSession->id,
            'response_length' => strlen($this->userResponse),
        ]);

        // Extract patterns from user response using AI
        $extractedPatterns = $this->extractPatternsFromResponse($prompting);

        // Process the response and store patterns
        $patternLearning->processCoachingResponse(
            $this->coachingSession,
            $this->userResponse,
            $extractedPatterns
        );

        // Detect and store cross-domain pattern connections
        $this->detectCrossDomainConnections($patternLearning, $extractedPatterns);

        // Acknowledge the source anomaly
        $this->acknowledgeSourceAnomaly();

        // Create coaching insight blocks for significant patterns
        $this->createInsightBlocks($blockCreation, $extractedPatterns);

        // Tag related events with insights
        $this->tagRelatedEvents($patternLearning, $extractedPatterns);

        Log::info('[Flint] [COACHING] Completed processing coaching response', [
            'user_id' => $this->user->id,
            'session_id' => $this->coachingSession->id,
            'patterns_extracted' => count($extractedPatterns),
        ]);
    }

    /**
     * Extract patterns from user response using AI.
     */
    protected function extractPatternsFromResponse(AssistantPromptingService $prompting): array
    {
        $metadata = $this->coachingSession->metadata;
        $anomalyContext = $metadata['anomaly_context'] ?? [];
        $questions = $metadata['ai_questions'] ?? [];

        $questionsText = implode("\n", array_map(fn ($q, $i) => ($i + 1).". {$q}", $questions, range(0, count($questions) - 1)));

        $metricName = $anomalyContext['metric_name'] ?? 'Unknown Metric';
        $typeLabel = $anomalyContext['type_label'] ?? 'Anomaly';
        $deviationPercent = $anomalyContext['deviation_percent'] ?? 0;

        $prompt = <<<PROMPT
You are analyzing a user's response to a health coaching check-in to extract learnable patterns.

**Context:**
- Metric: {$metricName}
- Anomaly Type: {$typeLabel}
- Deviation: {$deviationPercent}% from normal

**Questions Asked:**
{$questionsText}

**User's Response:**
"{$this->userResponse}"

**Your Task:**
Extract actionable patterns from the user's response that could help explain future anomalies.

Return your response as a JSON array of patterns:

```json
[
  {
    "title": "Short pattern title (e.g., 'Late Night Work Sessions')",
    "trigger_conditions": {
      "activity": "Description of what triggers this",
      "timing": "When it typically happens"
    },
    "consequences": {
      "metric": "The affected metric",
      "effect": "How it affects the metric"
    },
    "user_explanation": "The user's own words summarizing this",
    "domains": ["health", "optional-other-domain"],
    "confidence": 0.5
  }
]
```

**Guidelines:**
- Only extract patterns that are clearly stated or strongly implied
- Return empty array [] if no clear patterns can be extracted
- Focus on cause-effect relationships
- Include the user's own words where possible
- Keep titles concise (3-5 words)
- Start with low confidence (0.3-0.5) for new patterns
PROMPT;

        try {
            $response = $prompting->generateResponse($prompt, [
                'model' => 'gpt-4.1-mini',
                'user_id' => $this->user->id,
                'context' => [
                    'agent_type' => 'pattern_extractor',
                    'session_id' => $this->coachingSession->id,
                ],
            ]);

            return $this->parsePatternsFromResponse($response);
        } catch (Exception $e) {
            Log::warning('[Flint] [COACHING] Failed to extract patterns from response', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Parse patterns from AI response.
     */
    protected function parsePatternsFromResponse(string $response): array
    {
        // Try direct JSON decode
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $this->validatePatterns($decoded);
        }

        // Try to extract from markdown code block
        if (preg_match('/```(?:json)?\s*(\[.*?\])\s*```/s', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->validatePatterns($decoded);
            }
        }

        // Try to find any JSON array
        if (preg_match('/(\[.*?\])/s', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->validatePatterns($decoded);
            }
        }

        return [];
    }

    /**
     * Validate extracted patterns have required fields.
     */
    protected function validatePatterns(array $patterns): array
    {
        return array_filter($patterns, function ($pattern) {
            return isset($pattern['title'])
                && ! empty($pattern['title'])
                && isset($pattern['user_explanation']);
        });
    }

    /**
     * Detect and store cross-domain pattern connections.
     */
    protected function detectCrossDomainConnections(
        PatternLearningService $patternLearning,
        array $extractedPatterns
    ): void {
        if (empty($extractedPatterns)) {
            return;
        }

        // Get the domains from the extracted patterns
        $patternDomains = collect($extractedPatterns)
            ->flatMap(fn ($pattern) => $pattern['domains'] ?? ['health'])
            ->unique()
            ->toArray();

        // If patterns span multiple domains, look for cross-domain connections
        if (count($patternDomains) > 1) {
            foreach ($patternDomains as $domain) {
                $crossDomainPatterns = $patternLearning->findCrossDomainPatterns(
                    $this->user,
                    $domain
                );

                if ($crossDomainPatterns->isNotEmpty()) {
                    // Store cross-domain connections in the coaching session metadata
                    $metadata = $this->coachingSession->metadata;
                    $metadata['cross_domain_insights'] = $crossDomainPatterns
                        ->map(fn ($pattern) => [
                            'pattern_id' => $pattern->id,
                            'title' => $pattern->title,
                            'domains' => $pattern->metadata['domains'] ?? [],
                            'confidence' => $pattern->metadata['confidence_score'] ?? 0.3,
                        ])
                        ->toArray();

                    $this->coachingSession->metadata = $metadata;
                    $this->coachingSession->save();

                    Log::info('[Flint] [COACHING] Detected cross-domain connections', [
                        'session_id' => $this->coachingSession->id,
                        'connections' => count($crossDomainPatterns),
                    ]);
                }
            }
        }

        // Update newly stored patterns with cross-domain connections
        $learnedPatterns = $patternLearning->getLearnedPatterns($this->user);

        foreach ($extractedPatterns as $pattern) {
            $learnedPattern = $learnedPatterns->first(fn ($p) => $p->title === $pattern['title']);

            if ($learnedPattern && count($pattern['domains'] ?? []) > 1) {
                $patternLearning->enrichPatternWithCrossDomainInsights(
                    $learnedPattern,
                    $this->user
                );
            }
        }
    }

    /**
     * Acknowledge the source anomaly.
     */
    protected function acknowledgeSourceAnomaly(): void
    {
        $anomalyId = $this->coachingSession->metadata['anomaly_id'] ?? null;

        if ($anomalyId) {
            try {
                $anomaly = MetricTrend::find($anomalyId);
                if ($anomaly) {
                    $anomaly->acknowledge();

                    Log::info('[Flint] [COACHING] Acknowledged source anomaly', [
                        'anomaly_id' => $anomalyId,
                    ]);
                }
            } catch (Exception $e) {
                Log::warning('[Flint] [COACHING] Failed to acknowledge anomaly', [
                    'anomaly_id' => $anomalyId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Create insight blocks for significant patterns.
     */
    protected function createInsightBlocks(
        FlintBlockCreationService $blockCreation,
        array $extractedPatterns
    ): void {
        if (empty($extractedPatterns)) {
            return;
        }

        $flintEvent = $blockCreation->getOrCreateFlintEvent($this->user);

        foreach ($extractedPatterns as $pattern) {
            $blockCreation->createCoachingInsightBlock($this->user, [
                'title' => $pattern['title'],
                'insight' => $pattern['user_explanation'],
                'trigger_conditions' => $pattern['trigger_conditions'] ?? [],
                'consequences' => $pattern['consequences'] ?? [],
                'confirmation_count' => 1,
                'confidence' => $pattern['confidence'] ?? 0.3,
            ], $flintEvent);
        }
    }

    /**
     * Tag related events with insights from learned patterns.
     */
    protected function tagRelatedEvents(
        PatternLearningService $patternLearning,
        array $extractedPatterns
    ): void {
        // Get the learned patterns that were just stored
        $learnedPatterns = $patternLearning->getLearnedPatterns($this->user, 0.3);

        // For each new pattern, find and tag related events
        foreach ($extractedPatterns as $pattern) {
            $learnedPattern = $learnedPatterns->first(fn ($p) => $p->title === $pattern['title']);

            if (! $learnedPattern) {
                continue;
            }

            // Find related events from the anomaly period
            $anomalyContext = $this->coachingSession->metadata['anomaly_context'] ?? [];
            $service = $anomalyContext['service'] ?? null;
            $action = $anomalyContext['action'] ?? null;

            if ($service && $action) {
                $relatedEvents = Event::forUser($this->user->id)
                    ->where('service', $service)
                    ->where('action', $action)
                    ->where('time', '>=', now()->subDays(7))
                    ->limit(5)
                    ->get();

                foreach ($relatedEvents as $event) {
                    $patternLearning->tagEventWithInsight(
                        $event,
                        $learnedPattern,
                        $pattern['user_explanation']
                    );
                }
            }
        }
    }
}
