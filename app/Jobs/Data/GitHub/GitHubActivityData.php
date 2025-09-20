<?php

namespace App\Jobs\Data\GitHub;

use App\Integrations\GitHub\GitHubPlugin;
use App\Jobs\Base\BaseProcessingJob;
use Exception;
use Illuminate\Support\Facades\Log;

class GitHubActivityData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'github';
    }

    protected function getJobType(): string
    {
        return 'activity';
    }

    protected function process(): void
    {
        $repositoryEvents = $this->rawData['repository_events'] ?? [];
        $plugin = new GitHubPlugin;

        if (empty($repositoryEvents)) {
            Log::info('GitHub Activity Data: No repository events to process', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        foreach ($repositoryEvents as $repo => $events) {
            Log::info('GitHub Activity Data: Processing repository events', [
                'integration_id' => $this->integration->id,
                'repository' => $repo,
                'event_count' => count($events),
            ]);

            foreach ($events as $eventData) {
                try {
                    $plugin->processEventPayload($this->integration, $eventData);
                } catch (Exception $e) {
                    Log::error('GitHub Activity Data: Failed to process event', [
                        'integration_id' => $this->integration->id,
                        'repository' => $repo,
                        'event_type' => $eventData['type'] ?? 'unknown',
                        'event_id' => $eventData['id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('GitHub Activity Data: Completed processing events', [
            'integration_id' => $this->integration->id,
        ]);
    }
}
