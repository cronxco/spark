<?php

namespace App\Jobs\OAuth\Immich;

use App\Integrations\Immich\ImmichPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Immich\PhotosData;
use Exception;

class PhotosPull extends BaseFetchJob
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
        return 'photos';
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

        // Determine sync mode and date filter
        $syncMode = $this->integration->configuration['sync_mode'] ?? 'recent';
        $afterDate = null;

        if ($syncMode === 'recent') {
            // Sync last 30 days
            $afterDate = now()->subDays(30)->toIso8601String();
        }

        // Fetch photos from API
        return $plugin->pullPhotoData($this->integration, $serverUrl, $apiKey, $afterDate);
    }

    /**
     * Dispatch processing jobs with the fetched data
     */
    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData['assets'])) {
            log_hierarchical($this->integration, 'info', 'No photos to process');

            return;
        }

        // Dispatch PhotosData job to process and cluster photos
        PhotosData::dispatch($this->integration, $rawData);
    }
}
