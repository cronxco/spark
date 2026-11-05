<?php

namespace App\Jobs\OAuth\Monzo;

use App\Integrations\Monzo\MonzoPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Monzo\MonzoTransactionData;

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

        return $plugin->pullTransactionData($this->integration);
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
