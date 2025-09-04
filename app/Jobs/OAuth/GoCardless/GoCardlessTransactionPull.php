<?php

namespace App\Jobs\OAuth\GoCardless;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\GoCardless\GoCardlessTransactionData;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoCardlessTransactionPull extends BaseFetchJob
{
    // Rate limit cache keys
    private const TRANSACTION_CALLS_CACHE_KEY = 'gocardless_transaction_calls';

    private const MAX_DAILY_TRANSACTION_CALLS = 10; // GoCardless limit

    protected function getServiceName(): string
    {
        return 'gocardless';
    }

    protected function getJobType(): string
    {
        return 'transactions';
    }

    protected function fetchData(): array
    {
        $plugin = new GoCardlessBankPlugin;

        if (empty($this->integration->configuration['account_id'])) {
            throw new Exception('Missing GoCardless account_id in integration configuration');
        }

        $accountId = $this->integration->configuration['account_id'];

        // Validate account exists before making API calls
        if (! $this->validateAccountExists($accountId, $plugin)) {
            // Log the problematic integration for monitoring
            Log::warning('GoCardless integration failed due to invalid account ID - account not found in GoCardless API', [
                'integration_id' => $this->integration->id,
                'integration_name' => $this->integration->name,
                'account_id' => $accountId,
                'user_id' => $this->integration->user_id,
                'error_type' => 'invalid_account_id',
                'service' => 'gocardless',
                'job_type' => 'transactions',
                'action' => 'account_validation_failed',
                'recommendation' => 'Check if the account has been disconnected or if the account ID is correct',
            ]);

            throw new Exception('Account ID not found in GoCardless API. The configured account may have been disconnected or the account ID may be incorrect.');
        }

        // Check if we've exceeded daily transaction API call limits
        if (! $this->canMakeTransactionApiCall($accountId)) {
            throw new Exception('Daily GoCardless transaction API call limit exceeded for account. Please wait until tomorrow.');
        }

        // Get date range for transactions (last 7 days by default)
        $daysBack = (int) ($this->integration->configuration['days_back'] ?? 7);
        $startDate = now()->subDays($daysBack)->toDateString();
        $endDate = now()->toDateString();

        $cacheKey = "gocardless_transactions_{$accountId}_{$startDate}_{$endDate}";
        $cachedData = Cache::get($cacheKey);

        if ($cachedData) {
            $plugin->logApiRequest('GET', "/accounts/{$accountId}/transactions/", [], [
                'date_from' => $startDate,
                'date_to' => $endDate,
            ], $this->integration->id, 'CACHE_HIT');

            return $cachedData;
        }

        // Make API request with rate limit awareness
        $plugin->logApiRequest('GET', "/accounts/{$accountId}/transactions/", [
            'Authorization' => '[REDACTED]',
        ], [
            'date_from' => $startDate,
            'date_to' => $endDate,
        ], $this->integration->id);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $plugin->getAccessToken(),
        ])
            ->get($plugin->getBaseUrl() . "/accounts/{$accountId}/transactions/", [
                'date_from' => $startDate,
                'date_to' => $endDate,
            ]);

        $plugin->logApiResponse('GET', "/accounts/{$accountId}/transactions/", $response->status(), $response->body(), $response->headers(), $this->integration->id);

        // Handle rate limiting gracefully
        if ($response->status() === 429) {
            throw new Exception('GoCardless transaction API rate limit exceeded. Please wait before retrying.');
        }

        if (! $response->successful()) {
            throw new Exception('Failed to fetch transactions from GoCardless API: ' . $response->body());
        }

        $data = $response->json();

        // Cache the result (for 1 hour to allow for updates)
        Cache::put($cacheKey, $data, 3600);

        // Record this API call for rate limiting
        $this->recordTransactionApiCall($accountId);

        return $data;
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch transaction processing job
        GoCardlessTransactionData::dispatch($this->integration, $rawData);
    }

    /**
     * Check if we can make a transaction API call without exceeding rate limits
     */
    private function canMakeTransactionApiCall(string $accountId): bool
    {
        $calls = Cache::get(self::TRANSACTION_CALLS_CACHE_KEY, []);
        $today = now()->toDateString();
        $accountCalls = array_filter($calls, function ($call) use ($accountId, $today) {
            return $call['account_id'] === $accountId && $call['date'] === $today;
        });

        return count($accountCalls) < self::MAX_DAILY_TRANSACTION_CALLS;
    }

    /**
     * Record a transaction API call for rate limiting
     */
    private function recordTransactionApiCall(string $accountId): void
    {
        $calls = Cache::get(self::TRANSACTION_CALLS_CACHE_KEY, []);
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

        Cache::put(self::TRANSACTION_CALLS_CACHE_KEY, array_values($calls), 604800); // 7 days
    }

    /**
     * Validate that the account exists in GoCardless before making API calls
     */
    private function validateAccountExists(string $accountId, GoCardlessBankPlugin $plugin): bool
    {
        try {
            // Check if we have a cached validation result
            $validationCacheKey = "gocardless_account_validation_{$accountId}";
            $cachedValidation = Cache::get($validationCacheKey);

            if ($cachedValidation === true) {
                return true;
            }

            if ($cachedValidation === false) {
                return false;
            }

            // Make a lightweight API call to check if account exists
            $plugin->logApiRequest('GET', "/accounts/{$accountId}/", [
                'Authorization' => '[REDACTED]',
            ], [], $this->integration->id);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $plugin->getAccessToken(),
            ])
                ->get($plugin->getBaseUrl() . "/accounts/{$accountId}/");

            $plugin->logApiResponse('GET', "/accounts/{$accountId}/", $response->status(), $response->body(), $response->headers(), $this->integration->id);

            if ($response->status() === 404) {
                // Account doesn't exist, cache this result for 24 hours
                Cache::put($validationCacheKey, false, 86400);

                // Log the invalid account ID detection
                Log::info('GoCardless account validation failed - account not found', [
                    'integration_id' => $this->integration->id,
                    'account_id' => $accountId,
                    'job_type' => 'transactions',
                    'api_response_status' => 404,
                    'api_response_body' => $response->body(),
                    'action' => 'account_validation_failed',
                ]);

                return false;
            }

            if ($response->successful()) {
                // Account exists, cache this result for 24 hours
                Cache::put($validationCacheKey, true, 86400);

                return true;
            }

            // For other errors (rate limits, server errors), assume account exists to avoid false negatives
            return true;

        } catch (Exception $e) {
            // If validation fails due to network issues, assume account exists
            return true;
        }
    }
}
