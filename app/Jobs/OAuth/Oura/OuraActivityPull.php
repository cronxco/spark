<?php

namespace App\Jobs\OAuth\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Oura\OuraActivityData;
use Illuminate\Support\Facades\Http;
use ReflectionClass;

class OuraActivityPull extends BaseFetchJob
{
    public function getIntegration()
    {
        return $this->integration;
    }

    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'activity';
    }

    protected function fetchData(): array
    {
        $plugin = new OuraPlugin;
        $daysBack = (int) ($this->integration->configuration['days_back'] ?? 7);
        $startDate = now()->subDays($daysBack)->toDateString();
        $endDate = now()->toDateString();

        // Use reflection to access the protected getJson method
        $reflection = new ReflectionClass($plugin);
        $getJsonMethod = $reflection->getMethod('getJson');
        $getJsonMethod->setAccessible(true);
        $data = $getJsonMethod->invoke($plugin, '/usercollection/daily_activity', $this->integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        return $data['data'] ?? [];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch activity processing job with all activity data
        OuraActivityData::dispatch($this->integration, $rawData);
    }

    private function makeAuthenticatedRequest(OuraPlugin $plugin, string $endpoint, array $queryParams = []): \Illuminate\Http\Client\Response
    {
        // Log the API request (using reflection to access protected method for testing compatibility)
        $reflection = new ReflectionClass($plugin);
        $logRequestMethod = $reflection->getMethod('logApiRequest');
        $logRequestMethod->setAccessible(true);
        $logRequestMethod->invoke($plugin, 'GET', $endpoint, [
            'Authorization' => '[REDACTED]',
        ], $queryParams, $this->integration->id);

        $response = Http::withHeaders($plugin->authHeaders($this->integration))
            ->get($plugin->getBaseUrl() . $endpoint, $queryParams);

        // Log the API response (using reflection to access protected method for testing compatibility)
        $logResponseMethod = $reflection->getMethod('logApiResponse');
        $logResponseMethod->setAccessible(true);
        $logResponseMethod->invoke($plugin, 'GET', $endpoint, $response->status(), $response->body(), $response->headers(), $this->integration->id);

        return $response;
    }
}
