<?php

namespace App\Jobs\Fetch;

use App\Jobs\Base\BaseFetchJob;
use App\Models\EventObject;
use Illuminate\Support\Facades\Log;

class FetchScheduledUrls extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'fetch';
    }

    protected function getJobType(): string
    {
        return 'scheduled_urls';
    }

    protected function fetchData(): array
    {
        Log::info('Fetch: Starting scheduled URL fetch', [
            'integration_id' => $this->integration->id,
            'user_id' => $this->integration->user_id,
        ]);

        // Query all enabled fetch_webpage EventObjects for this integration
        // EventObjects don't have integration_id, filter by fetch_integration_id in metadata
        $webpages = EventObject::where('user_id', $this->integration->user_id)
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->whereNotNull('url')
            ->get();

        // Filter to only this integration's URLs and enabled URLs
        $enabledWebpages = $webpages->filter(function ($webpage) {
            $metadata = $webpage->metadata ?? [];

            return ($metadata['fetch_integration_id'] ?? null) === $this->integration->id
                && ($metadata['enabled'] ?? true) === true;
        });

        Log::info('Fetch: Found URLs to fetch', [
            'integration_id' => $this->integration->id,
            'total_urls' => $webpages->count(),
            'enabled_urls' => $enabledWebpages->count(),
        ]);

        // Return data for processing
        return [
            'webpages' => $enabledWebpages->map(function ($webpage) {
                return [
                    'id' => $webpage->id,
                    'url' => $webpage->url,
                    'title' => $webpage->title,
                    'metadata' => $webpage->metadata,
                ];
            })->toArray(),
            'total_count' => $enabledWebpages->count(),
        ];
    }

    protected function dispatchProcessingJobs($data): void
    {
        $webpages = $data['webpages'] ?? [];

        Log::info('Fetch: Dispatching URL fetch jobs', [
            'integration_id' => $this->integration->id,
            'job_count' => count($webpages),
        ]);

        // Dispatch URL discovery job if monitoring is configured
        $monitoredIntegrations = $this->integration->configuration['monitor_integrations'] ?? [];
        if (! empty($monitoredIntegrations)) {
            DiscoverUrlsFromIntegrations::dispatch($this->integration);

            Log::info('Fetch: Dispatched URL discovery job', [
                'integration_id' => $this->integration->id,
                'monitored_count' => count($monitoredIntegrations),
            ]);
        }

        // Dispatch FetchSingleUrl job for each webpage
        foreach ($webpages as $webpage) {
            FetchSingleUrl::dispatch(
                $this->integration,
                $webpage['id'],
                $webpage['url']
            );
        }
    }
}
