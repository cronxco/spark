<?php

namespace App\Jobs\OAuth\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Oura\OuraTagsData;

class OuraTagsPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'tags';
    }

    protected function fetchData(): array
    {
        $plugin = new OuraPlugin;

        return $plugin->pullTagsData($this->integration);
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch tags processing job with all tag data
        OuraTagsData::dispatch($this->integration, $rawData);
    }
}
