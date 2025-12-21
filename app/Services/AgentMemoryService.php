<?php

namespace App\Services;

use App\Models\Block;
use App\Models\EventObject;

class AgentMemoryService
{
    /**
     * Store a pattern in long-term memory
     */
    public function storePattern(string $userId, array $patternData): EventObject
    {
        $eventObject = EventObject::firstOrCreate(
            [
                'user_id' => $userId,
                'concept' => 'flint',
                'type' => 'pattern',
                'title' => $patternData['title'] ?? 'Detected Pattern',
            ],
            [
                'time' => now(),
                'metadata' => [],
            ]
        );

        // Update metadata with pattern details
        $metadata = $eventObject->metadata ?? [];
        $metadata['pattern_type'] = $patternData['pattern_type'] ?? 'correlation';
        $metadata['domains'] = $patternData['domains'] ?? [];
        $metadata['confidence'] = $patternData['confidence'] ?? 0.0;
        $metadata['detected_at'] = now()->toIso8601String();
        $metadata['occurrences'] = $patternData['occurrences'] ?? [];
        $metadata['description'] = $patternData['description'] ?? '';
        $metadata['supporting_evidence'] = $patternData['supporting_evidence'] ?? [];

        $eventObject->metadata = $metadata;
        $eventObject->save();

        return $eventObject;
    }

    /**
     * Get all patterns for a user
     */
    public function getPatterns(string $userId, ?string $patternType = null): array
    {
        $query = EventObject::where('user_id', $userId)
            ->where('concept', 'flint')
            ->where('type', 'pattern')
            ->whereNull('deleted_at');

        if ($patternType !== null) {
            $query->whereRaw("metadata->>'pattern_type' = ?", [$patternType]);
        }

        return $query->orderBy('time', 'desc')->get()->toArray();
    }

    /**
     * Store agent learning data
     */
    public function storeAgentLearning(string $userId, string $domain, array $learningData): EventObject
    {
        $eventObject = EventObject::firstOrCreate(
            [
                'user_id' => $userId,
                'concept' => 'flint',
                'type' => 'agent_learning',
                'title' => "Flint {$domain} Agent Learning",
            ],
            [
                'time' => now(),
                'metadata' => [],
            ]
        );

        // Update metadata with learning data
        $metadata = $eventObject->metadata ?? [];
        $metadata['domain'] = $domain;
        $metadata['last_updated'] = now()->toIso8601String();
        $metadata['successful_insights'] = $learningData['successful_insights'] ?? [];
        $metadata['failed_insights'] = $learningData['failed_insights'] ?? [];
        $metadata['user_preferences'] = $learningData['user_preferences'] ?? [];
        $metadata['effective_patterns'] = $learningData['effective_patterns'] ?? [];

        $eventObject->metadata = $metadata;
        $eventObject->save();

        return $eventObject;
    }

    /**
     * Get agent learning data
     */
    public function getAgentLearning(string $userId, string $domain): ?array
    {
        $eventObject = EventObject::where('user_id', $userId)
            ->where('concept', 'flint')
            ->where('type', 'agent_learning')
            ->where('title', "Flint {$domain} Agent Learning")
            ->whereNull('deleted_at')
            ->first();

        return $eventObject ? $eventObject->metadata : null;
    }

    /**
     * Record successful insight (for learning)
     */
    public function recordSuccessfulInsight(string $userId, string $domain, string $blockId, array $insightData): void
    {
        $learning = $this->getAgentLearning($userId, $domain) ?? [];

        $learning['successful_insights'] = $learning['successful_insights'] ?? [];
        $learning['successful_insights'][] = [
            'block_id' => $blockId,
            'insight_type' => $insightData['type'] ?? 'general',
            'recorded_at' => now()->toIso8601String(),
            'context' => $insightData['context'] ?? [],
        ];

        // Keep only last 100 successful insights
        $learning['successful_insights'] = array_slice($learning['successful_insights'], -100);

        $this->storeAgentLearning($userId, $domain, $learning);
    }

    /**
     * Record failed insight (for learning)
     */
    public function recordFailedInsight(string $userId, string $domain, string $reason, array $insightData): void
    {
        $learning = $this->getAgentLearning($userId, $domain) ?? [];

        $learning['failed_insights'] = $learning['failed_insights'] ?? [];
        $learning['failed_insights'][] = [
            'reason' => $reason,
            'insight_type' => $insightData['type'] ?? 'general',
            'recorded_at' => now()->toIso8601String(),
            'context' => $insightData['context'] ?? [],
        ];

        // Keep only last 50 failed insights
        $learning['failed_insights'] = array_slice($learning['failed_insights'], -50);

        $this->storeAgentLearning($userId, $domain, $learning);
    }

    /**
     * Update user preferences for a domain
     */
    public function updateUserPreferences(string $userId, string $domain, array $preferences): void
    {
        $learning = $this->getAgentLearning($userId, $domain) ?? [];

        $learning['user_preferences'] = array_merge(
            $learning['user_preferences'] ?? [],
            $preferences
        );

        $this->storeAgentLearning($userId, $domain, $learning);
    }

    /**
     * Store effective pattern
     */
    public function storeEffectivePattern(string $userId, string $domain, string $patternDescription, array $context = []): void
    {
        $learning = $this->getAgentLearning($userId, $domain) ?? [];

        $learning['effective_patterns'] = $learning['effective_patterns'] ?? [];
        $learning['effective_patterns'][] = [
            'pattern' => $patternDescription,
            'context' => $context,
            'recorded_at' => now()->toIso8601String(),
        ];

        // Keep only last 50 effective patterns
        $learning['effective_patterns'] = array_slice($learning['effective_patterns'], -50);

        $this->storeAgentLearning($userId, $domain, $learning);
    }

    /**
     * Get insight blocks for a specific domain
     */
    public function getDomainInsightBlocks(string $userId, string $domain, ?int $days = 7): array
    {
        $blockType = "flint_{$domain}_insight";

        $blocks = Block::whereHas('event', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
            ->where('block_type', $blockType)
            ->where('time', '>=', now()->subDays($days))
            ->orderBy('time', 'desc')
            ->get();

        return $blocks->toArray();
    }

    /**
     * Get all insight blocks across all domains
     */
    public function getAllInsightBlocks(string $userId, ?int $days = 7): array
    {
        $blocks = Block::whereHas('event', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
            ->where('block_type', 'like', 'flint_%_insight')
            ->where('time', '>=', now()->subDays($days))
            ->orderBy('time', 'desc')
            ->get();

        return $blocks->toArray();
    }

    /**
     * Get cross-domain insight blocks
     */
    public function getCrossDomainInsightBlocks(string $userId, ?int $days = 7): array
    {
        $blocks = Block::whereHas('event', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
            ->where('block_type', 'flint_cross_domain_insight')
            ->where('time', '>=', now()->subDays($days))
            ->orderBy('time', 'desc')
            ->get();

        return $blocks->toArray();
    }

    /**
     * Get pattern detection blocks
     */
    public function getPatternDetectionBlocks(string $userId, ?int $days = 90): array
    {
        $blocks = Block::whereHas('event', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
            ->where('block_type', 'flint_pattern_detected')
            ->where('time', '>=', now()->subDays($days))
            ->orderBy('time', 'desc')
            ->get();

        return $blocks->toArray();
    }

    /**
     * Get prioritized action blocks
     */
    public function getPrioritizedActionBlocks(string $userId, ?int $days = 7): array
    {
        $blocks = Block::whereHas('event', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
            ->where('block_type', 'flint_prioritized_action')
            ->where('time', '>=', now()->subDays($days))
            ->orderBy('time', 'desc')
            ->get();

        return $blocks->toArray();
    }

    /**
     * Get digest blocks
     */
    public function getDigestBlocks(string $userId, ?int $limit = 10): array
    {
        $blocks = Block::whereHas('event', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
            ->where('block_type', 'flint_digest')
            ->orderBy('time', 'desc')
            ->limit($limit)
            ->get();

        return $blocks->toArray();
    }

    /**
     * Get the most recent digest block
     */
    public function getLatestDigest(string $userId): ?Block
    {
        return Block::whereHas('event', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
            ->where('block_type', 'flint_digest')
            ->orderBy('time', 'desc')
            ->first();
    }

    /**
     * Clean up old patterns (beyond retention period)
     */
    public function cleanupOldPatterns(string $userId, int $retentionDays = 365): int
    {
        $cutoff = now()->subDays($retentionDays);

        return EventObject::where('user_id', $userId)
            ->where('concept', 'flint')
            ->where('type', 'pattern')
            ->where('time', '<', $cutoff)
            ->delete();
    }

    /**
     * Get historical feedback statistics for learning
     */
    public function getHistoricalFeedbackStats(string $userId, int $days = 90): array
    {
        $blocks = Block::whereHas('event', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
            ->where('block_type', 'like', 'flint_%')
            ->where('time', '>=', now()->subDays($days))
            ->get();

        $stats = [
            'total_blocks' => $blocks->count(),
            'by_type' => [],
            'avg_confidence' => 0,
            'high_confidence_count' => 0,
        ];

        $confidenceSum = 0;
        $confidenceCount = 0;

        foreach ($blocks as $block) {
            $blockType = $block->block_type;
            $stats['by_type'][$blockType] = ($stats['by_type'][$blockType] ?? 0) + 1;

            $confidence = $block->metadata['confidence'] ?? null;
            if ($confidence !== null) {
                $confidenceSum += $confidence;
                $confidenceCount++;

                if ($confidence >= 0.8) {
                    $stats['high_confidence_count']++;
                }
            }
        }

        if ($confidenceCount > 0) {
            $stats['avg_confidence'] = round($confidenceSum / $confidenceCount, 3);
        }

        return $stats;
    }
}
