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

        $config = $this->integration->configuration ?? [];
        Arr::set($config, 'reddit.after', null);
        $this->integration->update(['configuration' => $config]);

        if (empty($children)) {
            return;
        }

        RedditSavedData::dispatch($this->integration, [
            'children' => $children,
            'me' => $me,
        ]);

    }
}
