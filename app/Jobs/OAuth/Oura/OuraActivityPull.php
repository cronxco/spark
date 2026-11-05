<?php

namespace App\Jobs\OAuth\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Oura\OuraActivityData;

class OuraActivityPull extends BaseFetchJob
{
    public function getIntegration()
    {
        return $this->integration;
    }

    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'activity';
    }

    protected function fetchData(): array
    {
        $plugin = new OuraPlugin;

        return $plugin->pullActivityData($this->integration);
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch activity processing job with all activity data
        OuraActivityData::dispatch($this->integration, $rawData);
    }
}
