<?php

namespace App\Jobs\OAuth\Monzo;

use App\Integrations\Monzo\MonzoPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Monzo\MonzoAccountData;
use Illuminate\Support\Facades\Http;
use ReflectionClass;

class MonzoAccountPull extends BaseFetchJob
{
    public function getIntegration()
    {
        return $this->integration;
    }

    protected function getServiceName(): string
    {
        return 'monzo';
    }

    protected function getJobType(): string
    {
        return 'accounts';
    }

    protected function fetchData(): array
    {
        $plugin = new MonzoPlugin;

        // Use reflection to access the protected listAccounts method
        $reflection = new ReflectionClass($plugin);
        $listAccountsMethod = $reflection->getMethod('listAccounts');
        $listAccountsMethod->setAccessible(true);

        return $listAccountsMethod->invoke($plugin, $this->integration);
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch account processing jobs for each account
        foreach ($rawData as $account) {
            MonzoAccountData::dispatch($this->integration, $account);
        }
    }

    private function makeAuthenticatedRequest(MonzoPlugin $plugin, string $endpoint): \Illuminate\Http\Client\Response
    {
        // Log the API request (using reflection to access protected method for testing compatibility)
        $reflection = new ReflectionClass($plugin);
        $logRequestMethod = $reflection->getMethod('logApiRequest');
        $logRequestMethod->setAccessible(true);
        $logRequestMethod->invoke($plugin, 'GET', $endpoint, [
            'Authorization' => '[REDACTED]',
        ], [], $this->integration->id);

        $response = Http::withHeaders($plugin->getAuthHeaders($this->integration))
            ->get($plugin->apiBase . $endpoint);

        // Log the API response (using reflection to access protected method for testing compatibility)
        $logResponseMethod = $reflection->getMethod('logApiResponse');
        $logResponseMethod->setAccessible(true);
        $logResponseMethod->invoke($plugin, 'GET', $endpoint, $response->status(), $response->body(), $response->headers(), $this->integration->id);

        return $response;
    }
}
