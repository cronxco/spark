<?php

namespace App\Jobs\OAuth\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Oura\OuraSleepData;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;

class OuraSleepPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'sleep';
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
        $plugin->logApiRequest('GET', '/usercollection/daily_sleep', [
            'Authorization' => '[REDACTED]',
        ], [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ], $this->integration->id);

        $response = Http::withHeaders($plugin->authHeaders($this->integration))
            ->get($plugin->getBaseUrl() . '/usercollection/daily_sleep', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

        // Log the API response
        $plugin->logApiResponse('GET', '/usercollection/daily_sleep', $response->status(), $response->body(), $response->headers(), $this->integration->id);

        if (! $response->successful()) {
            throw new Exception('Failed to fetch daily sleep from Oura API: ' . $response->body());
        }
        $items = $response->json('data') ?? [];

        if ($doSweep) {
            $sweepResp = Http::withHeaders($plugin->authHeaders($this->integration))
                ->get($plugin->getBaseUrl() . '/usercollection/daily_sleep', [
                    'start_date' => now()->subDays(30)->toDateString(),
                    'end_date' => $endDate,
                ]);
            if ($sweepResp->successful()) {
                $sweep = $sweepResp->json('data') ?? [];
                // Merge unique by day
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

        // Dispatch sleep processing job with all sleep data
        OuraSleepData::dispatch($this->integration, $rawData);
    }
}
