<?php

namespace App\Jobs\Initialization\Monzo;

use App\Integrations\Monzo\MonzoPlugin;
use App\Jobs\Base\BaseInitializationJob;
use App\Jobs\Data\Monzo\MonzoBalanceData;
use App\Jobs\Data\Monzo\MonzoPotData;
use App\Jobs\Data\Monzo\MonzoTransactionData;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MonzoHistoricalData extends BaseInitializationJob
{
    protected function getServiceName(): string
    {
        return 'monzo';
    }

    protected function getJobType(): string
    {
        return 'historical';
    }

    protected function initialize(): void
    {
        $plugin = new MonzoPlugin;
        $accounts = $plugin->listAccounts($this->integration);

        if (empty($accounts)) {
            return;
        }

        // Fetch historical data (last 30 days instead of 7)
        $sinceIso = now()->subDays(30)->toIso8601String();

        foreach ($accounts as $account) {
            try {
                $this->fetchHistoricalTransactions($plugin, $account, $sinceIso);
                $this->fetchHistoricalBalances($plugin, $account);
                $this->fetchHistoricalPots($plugin, $account);
            } catch (Exception $e) {
                // Log but continue with other accounts
                Log::error("Failed to fetch historical data for account {$account['id']}", [
                    'error' => $e->getMessage(),
                    'integration_id' => $this->integration->id,
                ]);
            }
        }
    }

    private function fetchHistoricalTransactions(MonzoPlugin $plugin, array $account, string $sinceIso): void
    {
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
            throw new Exception('Failed to fetch historical transactions: ' . $response->body());
        }

        $transactions = $response->json('transactions') ?? [];
        if (! empty($transactions)) {
            MonzoTransactionData::dispatch($this->integration, $transactions, $account['id']);
        }
    }

    private function fetchHistoricalBalances(MonzoPlugin $plugin, array $account): void
    {
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
            throw new Exception('Failed to fetch historical balance: ' . $response->body());
        }

        $balanceData = $response->json();
        $balanceData['_account'] = $account;

        MonzoBalanceData::dispatch($this->integration, $balanceData, $account['id']);
    }

    private function fetchHistoricalPots(MonzoPlugin $plugin, array $account): void
    {
        // Log the API request
        $plugin->logApiRequest('GET', '/pots', [
            'Authorization' => '[REDACTED]',
        ], [
            'current_account_id' => $account['id'],
        ], $this->integration->id);

        $response = Http::withHeaders($plugin->authHeaders($this->integration))
            ->get($plugin->getBaseUrl() . '/pots', [
                'current_account_id' => $account['id'],
            ]);

        // Log the API response
        $plugin->logApiResponse('GET', '/pots', $response->status(), $response->body(), $response->headers(), $this->integration->id);

        if (! $response->successful()) {
            throw new Exception('Failed to fetch historical pots: ' . $response->body());
        }

        $pots = $response->json('pots') ?? [];
        if (! empty($pots)) {
            MonzoPotData::dispatch($this->integration, $pots, $account['id']);
        }
    }
}
