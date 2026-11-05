<?php

namespace App\Jobs\OAuth\GoCardless;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\GoCardless\GoCardlessBalanceData;

class GoCardlessBalancePull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'gocardless';
    }

    protected function getJobType(): string
    {
        return 'balances';
    }

    protected function fetchData(): array
    {
        $plugin = new GoCardlessBankPlugin;

        return $plugin->pullBalanceData($this->integration);
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch balance processing job
        GoCardlessBalanceData::dispatch($this->integration, $rawData);
    }
}
