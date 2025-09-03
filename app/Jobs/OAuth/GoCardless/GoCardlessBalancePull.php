<?php

namespace App\Jobs\OAuth\GoCardless;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\GoCardless\GoCardlessBalanceData;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GoCardlessBalancePull extends BaseFetchJob
{
    // Rate limit cache keys
    private const BALANCE_CALLS_CACHE_KEY = 'gocardless_balance_calls';

    private const MAX_DAILY_BALANCE_CALLS = 10; // GoCardless limit

    protected function getServiceName(): string
    {
        return 'gocardless';
    }

    protected function getJobType(): string
    {
        return 'balances';
    }

    protected function fetchData(): array
    {
        $plugin = new GoCardlessBankPlugin;
        $group = $this->integration->group;

        if (! $group || empty($group->account_id)) {
            throw new Exception('Missing GoCardless group or account_id');
        }

        $accountId = $group->account_id;

        // Check if we've exceeded daily balance API call limits
        if (! $this->canMakeBalanceApiCall($accountId)) {
            throw new Exception('Daily GoCardless balance API call limit exceeded for account. Please wait until tomorrow.');
        }

        $cacheKey = "gocardless_balances_{$accountId}";
        $cachedData = Cache::get($cacheKey);

        if ($cachedData) {
            $plugin->logApiRequest('GET', "/accounts/{$accountId}/balances/", [], [], $this->integration->id, 'CACHE_HIT');

            return $cachedData;
        }

        // Make API request with rate limit awareness
        $plugin->logApiRequest('GET', "/accounts/{$accountId}/balances/", [
            'Authorization' => '[REDACTED]',
        ], [], $this->integration->id);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $plugin->getAccessToken(),
        ])
            ->get($plugin->getBaseUrl() . "/accounts/{$accountId}/balances/");

        $plugin->logApiResponse('GET', "/accounts/{$accountId}/balances/", $response->status(), $response->body(), $response->headers(), $this->integration->id);

        // Handle rate limiting gracefully
        if ($response->status() === 429) {
            throw new Exception('GoCardless balance API rate limit exceeded. Please wait before retrying.');
        }

        if (! $response->successful()) {
            throw new Exception('Failed to fetch balances from GoCardless API: ' . $response->body());
        }

        $data = $response->json();

        // Cache the result (for 1 hour to allow for updates)
        Cache::put($cacheKey, $data, 3600);

        // Record this API call for rate limiting
        $this->recordBalanceApiCall($accountId);

        return $data;
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch balance processing job
        GoCardlessBalanceData::dispatch($this->integration, $rawData);
    }

    /**
     * Check if we can make a balance API call without exceeding rate limits
     */
    private function canMakeBalanceApiCall(string $accountId): bool
    {
        $calls = Cache::get(self::BALANCE_CALLS_CACHE_KEY, []);
        $today = now()->toDateString();
        $accountCalls = array_filter($calls, function ($call) use ($accountId, $today) {
            return $call['account_id'] === $accountId && $call['date'] === $today;
        });

        return count($accountCalls) < self::MAX_DAILY_BALANCE_CALLS;
    }

    /**
     * Record a balance API call for rate limiting
     */
    private function recordBalanceApiCall(string $accountId): void
    {
        $calls = Cache::get(self::BALANCE_CALLS_CACHE_KEY, []);
        $calls[] = [
            'account_id' => $accountId,
            'date' => now()->toDateString(),
            'timestamp' => now()->toISOString(),
        ];

        // Keep only recent calls (last 7 days)
        $sevenDaysAgo = now()->subDays(7)->toDateString();
        $calls = array_filter($calls, function ($call) use ($sevenDaysAgo) {
            return $call['date'] >= $sevenDaysAgo;
        });

        Cache::put(self::BALANCE_CALLS_CACHE_KEY, array_values($calls), 604800); // 7 days
    }
}
