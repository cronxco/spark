<?php

namespace App\Jobs\Data\GoCardless;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Jobs\Base\BaseProcessingJob;
use Illuminate\Support\Facades\Log;

class GoCardlessTransactionData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'gocardless';
    }

    protected function getJobType(): string
    {
        return 'transactions';
    }

    protected function process(): void
    {
        $transactionData = $this->rawData;
        $plugin = new GoCardlessBankPlugin;

        // Extract transactions from the API response
        $bookedTransactions = $transactionData['transactions']['booked'] ?? [];
        $pendingTransactions = $transactionData['transactions']['pending'] ?? [];

        // Create account array from integration configuration
        $account = [
            'id' => $this->integration->configuration['account_id'] ?? null,
        ];

        Log::info('GoCardlessTransactionData: Processing transactions', [
            'integration_id' => $this->integration->id,
            'account_id' => $account['id'] ?? 'unknown',
            'account' => $account,
            'pending_count' => count($pendingTransactions),
            'booked_count' => count($bookedTransactions),
            'has_account_id_in_config' => isset($this->integration->configuration['account_id']),
        ]);

        // Process pending transactions first
        foreach ($pendingTransactions as $transaction) {
            $plugin->processTransactionItem($this->integration, $account, $transaction, 'pending');
        }

        // Process booked transactions (these may update existing pending transactions)
        foreach ($bookedTransactions as $transaction) {
            $plugin->processTransactionItem($this->integration, $account, $transaction, 'booked');
        }
    }
}
