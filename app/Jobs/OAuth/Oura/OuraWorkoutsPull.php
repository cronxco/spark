<?php

namespace App\Jobs\OAuth\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Oura\OuraWorkoutsData;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;

class OuraWorkoutsPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'workouts';
    }

    protected function fetchData(): array
    {
        $plugin = new OuraPlugin;
        $config = $this->integration->configuration ?? [];
        $incrementalDays = max(2, (int) ($config['oura_incremental_days'] ?? 3));
        $startDate = now()->subDays($incrementalDays)->toDateString();
        $endDate = now()->toDateString();
        $lastSweepAt = isset($config['oura_last_sweep_at']) ? Carbon::parse($config['oura_last_sweep_at']) : null;
        $doSweep = ! $lastSweepAt || $lastSweepAt->lt(now()->subHours(22));

        // Log the API request
        $plugin->logApiRequest('GET', '/usercollection/workout', [
            'Authorization' => '[REDACTED]',
        ], [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ], $this->integration->id);

        $response = Http::withHeaders($plugin->authHeaders($this->integration))
            ->get($plugin->getBaseUrl() . '/usercollection/workout', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

        // Log the API response
        $plugin->logApiResponse('GET', '/usercollection/workout', $response->status(), $response->body(), $response->headers(), $this->integration->id);

        if (! $response->successful()) {
            throw new Exception('Failed to fetch workouts from Oura API: ' . $response->body());
        }
        $items = $response->json('data') ?? [];
        if ($doSweep) {
            $sweepResp = Http::withHeaders($plugin->authHeaders($this->integration))
                ->get($plugin->getBaseUrl() . '/usercollection/workout', [
                    'start_date' => now()->subDays(30)->toDateString(),
                    'end_date' => $endDate,
                ]);
            if ($sweepResp->successful()) {
                $sweep = $sweepResp->json('data') ?? [];
                $byDay = [];
                foreach (array_merge($items, $sweep) as $row) {
                    $k = $row['day'] ?? ($row['date'] ?? null);
                    if ($k) {
                        $byDay[$k] = $row;
                    }
                }
                $items = array_values($byDay);
                $config['oura_last_sweep_at'] = now()->toIso8601String();
                $this->integration->update(['configuration' => $config]);
            }
        }

        return $items;
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch workouts processing job with all workout data
        OuraWorkoutsData::dispatch($this->integration, $rawData);
    }
}
