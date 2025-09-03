<?php

namespace App\Jobs\OAuth\Monzo;

use App\Integrations\Monzo\MonzoPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Monzo\MonzoBalanceData;
use Exception;
use Illuminate\Support\Facades\Http;

class MonzoBalancePull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'monzo';
    }

    protected function getJobType(): string
    {
        return 'balances';
    }

    protected function fetchData(): array
    {
        $plugin = new MonzoPlugin;
        $accounts = $plugin->listAccounts($this->integration);

        if (empty($accounts)) {
            return [];
        }

        $allBalances = [];

        foreach ($accounts as $account) {
            // Log the API request
            $plugin->logApiRequest('GET', '/balance', [
                'Authorization' => '[REDACTED]',
            ], [
                'account_id' => $account['id'],
            ], $this->integration->id);

            $response = Http::withHeaders($plugin->authHeaders($this->integration))
                ->get($plugin->getBaseUrl() . '/balance', [
                    'account_id' => $account['id'],
                ]);

            // Log the API response
            $plugin->logApiResponse('GET', '/balance', $response->status(), $response->body(), $response->headers(), $this->integration->id);

            if (! $response->successful()) {
                throw new Exception('Failed to fetch balance from Monzo API: ' . $response->body());
            }

            $balanceData = $response->json();
            $balanceData['_account'] = $account;
            $allBalances[$account['id']] = $balanceData;
        }

        return $allBalances;
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch balance processing jobs for each account
        foreach ($rawData as $accountId => $balanceData) {
            MonzoBalanceData::dispatch($this->integration, $balanceData, $accountId);
        }
    }
}
