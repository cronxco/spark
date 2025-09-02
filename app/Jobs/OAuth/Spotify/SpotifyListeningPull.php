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
            'currently_playing' => null,
            'recently_played' => [],
            'fetched_at' => now()->toISOString(),
        ];

        try {
            // Get currently playing track
            $currentlyPlaying = $this->getCurrentlyPlaying($plugin);
            if ($currentlyPlaying) {
                $listeningData['currently_playing'] = $currentlyPlaying;
                Log::info('Spotify: Found currently playing track', [
                    'integration_id' => $this->integration->id,
                    'track_name' => $currentlyPlaying['item']['name'] ?? 'Unknown',
                    'artist_name' => $currentlyPlaying['item']['artists'][0]['name'] ?? 'Unknown',
                ]);
            }
        } catch (Exception $e) {
            Log::warning('Spotify: Failed to get currently playing track', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);
            // Continue without currently playing data
        }

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
        if (empty($rawData['currently_playing']) && empty($rawData['recently_played'])) {
            Log::info('Spotify: No listening data to process', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        // Dispatch listening processing job
        SpotifyListeningData::dispatch($this->integration, $rawData);
    }

    private function getCurrentlyPlaying(SpotifyPlugin $plugin): ?array
    {
        $endpoint = '/me/player/currently-playing';

        try {
            $response = $plugin->makeAuthenticatedRequest($endpoint, $this->integration);

            if (empty($response) || ! isset($response['item'])) {
                return null; // No track currently playing
            }

            return $response;
        } catch (Exception $e) {
            // If it's a 204 (no content) or other expected error, return null
            if (str_contains($e->getMessage(), '204') || str_contains($e->getMessage(), 'No Content')) {
                return null;
            }
            throw $e;
        }
    }

    private function getRecentlyPlayed(SpotifyPlugin $plugin): array
    {
        $endpoint = '/me/player/recently-played?limit=50';
        $response = $plugin->makeAuthenticatedRequest($endpoint, $this->integration);

        return $response['items'] ?? [];
    }
}
