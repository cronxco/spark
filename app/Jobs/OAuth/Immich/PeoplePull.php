<?php

namespace App\Jobs\OAuth\Immich;

use App\Integrations\Immich\ImmichPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Immich\PeopleData;
use Exception;

class PeoplePull extends BaseFetchJob
{
    /**
     * Get the service name for this job
     */
    protected function getServiceName(): string
    {
        return 'immich';
    }

    /**
     * Get the job type for logging
     */
    protected function getJobType(): string
    {
        return 'people';
    }

    /**
     * Fetch raw data from Immich API
     */
    protected function fetchData(): array
    {
        $plugin = new ImmichPlugin;

        // Get credentials from integration group
        $serverUrl = $this->integration->group->auth_metadata['server_url'] ?? null;
        $apiKey = $this->integration->group->auth_metadata['api_key'] ?? null;

        if (! $serverUrl || ! $apiKey) {
            throw new Exception('Immich server URL and API key are required');
        }

        // Fetch people from API
        return $plugin->pullPeopleData($this->integration, $serverUrl, $apiKey);
    }

    /**
     * Dispatch processing jobs with the fetched data
     */
    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData['people'])) {
            log_hierarchical($this->integration, 'info', 'No people to process');

            return;
        }

        // Dispatch PeopleData job to process people
        PeopleData::dispatch($this->integration, $rawData);
    }
}
