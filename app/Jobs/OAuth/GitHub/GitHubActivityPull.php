<?php

namespace App\Jobs\OAuth\GitHub;

use App\Integrations\GitHub\GitHubPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\GitHub\GitHubActivityData;

class GitHubActivityPull extends BaseFetchJob
{
    public function getIntegration()
    {
        return $this->integration;
    }

    protected function getServiceName(): string
    {
        return 'github';
    }

    protected function getJobType(): string
    {
        return 'activity';
    }

    protected function fetchData(): array
    {
        $plugin = new GitHubPlugin;
        $config = $this->integration->configuration ?? [];

        return $plugin->pullActivityData($this->integration, $config);
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData['repository_events'])) {
            return;
        }

        // Dispatch activity processing job
        GitHubActivityData::dispatch($this->integration, $rawData);
    }
}
