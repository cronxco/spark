<?php

namespace App\Jobs\OAuth\Vivino;

use App\Integrations\Fetch\PlaywrightFetchClient;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Vivino\VivinoActivityData;
use Exception;

/**
 * Fetches Will's own Vivino profile activity page via an authenticated
 * Playwright browser session, the same mechanism
 * UntappdCheckinDetailPull uses to fetch logged-in-only Untappd pages -
 * replaying cookies stored on the IntegrationGroup (added once via the
 * existing "Manage Fetch Cookies" Spotlight command) rather than storing
 * any password. Vivino is a modern client-rendered page, so this calls
 * PlaywrightFetchClient directly rather than going through
 * FetchEngineManager's HTTP-first default.
 */
class VivinoActivityPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'vivino';
    }

    protected function getJobType(): string
    {
        return 'activity';
    }

    protected function fetchData(): array
    {
        $group = $this->integration->group;
        $profileUrl = $group->auth_metadata['vivino_profile_url'] ?? null;

        if (! $profileUrl) {
            throw new Exception('Vivino profile URL not configured in integration group settings');
        }

        $client = app(PlaywrightFetchClient::class);
        $result = $client->fetch($profileUrl, $group);

        if (! $result['success']) {
            throw new Exception('Vivino fetch failed: ' . ($result['error'] ?? 'Unknown error'));
        }

        return [
            'html' => $result['html'],
        ];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData['html'])) {
            return;
        }

        VivinoActivityData::dispatch($this->integration, $rawData);
    }
}
