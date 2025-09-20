<?php

namespace App\Jobs\OAuth\Monzo;

use App\Integrations\Monzo\MonzoPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Monzo\MonzoBalanceData;

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

        return $plugin->pullBalanceData($this->integration);
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
