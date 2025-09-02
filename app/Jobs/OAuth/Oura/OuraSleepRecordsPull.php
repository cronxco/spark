<?php

namespace App\Jobs\OAuth\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Oura\OuraSleepRecordsData;
use Exception;
use Illuminate\Support\Facades\Http;

class OuraSleepRecordsPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'sleep_records';
    }

    protected function fetchData(): array
    {
        $plugin = new OuraPlugin;
        $daysBack = (int) ($this->integration->configuration['days_back'] ?? 7);
        $startDate = now()->subDays($daysBack)->toDateString();
        $endDate = now()->toDateString();

        // Log the API request
        $plugin->logApiRequest('GET', '/usercollection/sleep', [
            'Authorization' => '[REDACTED]',
        ], [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ], $this->integration->id);

        $response = Http::withHeaders($plugin->authHeaders($this->integration))
            ->get($plugin->baseUrl . '/usercollection/sleep', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

        // Log the API response
        $plugin->logApiResponse('GET', '/usercollection/sleep', $response->status(), $response->body(), $response->headers(), $this->integration->id);

        if (! $response->successful()) {
            throw new Exception('Failed to fetch sleep records from Oura API: ' . $response->body());
        }

        return $response->json('data') ?? [];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch sleep records processing job with all sleep record data
        OuraSleepRecordsData::dispatch($this->integration, $rawData);
    }
}
