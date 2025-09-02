<?php

namespace App\Jobs\OAuth\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Oura\OuraSessionsData;
use Exception;
use Illuminate\Support\Facades\Http;

class OuraSessionsPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'sessions';
    }

    protected function fetchData(): array
    {
        $plugin = new OuraPlugin;
        $daysBack = (int) ($this->integration->configuration['days_back'] ?? 7);
        $startDate = now()->subDays($daysBack)->toDateString();
        $endDate = now()->toDateString();

        // Log the API request
        $plugin->logApiRequest('GET', '/usercollection/session', [
            'Authorization' => '[REDACTED]',
        ], [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ], $this->integration->id);

        $response = Http::withHeaders($plugin->authHeaders($this->integration))
            ->get($plugin->baseUrl . '/usercollection/session', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

        // Log the API response
        $plugin->logApiResponse('GET', '/usercollection/session', $response->status(), $response->body(), $response->headers(), $this->integration->id);

        if (! $response->successful()) {
            throw new Exception('Failed to fetch sessions from Oura API: ' . $response->body());
        }

        return $response->json('data') ?? [];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch sessions processing job with all session data
        OuraSessionsData::dispatch($this->integration, $rawData);
    }
}
