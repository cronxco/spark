<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Redis;

class PlaywrightHealthMetrics
{
    protected const TTL = 86400; // 24 hours

    /**
     * Record a fetch attempt
     */
    public function recordFetch(string $method, bool $success, int $durationMs, ?string $errorType = null): void
    {
        if (! $this->isRedisAvailable()) {
            return;
        }

        $date = now()->format('Y-m-d');
        $statusKey = $success ? 'success' : 'failed';

        // Increment counters
        $counterKey = "playwright:metrics:fetch:{$date}:method:{$method}:{$statusKey}";
        Redis::incr($counterKey);
        Redis::expire($counterKey, self::TTL);

        // Store duration
        $durationKey = "playwright:metrics:fetch:{$date}:duration:{$method}";
        Redis::rpush($durationKey, $durationMs);
        Redis::expire($durationKey, self::TTL);

        // Store error type if applicable
        if (! $success && $errorType) {
            $errorKey = "playwright:metrics:errors:{$date}:{$errorType}";
            Redis::incr($errorKey);
            Redis::expire($errorKey, self::TTL);
        }
    }

    /**
     * Record stealth bypass attempt
     */
    public function recordStealthBypass(bool $successful): void
    {
        if (! $this->isRedisAvailable()) {
            return;
        }

        $statusKey = $successful ? 'bypassed' : 'detected';
        $key = "playwright:metrics:stealth:{$statusKey}";

        Redis::incr($key);
        Redis::expire($key, self::TTL);
    }

    /**
     * Get statistics for a given period
     *
     * @param  string  $period  '24h' or 'today'
     */
    public function getStats(string $period = '24h'): array
    {
        if (! $this->isRedisAvailable()) {
            return $this->getEmptyStats();
        }

        $date = now()->format('Y-m-d');

        // Fetch counts
        $httpSuccess = (int) Redis::get("playwright:metrics:fetch:{$date}:method:http:success") ?: 0;
        $httpFailed = (int) Redis::get("playwright:metrics:fetch:{$date}:method:http:failed") ?: 0;
        $playwrightSuccess = (int) Redis::get("playwright:metrics:fetch:{$date}:method:playwright:success") ?: 0;
        $playwrightFailed = (int) Redis::get("playwright:metrics:fetch:{$date}:method:playwright:failed") ?: 0;

        // Calculate success rates
        $httpTotal = $httpSuccess + $httpFailed;
        $playwrightTotal = $playwrightSuccess + $playwrightFailed;

        $httpSuccessRate = $httpTotal > 0 ? ($httpSuccess / $httpTotal) * 100 : 0;
        $playwrightSuccessRate = $playwrightTotal > 0 ? ($playwrightSuccess / $playwrightTotal) * 100 : 0;

        // Calculate average durations
        $httpDurations = Redis::lrange("playwright:metrics:fetch:{$date}:duration:http", 0, -1);
        $playwrightDurations = Redis::lrange("playwright:metrics:fetch:{$date}:duration:playwright", 0, -1);

        $httpAvgDuration = $this->calculateAverage($httpDurations);
        $playwrightAvgDuration = $this->calculateAverage($playwrightDurations);

        // Stealth stats
        $stealthBypassed = (int) Redis::get('playwright:metrics:stealth:bypassed') ?: 0;
        $stealthDetected = (int) Redis::get('playwright:metrics:stealth:detected') ?: 0;
        $stealthTotal = $stealthBypassed + $stealthDetected;
        $stealthEffectiveness = $stealthTotal > 0 ? ($stealthBypassed / $stealthTotal) * 100 : 0;

        return [
            'http' => [
                'total' => $httpTotal,
                'success' => $httpSuccess,
                'failed' => $httpFailed,
                'success_rate' => round($httpSuccessRate, 1),
                'avg_duration_ms' => round($httpAvgDuration),
            ],
            'playwright' => [
                'total' => $playwrightTotal,
                'success' => $playwrightSuccess,
                'failed' => $playwrightFailed,
                'success_rate' => round($playwrightSuccessRate, 1),
                'avg_duration_ms' => round($playwrightAvgDuration),
            ],
            'stealth' => [
                'bypassed' => $stealthBypassed,
                'detected' => $stealthDetected,
                'total' => $stealthTotal,
                'effectiveness' => round($stealthEffectiveness, 1),
            ],
            'total_fetches' => $httpTotal + $playwrightTotal,
        ];
    }

    /**
     * Get worker information
     */
    public function getWorkerInfo(): array
    {
        // This will be populated by PlaywrightFetchClient
        return [];
    }

    /**
     * Get recent errors
     */
    public function getRecentErrors(int $limit = 10): array
    {
        if (! $this->isRedisAvailable()) {
            return [];
        }

        $date = now()->format('Y-m-d');

        // Get all error keys for today
        $pattern = "playwright:metrics:errors:{$date}:*";
        $keys = Redis::keys($pattern);

        $errors = [];
        foreach ($keys as $key) {
            $count = (int) Redis::get($key);
            $errorType = str_replace("playwright:metrics:errors:{$date}:", '', $key);

            $errors[] = [
                'type' => $errorType,
                'count' => $count,
                'date' => $date,
            ];
        }

        // Sort by count descending
        usort($errors, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_slice($errors, 0, $limit);
    }

    /**
     * Clear all metrics (useful for testing)
     */
    public function clearMetrics(): void
    {
        if (! $this->isRedisAvailable()) {
            return;
        }

        $date = now()->format('Y-m-d');
        $patterns = [
            "playwright:metrics:fetch:{$date}:*",
            'playwright:metrics:stealth:*',
            "playwright:metrics:errors:{$date}:*",
        ];

        foreach ($patterns as $pattern) {
            $keys = Redis::keys($pattern);
            if (! empty($keys)) {
                Redis::del($keys);
            }
        }
    }

    /**
     * Calculate average from array of numbers
     */
    protected function calculateAverage(array $numbers): float
    {
        if (empty($numbers)) {
            return 0;
        }

        $sum = array_sum(array_map('floatval', $numbers));

        return $sum / count($numbers);
    }

    /**
     * Check if Redis is available
     */
    protected function isRedisAvailable(): bool
    {
        try {
            Redis::ping();

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get empty stats structure
     */
    protected function getEmptyStats(): array
    {
        return [
            'http' => [
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'success_rate' => 0,
                'avg_duration_ms' => 0,
            ],
            'playwright' => [
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'success_rate' => 0,
                'avg_duration_ms' => 0,
            ],
            'stealth' => [
                'bypassed' => 0,
                'detected' => 0,
                'total' => 0,
                'effectiveness' => 0,
            ],
            'total_fetches' => 0,
        ];
    }
}
