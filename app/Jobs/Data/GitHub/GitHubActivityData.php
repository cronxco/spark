<?php

namespace App\Jobs\Data\GitHub;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
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
                    $this->processGitHubEvent($eventData);
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
    }

    private function processGitHubEvent(array $eventData): void
    {
        $eventType = $eventData['type'];

        $convertedData = match ($eventType) {
            'PushEvent' => $this->convertPushEvent($eventData),
            'PullRequestEvent' => $this->convertPullRequestEvent($eventData),
            'IssuesEvent' => $this->convertIssueEvent($eventData),
            'CommitCommentEvent' => $this->convertCommitCommentEvent($eventData),
            default => null,
        };

        if (! $convertedData || empty($convertedData['events'])) {
            return;
        }

        $this->createEventFromData($convertedData);
    }

    private function convertPushEvent(array $data): array
    {
        // Ensure we have a stable unique source ID
        if (empty($data['id'])) {
            return ['events' => []];
        }

        $actor = [
            'concept' => 'user',
            'type' => 'github_user',
            'title' => $data['actor']['login'],
            'content' => $data['actor']['login'],
            'metadata' => [
                'github_id' => $data['actor']['id'],
                'avatar_url' => $data['actor']['avatar_url'],
            ],
            'url' => $data['actor']['html_url']
                ?? $data['actor']['url']
                ?? (isset($data['actor']['login']) ? 'https://github.com/' . $data['actor']['login'] : null),
            'image_url' => $data['actor']['avatar_url'],
        ];

        $target = [
            'concept' => 'repository',
            'type' => 'github_repo',
            'title' => $data['repo']['name'],
            'content' => $data['repo']['description'] ?? '',
            'metadata' => [
                'github_id' => $data['repo']['id'],
                'full_name' => $data['repo']['full_name'] ?? $data['repo']['name'] ?? null,
            ],
            'url' => $data['repo']['html_url']
                ?? (isset($data['repo']['name']) ? 'https://github.com/' . $data['repo']['name'] : null),
        ];

        $commits = $data['payload']['commits'] ?? [];
        $blocks = [];

        foreach ($commits as $commit) {
            $blocks[] = [
                'title' => 'Commit: ' . substr($commit['sha'], 0, 7),
                'metadata' => [
                    'message' => $commit['message'],
                ],
                'url' => $commit['url'],
                'value' => 1,
                'value_unit' => 'commit',
            ];
        }

        return [
            'events' => [[
                'source_id' => 'github_push_' . $data['id'],
                'time' => $data['created_at'] ?? now(),
                'actor' => $actor,
                'target' => $target,
                'action' => 'push',
                'domain' => 'online',
                'service' => 'github',
                'value' => count($commits),
                'value_multiplier' => 1,
                'value_unit' => 'commit',
                'event_metadata' => [
                    'ref' => $data['payload']['ref'] ?? null,
                    'before' => $data['payload']['before'] ?? null,
                    'after' => $data['payload']['after'] ?? null,
                    'size' => $data['payload']['size'] ?? null,
                ],
                'blocks' => $blocks,
            ]],
        ];
    }

    private function convertPullRequestEvent(array $data): array
    {
        if (empty($data['id']) || empty($data['payload']['pull_request'])) {
            return ['events' => []];
        }

        $pr = $data['payload']['pull_request'];

        $actor = [
            'concept' => 'user',
            'type' => 'github_user',
            'title' => $data['actor']['login'],
            'content' => $data['actor']['login'],
            'metadata' => [
                'github_id' => $data['actor']['id'],
                'avatar_url' => $data['actor']['avatar_url'],
            ],
            'url' => $data['actor']['html_url']
                ?? (isset($data['actor']['login']) ? 'https://github.com/' . $data['actor']['login'] : null),
            'image_url' => $data['actor']['avatar_url'],
        ];

        $target = [
            'concept' => 'pull_request',
            'type' => 'github_pr',
            'title' => '#' . $pr['number'] . ': ' . $pr['title'],
            'content' => $pr['body'] ?? '',
            'metadata' => [
                'github_id' => $pr['id'],
                'number' => $pr['number'],
                'state' => $pr['state'],
                'merged' => $pr['merged'] ?? false,
            ],
            'url' => $pr['html_url'],
        ];

        $action = match ($data['payload']['action']) {
            'opened' => 'opened_pull_request',
            'closed' => ($pr['merged'] ?? false) ? 'merged_pull_request' : 'closed_pull_request',
            'reopened' => 'reopened_pull_request',
            default => 'updated_pull_request',
        };

        return [
            'events' => [[
                'source_id' => 'github_pr_' . $data['id'],
                'time' => $data['created_at'] ?? now(),
                'actor' => $actor,
                'target' => $target,
                'action' => $action,
                'domain' => 'online',
                'service' => 'github',
                'value' => $pr['changed_files'] ?? 1,
                'value_multiplier' => 1,
                'value_unit' => 'file',
                'event_metadata' => [
                    'action' => $data['payload']['action'],
                    'merged' => $pr['merged'] ?? false,
                    'additions' => $pr['additions'] ?? null,
                    'deletions' => $pr['deletions'] ?? null,
                    'changed_files' => $pr['changed_files'] ?? null,
                ],
                'blocks' => [],
            ]],
        ];
    }

    private function convertIssueEvent(array $data): array
    {
        if (empty($data['id']) || empty($data['payload']['issue'])) {
            return ['events' => []];
        }

        $issue = $data['payload']['issue'];

        $actor = [
            'concept' => 'user',
            'type' => 'github_user',
            'title' => $data['actor']['login'],
            'content' => $data['actor']['login'],
            'metadata' => [
                'github_id' => $data['actor']['id'],
                'avatar_url' => $data['actor']['avatar_url'],
            ],
            'url' => $data['actor']['html_url']
                ?? (isset($data['actor']['login']) ? 'https://github.com/' . $data['actor']['login'] : null),
            'image_url' => $data['actor']['avatar_url'],
        ];

        $target = [
            'concept' => 'issue',
            'type' => 'github_issue',
            'title' => '#' . $issue['number'] . ': ' . $issue['title'],
            'content' => $issue['body'] ?? '',
            'metadata' => [
                'github_id' => $issue['id'],
                'number' => $issue['number'],
                'state' => $issue['state'],
            ],
            'url' => $issue['html_url'],
        ];

        $action = match ($data['payload']['action']) {
            'opened' => 'opened_issue',
            'closed' => 'closed_issue',
            'reopened' => 'reopened_issue',
            default => 'updated_issue',
        };

        return [
            'events' => [[
                'source_id' => 'github_issue_' . $data['id'],
                'time' => $data['created_at'] ?? now(),
                'actor' => $actor,
                'target' => $target,
                'action' => $action,
                'domain' => 'online',
                'service' => 'github',
                'value' => 1,
                'value_multiplier' => 1,
                'value_unit' => 'issue',
                'event_metadata' => [
                    'action' => $data['payload']['action'],
                    'state' => $issue['state'],
                ],
                'blocks' => [],
            ]],
        ];
    }

    private function convertCommitCommentEvent(array $data): array
    {
        if (empty($data['id']) || empty($data['payload']['comment'])) {
            return ['events' => []];
        }

        $comment = $data['payload']['comment'];

        $actor = [
            'concept' => 'user',
            'type' => 'github_user',
            'title' => $data['actor']['login'],
            'content' => $data['actor']['login'],
            'metadata' => [
                'github_id' => $data['actor']['id'],
                'avatar_url' => $data['actor']['avatar_url'],
            ],
            'url' => $data['actor']['html_url']
                ?? (isset($data['actor']['login']) ? 'https://github.com/' . $data['actor']['login'] : null),
            'image_url' => $data['actor']['avatar_url'],
        ];

        $target = [
            'concept' => 'repository',
            'type' => 'github_repo',
            'title' => $data['repo']['name'],
            'content' => $data['repo']['description'] ?? '',
            'metadata' => [
                'github_id' => $data['repo']['id'],
                'full_name' => $data['repo']['full_name'] ?? $data['repo']['name'] ?? null,
            ],
            'url' => $data['repo']['html_url']
                ?? (isset($data['repo']['name']) ? 'https://github.com/' . $data['repo']['name'] : null),
        ];

        return [
            'events' => [[
                'source_id' => 'github_comment_' . $data['id'],
                'time' => $data['created_at'] ?? now(),
                'actor' => $actor,
                'target' => $target,
                'action' => 'commented',
                'domain' => 'online',
                'service' => 'github',
                'value' => 1,
                'value_multiplier' => 1,
                'value_unit' => 'comment',
                'event_metadata' => [
                    'comment_id' => $comment['id'],
                    'commit_id' => $comment['commit_id'] ?? null,
                ],
                'blocks' => [[
                    'title' => 'Comment',
                    'metadata' => [
                        'body' => $comment['body'],
                    ],
                    'url' => $comment['html_url'],
                    'value' => null,
                    'value_multiplier' => 1,
                    'value_unit' => null,
                ]],
            ]],
        ];
    }

    private function createEventFromData(array $convertedData): void
    {
        foreach ($convertedData['events'] as $eventData) {
            $actor = $this->createOrUpdateObject($eventData['actor']);
            $target = $this->createOrUpdateObject($eventData['target']);

            $event = Event::updateOrCreate(
                [
                    'integration_id' => $this->integration->id,
                    'source_id' => $eventData['source_id'],
                ],
                [
                    'time' => $eventData['time'],
                    'actor_id' => $actor->id,
                    'service' => $eventData['service'],
                    'domain' => $eventData['domain'],
                    'action' => $eventData['action'],
                    'value' => $eventData['value'] ?? null,
                    'value_multiplier' => $eventData['value_multiplier'] ?? 1,
                    'value_unit' => $eventData['value_unit'] ?? null,
                    'event_metadata' => $eventData['event_metadata'] ?? [],
                    'target_id' => $target->id,
                ]
            );

            // Add blocks if any
            if (! empty($eventData['blocks'])) {
                foreach ($eventData['blocks'] as $blockData) {
                    $event->blocks()->create([
                        'time' => $blockData['time'] ?? $event->time,
                        'block_type' => $blockData['block_type'] ?? 'generic',
                        'title' => $blockData['title'],
                        'metadata' => $blockData['metadata'] ?? [],
                        'url' => $blockData['url'] ?? null,
                        'value' => $blockData['value'] ?? null,
                        'value_multiplier' => $blockData['value_multiplier'] ?? 1,
                        'value_unit' => $blockData['value_unit'] ?? null,
                    ]);
                }
            }

            // Add tags
            $event->syncTags([
                'github',
                'online',
                $eventData['action'],
            ]);
        }
    }
}
