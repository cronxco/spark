<?php

namespace App\Jobs\OAuth\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Oura\OuraResilienceData;

class OuraResiliencePull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'resilience';
    }

    protected function fetchData(): array
    {
        $plugin = new OuraPlugin;

        return $plugin->pullResilienceData($this->integration);
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch resilience processing job with all resilience data
        OuraResilienceData::dispatch($this->integration, $rawData);
    }
}
