<?php

namespace App\Jobs\Data\Monzo;

use App\Integrations\Monzo\MonzoPlugin;
use App\Jobs\Base\BaseProcessingJob;
use Exception;
use Illuminate\Support\Facades\Log;

class MonzoTransactionData extends BaseProcessingJob
{
    protected string $accountId;

    public function __construct($integration, array $rawData, string $accountId)
    {
        parent::__construct($integration, $rawData);
        $this->accountId = $accountId;
    }

    protected function getServiceName(): string
    {
        return 'monzo';
    }

    protected function getJobType(): string
    {
        return 'transactions';
    }

    protected function process(): void
    {
        $transactions = $this->rawData;
        $plugin = new MonzoPlugin;

        if (empty($transactions)) {
            return;
        }

        Log::info('MonzoTransactionData: Processing transactions', [
            'integration_id' => $this->integration->id,
            'transaction_count' => count($transactions),
        ]);

        foreach ($transactions as $tx) {
            try {
                $plugin->processTransactionItem($this->integration, $tx, $this->accountId);
            } catch (Exception $e) {
                Log::error('Failed to process Monzo transaction', [
                    'transaction_id' => $tx['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                    'integration_id' => $this->integration->id,
                ]);
                // Continue processing other transactions
            }
        }

        Log::info('MonzoTransactionData: Completed processing transactions', [
            'integration_id' => $this->integration->id,
        ]);
    }
}
