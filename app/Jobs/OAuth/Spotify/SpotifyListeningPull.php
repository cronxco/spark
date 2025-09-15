<?php

namespace App\Jobs\OAuth\Spotify;

use App\Integrations\Spotify\SpotifyPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Spotify\SpotifyListeningData;
use Carbon\Carbon;
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
            $config = $this->integration->configuration ?? [];
            $afterMs = (int) ($config['spotify_after_ms'] ?? 0);

            // Incremental pull using 'after' cursor if available
            $recentlyPlayed = $this->getRecentlyPlayed($plugin, $afterMs > 0 ? $afterMs : null);

            // Daily sweep over last ~36h to backfill gaps
            $lastSweepAt = isset($config['spotify_last_sweep_at']) ? Carbon::parse($config['spotify_last_sweep_at']) : null;
            $needsSweep = ! $lastSweepAt || $lastSweepAt->lt(now()->subHours(22));
            if ($needsSweep) {
                $sweepSinceMs = (int) round(now()->subHours(36)->valueOf());
                $sweepItems = $this->getRecentlyPlayedSweep($plugin, $sweepSinceMs, 3);
                if (! empty($sweepItems)) {
                    // Merge and de-dup by track_id + played_at
                    $keyed = [];
                    foreach (array_merge($recentlyPlayed, $sweepItems) as $item) {
                        $tid = $item['track']['id'] ?? null;
                        $played = $item['played_at'] ?? null;
                        if ($tid && $played) {
                            $keyed[$tid . '|' . $played] = $item;
                        }
                    }
                    $recentlyPlayed = array_values($keyed);
                    $config['spotify_last_sweep_at'] = now()->toIso8601String();
                }
            }

            // Advance 'after' cursor to the newest played_at we saw
            $maxPlayedMs = 0;
            foreach ($recentlyPlayed as $it) {
                if (isset($it['played_at'])) {
                    $ms = (int) round(Carbon::parse($it['played_at'])->valueOf());
                    if ($ms > $maxPlayedMs) {
                        $maxPlayedMs = $ms;
                    }
                }
            }
            if ($maxPlayedMs > $afterMs) {
                $config['spotify_after_ms'] = $maxPlayedMs;
            }

            $this->integration->update(['configuration' => $config]);

            $listeningData['recently_played'] = $recentlyPlayed;

            Log::info('Spotify: Fetched recently played tracks', [
                'integration_id' => $this->integration->id,
                'track_count' => count($recentlyPlayed),
                'used_after_ms' => $afterMs,
                'new_after_ms' => $config['spotify_after_ms'] ?? null,
                'sweep' => $needsSweep,
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

    private function getRecentlyPlayed(SpotifyPlugin $plugin, ?int $afterMs = null): array
    {
        $qs = ['limit' => 50];
        if ($afterMs !== null) {
            $qs['after'] = $afterMs;
        }
        $endpoint = '/me/player/recently-played?' . http_build_query($qs);
        $response = $plugin->makeAuthenticatedApiRequest($endpoint, $this->integration);

        return $response['items'] ?? [];
    }

    private function getRecentlyPlayedSweep(SpotifyPlugin $plugin, int $sinceMs, int $maxPages = 3): array
    {
        $items = [];
        $beforeMs = null; // Start from most recent
        for ($page = 0; $page < $maxPages; $page++) {
            $params = ['limit' => 50];
            if ($beforeMs !== null) {
                $params['before'] = $beforeMs;
            }
            $endpoint = '/me/player/recently-played?' . http_build_query($params);
            $resp = $plugin->makeAuthenticatedApiRequest($endpoint, $this->integration);
            $batch = $resp['items'] ?? [];
            if (empty($batch)) {
                break;
            }
            $items = array_merge($items, $batch);
            // Prepare next page using oldest played_at as new before
            $oldest = end($batch);
            if (! $oldest || ! isset($oldest['played_at'])) {
                break;
            }
            $oldestMs = (int) round(Carbon::parse($oldest['played_at'])->valueOf());
            if ($oldestMs < $sinceMs) {
                break; // window reached
            }
            $beforeMs = $oldestMs;
        }

        return $items;
    }
}
