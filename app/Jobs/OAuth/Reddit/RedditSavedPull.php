<?php

namespace App\Jobs\OAuth\Reddit;

use App\Integrations\Reddit\RedditPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Reddit\RedditSavedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class RedditSavedPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'reddit';
    }

    protected function getJobType(): string
    {
        return 'saved';
    }

    protected function fetchData(): array
    {
        $plugin = new RedditPlugin;

        $username = $this->integration->group?->account_id ?? $this->integration->account_id;

        $after = Arr::get($this->integration->configuration ?? [], 'reddit.after');
        $limit = 100;

        $endpoint = "/user/{$username}/saved?limit={$limit}&raw_json=1";
        if (! empty($after)) {
            $endpoint .= "&after={$after}";
        }

        Log::info('Reddit: fetching saved items', [
            'integration_id' => $this->integration->id,
            'username' => $username,
            'after' => $after,
        ]);

        $saved = $plugin->makeAuthenticatedApiRequest($endpoint, $this->integration);

        $me = $plugin->makeAuthenticatedApiRequest('/api/v1/me', $this->integration);

        return [
            'saved' => $saved,
            'me' => $me,
        ];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        $saved = $rawData['saved'] ?? [];
        $me = $rawData['me'] ?? [];

        $children = $saved['data']['children'] ?? [];
        $after = $saved['data']['after'] ?? null;

        if (empty($children)) {
            Log::info('Reddit: no saved items to process', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        RedditSavedData::dispatch($this->integration, [
            'children' => $children,
            'me' => $me,
        ]);

        // Persist pagination cursor for next run
        $config = $this->integration->configuration ?? [];
        Arr::set($config, 'reddit.after', $after);
        $this->integration->update(['configuration' => $config]);
    }
}
