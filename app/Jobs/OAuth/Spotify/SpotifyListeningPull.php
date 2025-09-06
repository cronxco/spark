<?php

namespace App\Jobs\OAuth\Spotify;

use App\Integrations\Spotify\SpotifyPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Spotify\SpotifyListeningData;
use Exception;
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
        $accountId = $this->integration->group?->account_id ?? $this->integration->account_id;

        Log::info("Fetching Spotify listening data for user {$accountId}", [
            'integration_id' => $this->integration->id,
        ]);

        $listeningData = [
            'account_id' => $accountId,
            'recently_played' => [],
            'fetched_at' => now()->toISOString(),
        ];

        // Skip fetching currently playing to avoid duplicates

        try {
            // Get recently played tracks (last 50)
            $recentlyPlayed = $this->getRecentlyPlayed($plugin);
            $listeningData['recently_played'] = $recentlyPlayed;

            Log::info('Spotify: Fetched recently played tracks', [
                'integration_id' => $this->integration->id,
                'track_count' => count($recentlyPlayed),
            ]);
        } catch (Exception $e) {
            Log::warning('Spotify: Failed to get recently played tracks', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);
            // Continue without recently played data
        }

        return $listeningData;
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

    // Removed currently playing fetch to disable feature

    private function getRecentlyPlayed(SpotifyPlugin $plugin): array
    {
        $endpoint = '/me/player/recently-played?limit=50';
        $response = $plugin->makeAuthenticatedApiRequest($endpoint, $this->integration);

        return $response['items'] ?? [];
    }
}
