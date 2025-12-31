<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class InsightDeduplicationService
{
    /**
     * Cache key prefix for storing seen insights
     */
    protected string $cachePrefix = 'insight_dedup';

    /**
     * How long to remember insights (in seconds)
     */
    protected int $memoryTtl = 86400; // 24 hours

    /**
     * Similarity threshold for considering two insights as duplicates (0.0 - 1.0)
     */
    protected float $similarityThreshold = 0.85;

    /**
     * Check if an insight is a duplicate of recently seen insights
     *
     * @param  array  $insight  Insight to check (must have 'title' and 'description')
     * @param  string  $domain  Domain the insight belongs to (health, money, etc.)
     * @param  int  $userId  User ID
     * @return array ['is_duplicate' => bool, 'similar_to' => string|null, 'similarity_score' => float|null]
     */
    public function isDuplicate(array $insight, string $domain, int $userId): array
    {
        if (! isset($insight['title']) || ! isset($insight['description'])) {
            return [
                'is_duplicate' => false,
                'similar_to' => null,
                'similarity_score' => null,
            ];
        }

        // Get recently seen insights for this user/domain
        $seenInsights = $this->getSeenInsights($userId, $domain);

        // Generate signature for current insight
        $currentSignature = $this->generateSignature($insight);

        // Check for exact match first (fastest)
        if (isset($seenInsights[$currentSignature])) {
            return [
                'is_duplicate' => true,
                'similar_to' => $currentSignature,
                'similarity_score' => 1.0,
                'seen_at' => $seenInsights[$currentSignature]['seen_at'],
            ];
        }

        // Check for similar insights using text similarity
        foreach ($seenInsights as $signature => $seenInsight) {
            $similarity = $this->calculateSimilarity(
                $insight['title'] . ' ' . $insight['description'],
                $seenInsight['title'] . ' ' . $seenInsight['description']
            );

            if ($similarity >= $this->similarityThreshold) {
                return [
                    'is_duplicate' => true,
                    'similar_to' => $signature,
                    'similarity_score' => $similarity,
                    'seen_at' => $seenInsight['seen_at'],
                ];
            }
        }

        return [
            'is_duplicate' => false,
            'similar_to' => null,
            'similarity_score' => null,
        ];
    }

    /**
     * Mark an insight as seen (store in cache)
     *
     * @param  array  $insight  Insight to remember
     * @param  string  $domain  Domain the insight belongs to
     * @param  int  $userId  User ID
     * @return string The signature/hash of the insight
     */
    public function markAsSeen(array $insight, string $domain, int $userId): string
    {
        $signature = $this->generateSignature($insight);
        $cacheKey = $this->getCacheKey($userId, $domain);

        $seenInsights = $this->getSeenInsights($userId, $domain);

        $seenInsights[$signature] = [
            'title' => $insight['title'] ?? '',
            'description' => $insight['description'] ?? '',
            'confidence' => $insight['confidence'] ?? 0,
            'seen_at' => now()->toISOString(),
        ];

        Cache::put($cacheKey, $seenInsights, $this->memoryTtl);

        return $signature;
    }

    /**
     * Get all seen insights for a user/domain
     *
     * @param  int  $userId
     * @param  string  $domain
     * @return array Keyed by signature
     */
    protected function getSeenInsights(int $userId, string $domain): array
    {
        $cacheKey = $this->getCacheKey($userId, $domain);

        return Cache::get($cacheKey, []);
    }

    /**
     * Generate a unique signature/hash for an insight
     *
     * This uses normalized text to create a consistent hash
     *
     * @param  array  $insight
     * @return string
     */
    protected function generateSignature(array $insight): string
    {
        // Normalize text: lowercase, remove punctuation, trim whitespace
        $text = $this->normalizeText(($insight['title'] ?? '') . ' ' . ($insight['description'] ?? ''));

        return md5($text);
    }

    /**
     * Calculate text similarity between two strings using Levenshtein distance
     *
     * Returns a similarity score between 0.0 (completely different) and 1.0 (identical)
     *
     * @param  string  $text1
     * @param  string  $text2
     * @return float
     */
    protected function calculateSimilarity(string $text1, string $text2): float
    {
        // Normalize both texts
        $normalized1 = $this->normalizeText($text1);
        $normalized2 = $this->normalizeText($text2);

        // Quick check: if they're identical after normalization
        if ($normalized1 === $normalized2) {
            return 1.0;
        }

        // Use Levenshtein distance for similarity
        // Note: Levenshtein has a limit of 255 characters in PHP
        // For longer texts, we'll use a different approach

        $len1 = strlen($normalized1);
        $len2 = strlen($normalized2);

        // If either text is empty, similarity is 0
        if ($len1 === 0 || $len2 === 0) {
            return 0.0;
        }

        // For very long texts, use word-level comparison instead
        if ($len1 > 255 || $len2 > 255) {
            return $this->calculateWordSimilarity($normalized1, $normalized2);
        }

        // Calculate Levenshtein distance
        $distance = levenshtein($normalized1, $normalized2);

        // Convert distance to similarity score (0.0 - 1.0)
        $maxLength = max($len1, $len2);
        $similarity = 1.0 - ($distance / $maxLength);

        return max(0.0, min(1.0, $similarity));
    }

    /**
     * Calculate similarity using word overlap (for longer texts)
     *
     * @param  string  $text1
     * @param  string  $text2
     * @return float
     */
    protected function calculateWordSimilarity(string $text1, string $text2): float
    {
        $words1 = array_filter(explode(' ', $text1));
        $words2 = array_filter(explode(' ', $text2));

        if (empty($words1) || empty($words2)) {
            return 0.0;
        }

        // Calculate Jaccard similarity (intersection over union)
        $intersection = count(array_intersect($words1, $words2));
        $union = count(array_unique(array_merge($words1, $words2)));

        if ($union === 0) {
            return 0.0;
        }

        return $intersection / $union;
    }

    /**
     * Normalize text for comparison
     *
     * @param  string  $text
     * @return string
     */
    protected function normalizeText(string $text): string
    {
        // Convert to lowercase
        $text = Str::lower($text);

        // Remove punctuation
        $text = preg_replace('/[^\w\s]/', '', $text);

        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Generate cache key for user/domain insights
     *
     * @param  int  $userId
     * @param  string  $domain
     * @return string
     */
    protected function getCacheKey(int $userId, string $domain): string
    {
        return "{$this->cachePrefix}:{$userId}:{$domain}";
    }

    /**
     * Clear all seen insights for a user (useful for testing)
     *
     * @param  int  $userId
     * @param  string|null  $domain  Specific domain or null for all domains
     */
    public function clearSeenInsights(int $userId, ?string $domain = null): void
    {
        if ($domain) {
            Cache::forget($this->getCacheKey($userId, $domain));
        } else {
            // Clear all domains
            $domains = ['health', 'money', 'media', 'knowledge', 'online', 'future', 'cross_domain'];
            foreach ($domains as $dom) {
                Cache::forget($this->getCacheKey($userId, $dom));
            }
        }
    }

    /**
     * Get deduplication statistics for a user
     *
     * @param  int  $userId
     * @return array
     */
    public function getStatistics(int $userId): array
    {
        $domains = ['health', 'money', 'media', 'knowledge', 'online', 'future', 'cross_domain'];
        $stats = [];

        foreach ($domains as $domain) {
            $seenInsights = $this->getSeenInsights($userId, $domain);
            $stats[$domain] = count($seenInsights);
        }

        $stats['total'] = array_sum($stats);

        return $stats;
    }

    /**
     * Set custom similarity threshold
     *
     * @param  float  $threshold  Value between 0.0 and 1.0
     */
    public function setSimilarityThreshold(float $threshold): void
    {
        $this->similarityThreshold = max(0.0, min(1.0, $threshold));
    }

    /**
     * Get current similarity threshold
     *
     * @return float
     */
    public function getSimilarityThreshold(): float
    {
        return $this->similarityThreshold;
    }
}
