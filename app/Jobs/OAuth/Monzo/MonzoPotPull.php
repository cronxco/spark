<?php

namespace App\Jobs\OAuth\Monzo;

use App\Integrations\Monzo\MonzoPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Monzo\MonzoPotData;

class MonzoPotPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'monzo';
    }

    protected function getJobType(): string
    {
        return 'pots';
    }

    protected function fetchData(): array
    {
        $plugin = new MonzoPlugin;

        return $plugin->pullPotData($this->integration);
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch pot processing jobs for each account
        foreach ($rawData as $accountId => $pots) {
            if (! empty($pots)) {
                MonzoPotData::dispatch($this->integration, $pots, $accountId);
            }
        }
    }
}
