<?php

namespace App\Jobs\OAuth\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Oura\OuraHeartrateData;
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
        $daysBack = (int) ($this->integration->configuration['days_back'] ?? 7);
        $startDatetime = now()->subDays($daysBack)->toIso8601String();
        $endDatetime = now()->toIso8601String();

        // Log the API request
        $plugin->logApiRequest('GET', '/usercollection/heartrate', [
            'Authorization' => '[REDACTED]',
        ], [
            'start_datetime' => $startDatetime,
            'end_datetime' => $endDatetime,
        ], $this->integration->id);

        $response = Http::withHeaders($plugin->authHeaders($this->integration))
            ->get($plugin->baseUrl . '/usercollection/heartrate', [
                'start_datetime' => $startDatetime,
                'end_datetime' => $endDatetime,
            ]);

        // Log the API response
        $plugin->logApiResponse('GET', '/usercollection/heartrate', $response->status(), $response->body(), $response->headers(), $this->integration->id);

        if (! $response->successful()) {
            throw new Exception('Failed to fetch heartrate data from Oura API: ' . $response->body());
        }

        return $response->json('data') ?? [];
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
