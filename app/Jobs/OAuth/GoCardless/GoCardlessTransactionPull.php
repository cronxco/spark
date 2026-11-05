<?php

namespace App\Jobs\OAuth\GoCardless;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\GoCardless\GoCardlessTransactionData;

class GoCardlessTransactionPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'gocardless';
    }

    protected function getJobType(): string
    {
        return 'transactions';
    }

    protected function fetchData(): array
    {
        $plugin = new GoCardlessBankPlugin;

        return $plugin->pullTransactionData($this->integration);
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch transaction processing job
        GoCardlessTransactionData::dispatch($this->integration, $rawData);
    }
}
