<?php

namespace App\Jobs\OAuth\GoCardless;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\GoCardless\GoCardlessAccountData;

class GoCardlessAccountPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'gocardless';
    }

    protected function getJobType(): string
    {
        return 'accounts';
    }

    protected function fetchData(): array
    {
        $plugin = new GoCardlessBankPlugin;

        return $plugin->pullAccountData($this->integration);
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch account processing job
        GoCardlessAccountData::dispatch($this->integration, $rawData);
    }
}
