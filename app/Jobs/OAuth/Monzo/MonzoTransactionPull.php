<?php

namespace App\Jobs\OAuth\Monzo;

use App\Integrations\Monzo\MonzoPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Monzo\MonzoTransactionData;
use Carbon\Carbon;
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

        $config = $this->integration->configuration ?? [];
        $isDailySweep = false;
        $lastSweepAt = isset($config['monzo_last_sweep_at']) ? Carbon::parse($config['monzo_last_sweep_at']) : null;
        if (! $lastSweepAt || $lastSweepAt->lt(now()->subHours(22))) {
            $isDailySweep = true;
        }

        foreach ($accounts as $account) {
            // Incremental: 1 day; Sweep: 30 days
            $daysBack = $isDailySweep ? 30 : 1;
            $sinceIso = now()->subDays($daysBack)->toIso8601String();

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

            // If we hit the limit, page forward using the oldest transaction created date
            while (count($transactions) === 100) {
                $lastCreated = end($transactions)['created'] ?? null;
                if (! $lastCreated) {
                    break;
                }
                $nextResp = Http::withHeaders($plugin->authHeaders($this->integration))
                    ->get($plugin->getBaseUrl() . '/transactions', [
                        'account_id' => $account['id'],
                        'expand[]' => 'merchant',
                        'since' => $sinceIso,
                        'before' => $lastCreated, // Monzo supports before cursor by created timestamp
                        'limit' => 100,
                    ]);
                $plugin->logApiResponse('GET', '/transactions', $nextResp->status(), $nextResp->body(), $nextResp->headers(), $this->integration->id);
                if (! $nextResp->successful()) {
                    break;
                }
                $batch = $nextResp->json('transactions') ?? [];
                if (empty($batch)) {
                    break;
                }
                $transactions = array_merge($transactions, $batch);
                if (count($batch) < 100) {
                    break;
                }
            }
            $allTransactions[$account['id']] = $transactions;
        }

        if ($isDailySweep) {
            $config['monzo_last_sweep_at'] = now()->toIso8601String();
            $this->integration->update(['configuration' => $config]);
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
