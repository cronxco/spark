<?php

namespace App\Jobs\OAuth\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Oura\OuraSpo2Data;
use Exception;
use Illuminate\Support\Facades\Http;

class OuraSpo2Pull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'spo2';
    }

    protected function fetchData(): array
    {
        $plugin = new OuraPlugin;
        $daysBack = (int) ($this->integration->configuration['days_back'] ?? 7);
        $startDate = now()->subDays($daysBack)->toDateString();
        $endDate = now()->toDateString();

        // Log the API request
        $plugin->logApiRequest('GET', '/usercollection/daily_spo2', [
            'Authorization' => '[REDACTED]',
        ], [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ], $this->integration->id);

        $response = Http::withHeaders($plugin->authHeaders($this->integration))
            ->get($plugin->baseUrl . '/usercollection/daily_spo2', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

        // Log the API response
        $plugin->logApiResponse('GET', '/usercollection/daily_spo2', $response->status(), $response->body(), $response->headers(), $this->integration->id);

        if (! $response->successful()) {
            throw new Exception('Failed to fetch daily SpO2 from Oura API: ' . $response->body());
        }

        return $response->json('data') ?? [];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch SpO2 processing job with all SpO2 data
        OuraSpo2Data::dispatch($this->integration, $rawData);
    }
}
