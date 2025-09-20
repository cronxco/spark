<?php

namespace App\Jobs\OAuth\Reddit;

use App\Integrations\Reddit\RedditPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Reddit\RedditSavedData;
use Illuminate\Support\Arr;

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

        return $plugin->pullSavedData($this->integration);
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        $saved = $rawData['saved'] ?? [];
        $me = $rawData['me'] ?? [];

        $children = $saved['data']['children'] ?? [];
        $after = $saved['data']['after'] ?? null;

        if (empty($children)) {
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
