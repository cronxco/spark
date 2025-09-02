<?php

namespace App\Jobs\OAuth\Hevy;

use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Hevy\HevyWorkoutData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class HevyWorkoutPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'hevy';
    }

    protected function getJobType(): string
    {
        return 'workout';
    }

    protected function fetchData(): array
    {
        $daysBack = (int) ($this->integration->configuration['days_back'] ?? 14);
        $startDate = now()->subDays($daysBack)->toDateString();
        $endDate = now()->toDateString();

        $query = http_build_query([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'limit' => 100,
        ]);

        $endpoint = '/v1/workouts?' . $query;

        Log::info('Hevy: Fetching workouts', [
            'integration_id' => $this->integration->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'endpoint' => $endpoint,
        ]);

        try {
            $json = $this->getJson($endpoint);
            Log::info('Hevy: Fetched workout data', [
                'integration_id' => $this->integration->id,
                'data_count' => count($json['data'] ?? []),
            ]);

            return $json;
        } catch (Throwable $e) {
            Log::error('Hevy: Workout fetch failed', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData['data'] ?? [])) {
            Log::info('Hevy: No workout data to process', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        Log::info('Hevy: Dispatching workout processing job', [
            'integration_id' => $this->integration->id,
            'workout_count' => count($rawData['data']),
        ]);

        HevyWorkoutData::dispatch($this->integration, $rawData);
    }

    /**
     * Simple HTTP helper using API key authentication.
     */
    private function getJson(string $endpoint): array
    {
        $apiKey = (string) ($this->integration->configuration['api_key'] ?? config('services.hevy.api_key') ?? '');
        $url = 'https://api.hevyapp.com' . $endpoint;

        $response = Http::withHeaders([
            'api-key' => $apiKey,
        ])->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('Hevy API request failed with status ' . $response->status());
        }

        return $response->json() ?? [];
    }
}
