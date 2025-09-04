<?php

namespace App\Jobs\OAuth\GoCardless;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\GoCardless\GoCardlessAccountData;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoCardlessAccountPull extends BaseFetchJob
{
    // GoCardless rate limit constants - very strict!
    private const ACCOUNT_DETAILS_CACHE_TTL = 86400; // 24 hours

    protected function getServiceName(): string
    {
        return 'gocardless';
    }

    protected function getJobType(): string
    {
        return 'accounts';
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
                'job_type' => 'accounts',
                'action' => 'account_validation_failed',
                'recommendation' => 'Check if the account has been disconnected or if the account ID is correct',
            ]);

            throw new Exception('Account ID not found in GoCardless API. The configured account may have been disconnected or the account ID may be incorrect.');
        }

        // Check cache first to respect rate limits
        $cacheKey = "gocardless_account_details_{$accountId}";
        $cachedData = Cache::get($cacheKey);

        if ($cachedData) {
            $plugin->logApiRequest('GET', "/accounts/{$accountId}/details/", [], [], $this->integration->id, 'CACHE_HIT');

            return $cachedData;
        }

        // Make API request with rate limit awareness
        $plugin->logApiRequest('GET', "/accounts/{$accountId}/details/", [
            'Authorization' => '[REDACTED]',
        ], [], $this->integration->id);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $plugin->getAccessToken(),
        ])
            ->get($plugin->getBaseUrl() . "/accounts/{$accountId}/details/");

        $plugin->logApiResponse('GET', "/accounts/{$accountId}/details/", $response->status(), $response->body(), $response->headers(), $this->integration->id);

        // Handle rate limiting gracefully
        if ($response->status() === 429) {
            throw new Exception('GoCardless account details rate limit exceeded. Please wait before retrying.');
        }

        if (! $response->successful()) {
            throw new Exception('Failed to fetch account details from GoCardless API: ' . $response->body());
        }

        $data = $response->json();

        // Cache the result to respect rate limits
        Cache::put($cacheKey, $data, self::ACCOUNT_DETAILS_CACHE_TTL);

        return $data;
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch account processing job
        GoCardlessAccountData::dispatch($this->integration, $rawData);
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
