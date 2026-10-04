<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Short-lived, cache-backed store for the feedback users leave on Flint blocks.
 *
 * This used to back the whole in-app agent pipeline (domain insights, urgent
 * flags, inter-agent queries, prioritised actions). That pipeline is gone —
 * digests are produced externally by the Flint routine and written back through
 * the create-flint-digest MCP tool — so all that remains is the block-feedback
 * signal, which the Flint web UI both writes and reads.
 */
class AgentWorkingMemoryService
{
    protected const CACHE_PREFIX = 'flint:working_memory:';

    protected const DEFAULT_TTL = 60 * 24 * 7; // 7 days in minutes

    /**
     * Record user feedback on a Flint block.
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
     * Aggregate statistics over the recorded feedback.
     *
     * @return array<string, mixed>
     */
    public function getFeedbackStatistics(string $userId): array
    {
        $feedback = $this->getWorkingMemory($userId)['user_feedback'] ?? [];

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
     * Discard everything recorded for a user.
     */
    public function clearWorkingMemory(string $userId): void
    {
        Cache::forget($this->getCacheKey($userId));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getWorkingMemory(string $userId): array
    {
        return Cache::get($this->getCacheKey($userId), ['user_feedback' => []]);
    }

    protected function getCacheKey(string $userId): string
    {
        return self::CACHE_PREFIX . $userId;
    }
}
