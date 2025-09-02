<?php

namespace App\Jobs\OAuth\GitHub;

use App\Integrations\GitHub\GitHubPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\GitHub\GitHubActivityData;
use Exception;
use Illuminate\Support\Facades\Log;

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

        // Normalize repositories to array of owner/repo strings
        $repositories = $this->normalizeRepositories($config['repositories'] ?? []);

        // Normalize events to array of strings
        $events = $this->normalizeEvents($config['events'] ?? ['push', 'pull_request']);

        $allRepositoryEvents = [];

        foreach ($repositories as $repo) {
            try {
                Log::info('GitHub Activity Pull: Fetching events for repository', [
                    'integration_id' => $this->integration->id,
                    'repository' => $repo,
                ]);

                $repositoryEvents = $this->fetchRepositoryEvents($repo, $events, $plugin);
                $allRepositoryEvents[$repo] = $repositoryEvents;

            } catch (Exception $e) {
                Log::error('GitHub Activity Pull: Failed to fetch events for repository', [
                    'integration_id' => $this->integration->id,
                    'repository' => $repo,
                    'error' => $e->getMessage(),
                ]);

                // Continue with other repositories even if one fails
                $allRepositoryEvents[$repo] = [];
            }
        }

        return [
            'repositories' => $repositories,
            'events' => $events,
            'repository_events' => $allRepositoryEvents,
            'fetched_at' => now()->toISOString(),
        ];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData['repository_events'])) {
            return;
        }

        // Dispatch activity processing job
        GitHubActivityData::dispatch($this->integration, $rawData);
    }

    private function normalizeRepositories($repositoriesRaw): array
    {
        if (is_string($repositoriesRaw)) {
            // Try JSON array first
            $decoded = json_decode($repositoriesRaw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $repositories = array_values(array_filter(array_map('trim', $decoded)));
            } else {
                // Support comma or newline separated strings
                $parts = preg_split('/[,\n]/', $repositoriesRaw) ?: [];
                $repositories = array_values(array_filter(array_map('trim', $parts)));
            }
        } elseif (is_array($repositoriesRaw)) {
            $repositories = array_values(array_filter(array_map('trim', $repositoriesRaw)));
        } else {
            $repositories = [];
        }

        // Final guards against strings
        if (! is_array($repositories)) {
            $repositories = $repositories ? [trim((string) $repositories)] : [];
        }

        return $repositories;
    }

    private function normalizeEvents($eventsRaw): array
    {
        if (is_string($eventsRaw)) {
            $decoded = json_decode($eventsRaw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $events = array_values(array_filter(array_map('trim', $decoded)));
            } else {
                $parts = preg_split('/[,\n\s]+/', $eventsRaw) ?: [];
                $events = array_values(array_filter(array_map('trim', $parts)));
            }
        } elseif (is_array($eventsRaw)) {
            $events = array_values(array_filter(array_map('trim', $eventsRaw)));
        } else {
            $events = ['push', 'pull_request'];
        }

        // Final guards against strings
        if (! is_array($events)) {
            $events = $events ? [trim((string) $events)] : ['push', 'pull_request'];
        }

        return $events;
    }

    private function fetchRepositoryEvents(string $repo, array $events, GitHubPlugin $plugin): array
    {
        $repo = trim($repo);
        if ($repo === '') {
            return [];
        }

        $endpoint = "/repos/{$repo}/events";
        $eventsData = $plugin->makeAuthenticatedRequest($endpoint, $this->integration);

        if (! is_array($eventsData)) {
            return [];
        }

        // Filter by configured event types (map config labels -> GitHub event types)
        $configured = array_map('trim', $events);
        $labelToType = [
            'push' => 'PushEvent',
            'pull_request' => 'PullRequestEvent',
            'issue' => 'IssuesEvent',
            'commit_comment' => 'CommitCommentEvent',
        ];
        $allowedTypes = [];
        foreach ($configured as $label) {
            if ($label === '') {
                continue;
            }
            $allowedTypes[] = $labelToType[$label] ?? $label; // accept direct API type names too
        }
        $allowedTypes = array_values(array_unique($allowedTypes));

        $filteredEvents = [];
        foreach ($eventsData as $eventData) {
            if (! is_array($eventData)) {
                continue;
            }
            if (! isset($eventData['type'])) {
                continue;
            }
            if (! empty($allowedTypes) && ! in_array($eventData['type'], $allowedTypes, true)) {
                continue;
            }
            $filteredEvents[] = $eventData;
        }

        return $filteredEvents;
    }
}
