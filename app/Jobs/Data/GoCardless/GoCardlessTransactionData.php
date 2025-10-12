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

        // Get account information from the transaction data and merge with integration config
        $account = array_merge(
            ['id' => $this->integration->configuration['account_id'] ?? null],
            $transactionData['account'] ?? []
        );

        Log::info('GoCardlessTransactionData: Processing transactions', [
            'integration_id' => $this->integration->id,
            'account_id' => $account['id'],
            'pending_count' => count($pendingTransactions),
            'booked_count' => count($bookedTransactions),
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
