<?php

namespace App\Jobs\OAuth\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Oura\OuraHeartrateData;

class OuraHeartratePull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'heartrate';
    }

    protected function fetchData(): array
    {
        $plugin = new OuraPlugin;

        return $plugin->pullHeartrateData($this->integration);
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch heartrate processing job with all heartrate data
        OuraHeartrateData::dispatch($this->integration, $rawData);
    }
}
