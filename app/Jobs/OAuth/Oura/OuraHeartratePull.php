<?php

namespace App\Jobs\OAuth\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Oura\OuraHeartrateData;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;

class OuraHeartratePull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'heartrate';
    }

    protected function fetchData(): array
    {
        $plugin = new OuraPlugin;
        $config = $this->integration->configuration ?? [];
        $incrementalDays = max(2, (int) ($config['oura_incremental_days'] ?? 3));
        $startDatetime = now()->subDays($incrementalDays)->toIso8601String();
        $endDatetime = now()->toIso8601String();
        $lastSweepAt = isset($config['oura_last_sweep_at']) ? Carbon::parse($config['oura_last_sweep_at']) : null;
        $doSweep = ! $lastSweepAt || $lastSweepAt->lt(now()->subHours(22));

        // Log the API request
        $plugin->logApiRequest('GET', '/usercollection/heartrate', [
            'Authorization' => '[REDACTED]',
        ], [
            'start_datetime' => $startDatetime,
            'end_datetime' => $endDatetime,
        ], $this->integration->id);

        $response = Http::withHeaders($plugin->authHeaders($this->integration))
            ->get($plugin->getBaseUrl() . '/usercollection/heartrate', [
                'start_datetime' => $startDatetime,
                'end_datetime' => $endDatetime,
            ]);

        // Log the API response
        $plugin->logApiResponse('GET', '/usercollection/heartrate', $response->status(), $response->body(), $response->headers(), $this->integration->id);

        if (! $response->successful()) {
            throw new Exception('Failed to fetch heartrate data from Oura API: ' . $response->body());
        }
        $items = $response->json('data') ?? [];

        if ($doSweep) {
            $sweepResp = Http::withHeaders($plugin->authHeaders($this->integration))
                ->get($plugin->getBaseUrl() . '/usercollection/heartrate', [
                    'start_datetime' => now()->subDays(7)->toIso8601String(),
                    'end_datetime' => $endDatetime,
                ]);
            if ($sweepResp->successful()) {
                $sweep = $sweepResp->json('data') ?? [];
                $byKey = [];
                foreach (array_merge($items, $sweep) as $row) {
                    $key = ($row['timestamp'] ?? '') . '|' . ($row['source'] ?? '');
                    if ($key !== '|') {
                        $byKey[$key] = $row;
                    }
                }
                $items = array_values($byKey);
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

        // Dispatch heartrate processing job with all heartrate data
        OuraHeartrateData::dispatch($this->integration, $rawData);
    }
}
