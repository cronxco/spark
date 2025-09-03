<?php

namespace App\Jobs\OAuth\GoCardless;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\GoCardless\GoCardlessAccountData;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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
        $group = $this->integration->group;

        if (! $group || empty($group->account_id)) {
            throw new Exception('Missing GoCardless group or account_id');
        }

        $accountId = $group->account_id;

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
}
