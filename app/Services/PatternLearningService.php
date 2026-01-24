<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\MetricTrend;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PatternLearningService
{
    /**
     * Create or update a coaching session for a health anomaly.
     */
    public function createCoachingSession(
        User $user,
        MetricTrend $anomaly,
        array $aiQuestions,
        ?array $patternSuggestions = null
    ): EventObject {
        $metricStatistic = $anomaly->metricStatistic;
        $anomalyContext = $this->buildAnomalyContext($anomaly);

        $coachingSession = EventObject::create([
            'user_id' => $user->id,
            'concept' => 'flint',
            'type' => 'coaching_session',
            'title' => 'Health Check-In: '.$metricStatistic->getDisplayName(),
            'time' => now(),
            'metadata' => [
                'status' => 'active',
                'anomaly_id' => $anomaly->id,
                'metric_statistic_id' => $metricStatistic->id,
                'service' => $metricStatistic->service,
                'action' => $metricStatistic->action,
                'anomaly_context' => $anomalyContext,
                'ai_questions' => $aiQuestions,
                'pattern_suggestions' => $patternSuggestions ?? [],
                'created_at' => now()->toIso8601String(),
                'user_response' => null,
                'extracted_patterns' => [],
            ],
        ]);

        // Link coaching session to the source metric trend (via Event if available)
        $this->linkCoachingSessionToAnomaly($user, $coachingSession, $anomaly);

        Log::info('[PatternLearning] Created coaching session', [
            'user_id' => $user->id,
            'session_id' => $coachingSession->id,
            'metric' => $metricStatistic->getIdentifier(),
            'anomaly_type' => $anomaly->type,
        ]);

        return $coachingSession;
    }

    /**
     * Process a user's response to a coaching session.
     */
    public function processCoachingResponse(
        EventObject $coachingSession,
        string $userResponse,
        array $extractedPatterns = []
    ): EventObject {
        $metadata = $coachingSession->metadata;
        $metadata['status'] = 'completed';
        $metadata['user_response'] = $userResponse;
        $metadata['responded_at'] = now()->toIso8601String();
        $metadata['extracted_patterns'] = $extractedPatterns;

        $coachingSession->metadata = $metadata;
        $coachingSession->save();

        // Store learned patterns
        foreach ($extractedPatterns as $pattern) {
            $this->storeLearnedPattern(
                $coachingSession->user_id,
                $pattern,
                $coachingSession
            );
        }

        Log::info('[PatternLearning] Processed coaching response', [
            'session_id' => $coachingSession->id,
            'patterns_extracted' => count($extractedPatterns),
        ]);

        return $coachingSession;
    }

    /**
     * Dismiss a coaching session without response.
     */
    public function dismissCoachingSession(EventObject $coachingSession): EventObject
    {
        $metadata = $coachingSession->metadata;
        $metadata['status'] = 'dismissed';
        $metadata['dismissed_at'] = now()->toIso8601String();

        $coachingSession->metadata = $metadata;
        $coachingSession->save();

        return $coachingSession;
    }

    /**
     * Store a learned pattern from coaching.
     */
    public function storeLearnedPattern(
        string $userId,
        array $patternData,
        ?EventObject $sourceSession = null
    ): EventObject {
        // Check for existing similar patterns to update confidence
        $existingPattern = $this->findSimilarPattern($userId, $patternData);

        if ($existingPattern) {
            return $this->updatePatternConfidence($existingPattern, $sourceSession);
        }

        $learnedPattern = EventObject::create([
            'user_id' => $userId,
            'concept' => 'flint',
            'type' => 'learned_pattern',
            'title' => $patternData['title'] ?? 'Learned Pattern',
            'time' => now(),
            'metadata' => [
                'trigger_conditions' => $patternData['trigger_conditions'] ?? [],
                'consequences' => $patternData['consequences'] ?? [],
                'user_explanation' => $patternData['user_explanation'] ?? '',
                'confidence_score' => 0.3, // Initial confidence
                'confirmation_count' => 1,
                'source_sessions' => $sourceSession ? [$sourceSession->id] : [],
                'domains' => $patternData['domains'] ?? ['health'],
                'created_at' => now()->toIso8601String(),
                'last_confirmed_at' => now()->toIso8601String(),
            ],
        ]);

        Log::info('[PatternLearning] Stored new learned pattern', [
            'user_id' => $userId,
            'pattern_id' => $learnedPattern->id,
            'title' => $learnedPattern->title,
        ]);

        return $learnedPattern;
    }

    /**
     * Find patterns relevant to a new anomaly.
     */
    public function findRelevantPatterns(User $user, MetricTrend $anomaly, int $limit = 5): Collection
    {
        $metricStatistic = $anomaly->metricStatistic;

        return EventObject::where('user_id', $user->id)
            ->where('concept', 'flint')
            ->where('type', 'learned_pattern')
            ->whereNull('deleted_at')
            ->where(function ($query) use ($metricStatistic) {
                // Match patterns that relate to this metric
                $query->whereRaw("metadata::jsonb->'trigger_conditions' @> ?::jsonb", [
                    json_encode(['service' => $metricStatistic->service]),
                ])
                    ->orWhereRaw("metadata::jsonb->'trigger_conditions' @> ?::jsonb", [
                        json_encode(['action' => $metricStatistic->action]),
                    ])
                    ->orWhereRaw("metadata::jsonb->'consequences' @> ?::jsonb", [
                        json_encode(['metric' => $metricStatistic->getIdentifier()]),
                    ]);
            })
            ->orderByRaw("(metadata->>'confidence_score')::numeric DESC")
            ->limit($limit)
            ->get();
    }

    /**
     * Get active coaching sessions for a user.
     */
    public function getActiveCoachingSessions(User $user, int $limit = 10): Collection
    {
        return EventObject::where('user_id', $user->id)
            ->where('concept', 'flint')
            ->where('type', 'coaching_session')
            ->whereRaw("metadata->>'status' = 'active'")
            ->whereNull('deleted_at')
            ->orderBy('time', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get completed coaching sessions for a user.
     */
    public function getCompletedCoachingSessions(User $user, int $days = 30): Collection
    {
        return EventObject::where('user_id', $user->id)
            ->where('concept', 'flint')
            ->where('type', 'coaching_session')
            ->whereRaw("metadata->>'status' = 'completed'")
            ->where('time', '>=', now()->subDays($days))
            ->whereNull('deleted_at')
            ->orderBy('time', 'desc')
            ->get();
    }

    /**
     * Get all learned patterns for a user.
     */
    public function getLearnedPatterns(User $user, ?float $minConfidence = null): Collection
    {
        $query = EventObject::where('user_id', $user->id)
            ->where('concept', 'flint')
            ->where('type', 'learned_pattern')
            ->whereNull('deleted_at');

        if ($minConfidence !== null) {
            $query->whereRaw("(metadata->>'confidence_score')::numeric >= ?", [$minConfidence]);
        }

        return $query->orderByRaw("(metadata->>'confidence_score')::numeric DESC")
            ->get();
    }

    /**
     * Tag an event with a learned insight.
     */
    public function tagEventWithInsight(Event $event, EventObject $learnedPattern, string $insightText): void
    {
        // Add insight to event metadata
        $metadata = $event->event_metadata ?? [];
        $metadata['flint_insights'] = $metadata['flint_insights'] ?? [];
        $metadata['flint_insights'][] = [
            'pattern_id' => $learnedPattern->id,
            'insight' => $insightText,
            'confidence' => $learnedPattern->metadata['confidence_score'] ?? 0.5,
            'added_at' => now()->toIso8601String(),
        ];

        $event->event_metadata = $metadata;
        $event->save();

        // Create relationship between event and learned pattern
        Relationship::createRelationship([
            'user_id' => $event->user_id,
            'from_type' => Event::class,
            'from_id' => $event->id,
            'to_type' => EventObject::class,
            'to_id' => $learnedPattern->id,
            'type' => 'related_to',
            'metadata' => [
                'insight' => $insightText,
                'created_by' => 'pattern_learning',
            ],
        ]);
    }

    /**
     * Suggest explanations for an anomaly based on learned patterns.
     */
    public function suggestExplanations(User $user, MetricTrend $anomaly): array
    {
        $relevantPatterns = $this->findRelevantPatterns($user, $anomaly);

        $suggestions = [];
        foreach ($relevantPatterns as $pattern) {
            $confidence = $pattern->metadata['confidence_score'] ?? 0.3;
            $confirmations = $pattern->metadata['confirmation_count'] ?? 1;
            $crossDomainInsights = $pattern->metadata['cross_domain_connections'] ?? [];

            $suggestions[] = [
                'pattern_id' => $pattern->id,
                'suggestion' => $pattern->metadata['user_explanation'] ?? $pattern->title,
                'confidence' => $confidence,
                'confirmations' => $confirmations,
                'trigger_conditions' => $pattern->metadata['trigger_conditions'] ?? [],
                'last_confirmed' => $pattern->metadata['last_confirmed_at'] ?? null,
                'cross_domain_insights' => $crossDomainInsights,
                'domains' => $pattern->metadata['domains'] ?? ['health'],
            ];
        }

        return $suggestions;
    }

    /**
     * Check for cross-domain patterns (e.g., health + fitness).
     */
    public function findCrossDomainPatterns(User $user, string $primaryDomain, int $days = 30): Collection
    {
        return EventObject::where('user_id', $user->id)
            ->where('concept', 'flint')
            ->where('type', 'learned_pattern')
            ->whereNull('deleted_at')
            ->whereRaw("jsonb_array_length((metadata::jsonb)->'domains') > 1")
            ->whereRaw("(metadata::jsonb)->'domains' @> ?", [json_encode([$primaryDomain])])
            ->where('time', '>=', now()->subDays($days))
            ->orderByRaw("(metadata->>'confidence_score')::numeric DESC")
            ->get();
    }

    /**
     * Enrich a pattern with cross-domain insights.
     */
    public function enrichPatternWithCrossDomainInsights(EventObject $pattern, User $user): EventObject
    {
        $domains = $pattern->metadata['domains'] ?? ['health'];

        if (count($domains) <= 1) {
            return $pattern;
        }

        $crossDomainConnections = [];

        foreach ($domains as $domain) {
            // Find other patterns in this domain
            $relatedPatterns = $this->findCrossDomainPatterns($user, $domain, 90);

            foreach ($relatedPatterns as $relatedPattern) {
                if ($relatedPattern->id === $pattern->id) {
                    continue;
                }

                $crossDomainConnections[] = [
                    'pattern_id' => $relatedPattern->id,
                    'title' => $relatedPattern->title,
                    'domains' => $relatedPattern->metadata['domains'] ?? [],
                    'confidence' => $relatedPattern->metadata['confidence_score'] ?? 0.3,
                    'explanation' => $relatedPattern->metadata['user_explanation'] ?? '',
                ];
            }
        }

        if (! empty($crossDomainConnections)) {
            $metadata = $pattern->metadata;
            $metadata['cross_domain_connections'] = array_slice($crossDomainConnections, 0, 5);
            $pattern->metadata = $metadata;
            $pattern->save();

            Log::info('[PatternLearning] Enriched pattern with cross-domain insights', [
                'pattern_id' => $pattern->id,
                'connections' => count($crossDomainConnections),
            ]);
        }

        return $pattern;
    }

    /**
     * Build context object for an anomaly.
     */
    protected function buildAnomalyContext(MetricTrend $anomaly): array
    {
        $metricStatistic = $anomaly->metricStatistic;

        return [
            'type' => $anomaly->type,
            'type_label' => $anomaly->getTypeLabel(),
            'direction' => $anomaly->getDirection(),
            'metric_name' => $metricStatistic->getDisplayName(),
            'service' => $metricStatistic->service,
            'action' => $metricStatistic->action,
            'baseline_value' => (float) $anomaly->baseline_value,
            'current_value' => (float) $anomaly->current_value,
            'deviation' => (float) $anomaly->deviation,
            'deviation_percent' => $metricStatistic->mean_value > 0
                ? round(($anomaly->deviation / $metricStatistic->mean_value) * 100, 1)
                : 0,
            'significance_score' => (float) $anomaly->significance_score,
            'detected_at' => $anomaly->detected_at->toIso8601String(),
            'period' => [
                'start' => $anomaly->start_date?->toIso8601String(),
                'end' => $anomaly->end_date?->toIso8601String(),
            ],
        ];
    }

    /**
     * Link a coaching session to its source anomaly.
     */
    protected function linkCoachingSessionToAnomaly(
        User $user,
        EventObject $coachingSession,
        MetricTrend $anomaly
    ): void {
        // Find events related to this metric during the anomaly period
        $relatedEvents = Event::forUser($user->id)
            ->where('service', $anomaly->metricStatistic->service)
            ->where('action', $anomaly->metricStatistic->action)
            ->when($anomaly->start_date, fn ($q) => $q->where('time', '>=', $anomaly->start_date))
            ->when($anomaly->end_date, fn ($q) => $q->where('time', '<=', $anomaly->end_date))
            ->limit(5)
            ->get();

        foreach ($relatedEvents as $event) {
            Relationship::createRelationship([
                'user_id' => $user->id,
                'from_type' => EventObject::class,
                'from_id' => $coachingSession->id,
                'to_type' => Event::class,
                'to_id' => $event->id,
                'type' => 'related_to',
                'metadata' => [
                    'context' => 'coaching_session_source',
                    'anomaly_id' => $anomaly->id,
                ],
            ]);
        }
    }

    /**
     * Find a similar existing pattern.
     */
    protected function findSimilarPattern(string $userId, array $patternData): ?EventObject
    {
        $title = $patternData['title'] ?? '';
        $triggerConditions = $patternData['trigger_conditions'] ?? [];

        // First try exact title match
        $existing = EventObject::where('user_id', $userId)
            ->where('concept', 'flint')
            ->where('type', 'learned_pattern')
            ->where('title', $title)
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        // Try matching on trigger conditions
        if (! empty($triggerConditions)) {
            return EventObject::where('user_id', $userId)
                ->where('concept', 'flint')
                ->where('type', 'learned_pattern')
                ->whereRaw("metadata::jsonb->'trigger_conditions' @> ?::jsonb", [json_encode($triggerConditions)])
                ->whereNull('deleted_at')
                ->first();
        }

        return null;
    }

    /**
     * Update confidence for an existing pattern when reconfirmed.
     */
    protected function updatePatternConfidence(
        EventObject $pattern,
        ?EventObject $sourceSession
    ): EventObject {
        $metadata = $pattern->metadata;

        // Increase confidence (max 0.95)
        $currentConfidence = $metadata['confidence_score'] ?? 0.3;
        $metadata['confidence_score'] = min(0.95, $currentConfidence + 0.15);

        // Track confirmations
        $metadata['confirmation_count'] = ($metadata['confirmation_count'] ?? 1) + 1;
        $metadata['last_confirmed_at'] = now()->toIso8601String();

        // Add source session if provided
        if ($sourceSession) {
            $metadata['source_sessions'] = $metadata['source_sessions'] ?? [];
            $metadata['source_sessions'][] = $sourceSession->id;
            $metadata['source_sessions'] = array_unique($metadata['source_sessions']);
        }

        $pattern->metadata = $metadata;
        $pattern->save();

        Log::info('[PatternLearning] Updated pattern confidence', [
            'pattern_id' => $pattern->id,
            'new_confidence' => $metadata['confidence_score'],
            'confirmations' => $metadata['confirmation_count'],
        ]);

        return $pattern;
    }
}
