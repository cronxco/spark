<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class AgentWorkingMemoryService
{
    protected const CACHE_PREFIX = 'flint:working_memory:';

    protected const DEFAULT_TTL = 60 * 24 * 7; // 7 days in minutes

    /**
     * Get the complete working memory for a user
     */
    public function getWorkingMemory(string $userId): array
    {
        return Cache::get($this->getCacheKey($userId), $this->getDefaultStructure());
    }

    /**
     * Update the working memory for a user
     */
    public function updateWorkingMemory(string $userId, array $data): void
    {
        $current = $this->getWorkingMemory($userId);
        $merged = array_merge_recursive($current, $data);

        Cache::put($this->getCacheKey($userId), $merged, now()->addMinutes(self::DEFAULT_TTL));
    }

    /**
     * Store a domain insight
     */
    public function storeDomainInsight(string $userId, string $domain, array $insight): void
    {
        $toolSpan = start_ai_tool_span('store_domain_insight', [
            'domain' => $domain,
            'insight_count' => count($insight['insights'] ?? []),
        ]);

        $memory = $this->getWorkingMemory($userId);

        $memory['domain_insights'][$domain] = array_merge(
            $memory['domain_insights'][$domain] ?? [],
            [
                'last_updated' => now()->toIso8601String(),
                'insights' => $insight['insights'] ?? [],
                'suggestions' => $insight['suggestions'] ?? [],
                'metrics' => $insight['metrics'] ?? [],
                'confidence' => $insight['confidence'] ?? 0.0,
                'reasoning' => $insight['reasoning'] ?? '',
            ]
        );

        Cache::put($this->getCacheKey($userId), $memory, now()->addMinutes(self::DEFAULT_TTL));

        finish_ai_tool_span($toolSpan, ['stored' => true]);
    }

    /**
     * Get a domain insight
     */
    public function getDomainInsight(string $userId, string $domain): ?array
    {
        $memory = $this->getWorkingMemory($userId);

        return $memory['domain_insights'][$domain] ?? null;
    }

    /**
     * Get all domain insights
     */
    public function getAllDomainInsights(string $userId): array
    {
        $memory = $this->getWorkingMemory($userId);

        return $memory['domain_insights'] ?? [];
    }

    /**
     * Add a cross-domain observation
     */
    public function addCrossDomainObservation(string $userId, array $observation): void
    {
        $toolSpan = start_ai_tool_span('store_cross_domain_observation', [
            'domains' => $observation['domains'] ?? [],
        ]);

        $memory = $this->getWorkingMemory($userId);

        $memory['cross_domain_observations'][] = array_merge($observation, [
            'observed_at' => now()->toIso8601String(),
        ]);

        // Keep only last 50 observations
        $memory['cross_domain_observations'] = array_slice(
            $memory['cross_domain_observations'],
            -50
        );

        Cache::put($this->getCacheKey($userId), $memory, now()->addMinutes(self::DEFAULT_TTL));

        finish_ai_tool_span($toolSpan, ['stored' => true]);
    }

    /**
     * Get cross-domain observations
     */
    public function getCrossDomainObservations(string $userId, ?int $limit = null): array
    {
        $memory = $this->getWorkingMemory($userId);
        $observations = $memory['cross_domain_observations'] ?? [];

        if ($limit !== null) {
            return array_slice($observations, -$limit);
        }

        return $observations;
    }

    /**
     * Raise an urgent flag
     */
    public function raiseUrgentFlag(string $userId, string $domain, string $reason, array $context = []): void
    {
        $memory = $this->getWorkingMemory($userId);

        $memory['urgent_flags'][] = [
            'domain' => $domain,
            'reason' => $reason,
            'context' => $context,
            'raised_at' => now()->toIso8601String(),
            'resolved' => false,
        ];

        // Keep only last 20 urgent flags
        $memory['urgent_flags'] = array_slice($memory['urgent_flags'], -20);

        Cache::put($this->getCacheKey($userId), $memory, now()->addMinutes(self::DEFAULT_TTL));
    }

    /**
     * Resolve an urgent flag
     */
    public function resolveUrgentFlag(string $userId, int $index): void
    {
        $memory = $this->getWorkingMemory($userId);

        if (isset($memory['urgent_flags'][$index])) {
            $memory['urgent_flags'][$index]['resolved'] = true;
            $memory['urgent_flags'][$index]['resolved_at'] = now()->toIso8601String();

            Cache::put($this->getCacheKey($userId), $memory, now()->addMinutes(self::DEFAULT_TTL));
        }
    }

    /**
     * Get unresolved urgent flags
     */
    public function getUnresolvedUrgentFlags(string $userId): array
    {
        $memory = $this->getWorkingMemory($userId);

        return array_filter($memory['urgent_flags'] ?? [], fn ($flag) => ! $flag['resolved']);
    }

    /**
     * Post a query from one agent to another
     */
    public function postAgentQuery(string $userId, string $fromDomain, string $toDomain, string $question, array $context = []): void
    {
        $memory = $this->getWorkingMemory($userId);

        $memory['agent_queries'][] = [
            'from_domain' => $fromDomain,
            'to_domain' => $toDomain,
            'question' => $question,
            'context' => $context,
            'posted_at' => now()->toIso8601String(),
            'answered' => false,
            'answer' => null,
        ];

        // Keep only last 30 queries
        $memory['agent_queries'] = array_slice($memory['agent_queries'], -30);

        Cache::put($this->getCacheKey($userId), $memory, now()->addMinutes(self::DEFAULT_TTL));
    }

    /**
     * Answer an agent query
     */
    public function answerAgentQuery(string $userId, int $index, string $answer): void
    {
        $memory = $this->getWorkingMemory($userId);

        if (isset($memory['agent_queries'][$index])) {
            $memory['agent_queries'][$index]['answered'] = true;
            $memory['agent_queries'][$index]['answer'] = $answer;
            $memory['agent_queries'][$index]['answered_at'] = now()->toIso8601String();

            Cache::put($this->getCacheKey($userId), $memory, now()->addMinutes(self::DEFAULT_TTL));
        }
    }

    /**
     * Get unanswered queries for a specific domain
     */
    public function getUnansweredQueriesForDomain(string $userId, string $domain): array
    {
        $memory = $this->getWorkingMemory($userId);

        return array_filter(
            $memory['agent_queries'] ?? [],
            fn ($query) => $query['to_domain'] === $domain && ! $query['answered']
        );
    }

    /**
     * Record user feedback
     */
    public function recordFeedback(string $userId, string $blockId, string $feedbackType, $value, ?string $comment = null): void
    {
        $memory = $this->getWorkingMemory($userId);

        $memory['user_feedback'][] = [
            'block_id' => $blockId,
            'type' => $feedbackType,
            'value' => $value,
            'comment' => $comment,
            'recorded_at' => now()->toIso8601String(),
        ];

        // Keep only last 100 feedback items
        $memory['user_feedback'] = array_slice($memory['user_feedback'], -100);

        Cache::put($this->getCacheKey($userId), $memory, now()->addMinutes(self::DEFAULT_TTL));
    }

    /**
     * Get recent feedback
     */
    public function getRecentFeedback(string $userId, ?int $limit = 20): array
    {
        $memory = $this->getWorkingMemory($userId);

        return array_slice($memory['user_feedback'] ?? [], -$limit);
    }

    /**
     * Get feedback statistics for learning
     */
    public function getFeedbackStatistics(string $userId): array
    {
        $memory = $this->getWorkingMemory($userId);
        $feedback = $memory['user_feedback'] ?? [];

        $stats = [
            'total_feedback_count' => count($feedback),
            'rating_average' => 0,
            'rating_distribution' => [],
            'dismissed_count' => 0,
            'acted_count' => 0,
        ];

        foreach ($feedback as $item) {
            if ($item['type'] === 'rating') {
                $stats['rating_distribution'][$item['value']] = ($stats['rating_distribution'][$item['value']] ?? 0) + 1;
            } elseif ($item['type'] === 'dismissed') {
                $stats['dismissed_count']++;
            } elseif ($item['type'] === 'acted') {
                $stats['acted_count']++;
            }
        }

        if (! empty($stats['rating_distribution'])) {
            $totalRatings = array_sum($stats['rating_distribution']);
            $weightedSum = 0;
            foreach ($stats['rating_distribution'] as $rating => $count) {
                $weightedSum += $rating * $count;
            }
            $stats['rating_average'] = $totalRatings > 0 ? round($weightedSum / $totalRatings, 2) : 0;
        }

        return $stats;
    }

    /**
     * Store prioritized actions
     */
    public function storePrioritizedActions(string $userId, array $actions): void
    {
        $memory = $this->getWorkingMemory($userId);

        $memory['prioritized_actions'] = [
            'updated_at' => now()->toIso8601String(),
            'actions' => $actions,
        ];

        Cache::put($this->getCacheKey($userId), $memory, now()->addMinutes(self::DEFAULT_TTL));
    }

    /**
     * Get prioritized actions
     */
    public function getPrioritizedActions(string $userId): array
    {
        $memory = $this->getWorkingMemory($userId);

        return $memory['prioritized_actions']['actions'] ?? [];
    }

    /**
     * Mark an action as completed
     */
    public function markActionCompleted(string $userId, string $actionId): void
    {
        $memory = $this->getWorkingMemory($userId);

        if (isset($memory['prioritized_actions']['actions'])) {
            foreach ($memory['prioritized_actions']['actions'] as &$action) {
                if ($action['id'] === $actionId) {
                    $action['completed'] = true;
                    $action['completed_at'] = now()->toIso8601String();
                }
            }

            Cache::put($this->getCacheKey($userId), $memory, now()->addMinutes(self::DEFAULT_TTL));
        }
    }

    /**
     * Store last execution timestamp
     */
    public function setLastExecutionTime(string $userId, string $executionType): void
    {
        $memory = $this->getWorkingMemory($userId);

        $memory['last_execution'][$executionType] = now()->toIso8601String();

        Cache::put($this->getCacheKey($userId), $memory, now()->addMinutes(self::DEFAULT_TTL));
    }

    /**
     * Get last execution timestamp
     */
    public function getLastExecutionTime(string $userId, string $executionType): ?string
    {
        $memory = $this->getWorkingMemory($userId);

        return $memory['last_execution'][$executionType] ?? null;
    }

    /**
     * Clear working memory for a user
     */
    public function clearWorkingMemory(string $userId): void
    {
        Cache::forget($this->getCacheKey($userId));
    }

    /**
     * Get cache key for a user
     */
    protected function getCacheKey(string $userId): string
    {
        return self::CACHE_PREFIX . $userId;
    }

    /**
     * Get default working memory structure
     */
    protected function getDefaultStructure(): array
    {
        return [
            'domain_insights' => [
                'health' => [],
                'money' => [],
                'media' => [],
                'knowledge' => [],
                'online' => [],
            ],
            'cross_domain_observations' => [],
            'urgent_flags' => [],
            'agent_queries' => [],
            'user_feedback' => [],
            'prioritized_actions' => [
                'updated_at' => null,
                'actions' => [],
            ],
            'last_execution' => [
                'continuous_background' => null,
                'pre_digest_refresh' => null,
                'digest_generation' => null,
                'pattern_detection' => null,
            ],
        ];
    }
}
