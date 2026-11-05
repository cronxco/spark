<?php

namespace App\Jobs\OAuth\Monzo;

use App\Integrations\Monzo\MonzoPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Monzo\MonzoAccountData;

class MonzoAccountPull extends BaseFetchJob
{
    public function getIntegration()
    {
        return $this->integration;
    }

    protected function getServiceName(): string
    {
        return 'monzo';
    }

    protected function getJobType(): string
    {
        return 'accounts';
    }

    protected function fetchData(): array
    {
        $plugin = new MonzoPlugin;

        return $plugin->pullAccountData($this->integration);
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch account processing jobs for each account
        foreach ($rawData as $account) {
            MonzoAccountData::dispatch($this->integration, $account);
        }
    }
}
