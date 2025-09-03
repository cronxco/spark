<?php

namespace App\Jobs\OAuth\Monzo;

use App\Integrations\Monzo\MonzoPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Monzo\MonzoTransactionData;
use Exception;
use Illuminate\Support\Facades\Http;

class MonzoTransactionPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'monzo';
    }

    protected function getJobType(): string
    {
        return 'transactions';
    }

    protected function fetchData(): array
    {
        $plugin = new MonzoPlugin;
        $accounts = $plugin->listAccounts($this->integration);

        if (empty($accounts)) {
            return [];
        }

        $allTransactions = [];

        foreach ($accounts as $account) {
            $sinceIso = now()->subDays(7)->toIso8601String();

            // Log the API request
            $plugin->logApiRequest('GET', '/transactions', [
                'Authorization' => '[REDACTED]',
            ], [
                'account_id' => $account['id'],
                'expand[]' => 'merchant',
                'since' => $sinceIso,
                'limit' => 100,
            ], $this->integration->id);

            $response = Http::withHeaders($plugin->authHeaders($this->integration))
                ->get($plugin->getBaseUrl() . '/transactions', [
                    'account_id' => $account['id'],
                    'expand[]' => 'merchant',
                    'since' => $sinceIso,
                    'limit' => 100,
                ]);

            // Log the API response
            $plugin->logApiResponse('GET', '/transactions', $response->status(), $response->body(), $response->headers(), $this->integration->id);

            if (! $response->successful()) {
                throw new Exception('Failed to fetch transactions from Monzo API: ' . $response->body());
            }

            $transactions = $response->json('transactions') ?? [];
            $allTransactions[$account['id']] = $transactions;
        }

        return $allTransactions;
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch transaction processing jobs for each account
        foreach ($rawData as $accountId => $transactions) {
            if (! empty($transactions)) {
                MonzoTransactionData::dispatch($this->integration, $transactions, $accountId);
            }
        }
    }
}
