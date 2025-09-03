<?php

namespace App\Jobs\OAuth\Monzo;

use App\Integrations\Monzo\MonzoPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Monzo\MonzoPotData;
use Exception;
use Illuminate\Support\Facades\Http;

class MonzoPotPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'monzo';
    }

    protected function getJobType(): string
    {
        return 'pots';
    }

    protected function fetchData(): array
    {
        $plugin = new MonzoPlugin;
        $accounts = $plugin->listAccounts($this->integration);

        if (empty($accounts)) {
            return [];
        }

        $allPots = [];

        foreach ($accounts as $account) {
            // Log the API request
            $plugin->logApiRequest('GET', '/pots', [
                'Authorization' => '[REDACTED]',
            ], [
                'current_account_id' => $account['id'],
            ], $this->integration->id);

            $response = Http::withHeaders($plugin->getAuthHeaders($this->integration))
                ->get($plugin->apiBase . '/pots', [
                    'current_account_id' => $account['id'],
                ]);

            // Log the API response
            $plugin->logApiResponse('GET', '/pots', $response->status(), $response->body(), $response->headers(), $this->integration->id);

            if (! $response->successful()) {
                throw new Exception('Failed to fetch pots from Monzo API: ' . $response->body());
            }

            $pots = $response->json('pots') ?? [];
            $allPots[$account['id']] = $pots;
        }

        return $allPots;
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch pot processing jobs for each account
        foreach ($rawData as $accountId => $pots) {
            if (! empty($pots)) {
                MonzoPotData::dispatch($this->integration, $pots, $accountId);
            }
        }
    }
}
