<?php

namespace App\Jobs\OAuth\Spotify;

use App\Integrations\Spotify\SpotifyPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Spotify\SpotifyListeningData;
use Illuminate\Support\Facades\Log;

class SpotifyListeningPull extends BaseFetchJob
{
    public function getIntegration()
    {
        return $this->integration;
    }

    protected function getServiceName(): string
    {
        return 'spotify';
    }

    protected function getJobType(): string
    {
        return 'listening';
    }

    protected function fetchData(): array
    {
        $plugin = new SpotifyPlugin;

        return $plugin->pullListeningData($this->integration);
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData['recently_played'])) {
            Log::info('Spotify: No listening data to process', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        // Dispatch listening processing job
        SpotifyListeningData::dispatch($this->integration, $rawData);
    }
}
