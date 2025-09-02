<?php

namespace App\Jobs\Webhook\Slack;

use App\Jobs\Base\BaseWebhookHookJob;
use App\Jobs\Data\Slack\SlackEventsData;
use Exception;
use Illuminate\Support\Facades\Log;

class SlackEventsHook extends BaseWebhookHookJob
{
    protected function getServiceName(): string
    {
        return 'slack';
    }

    protected function getJobType(): string
    {
        return 'events';
    }

    protected function validateWebhook(): void
    {
        // Handle Slack URL verification challenge
        if (($this->webhookPayload['type'] ?? null) === 'url_verification') {
            Log::info('Slack: URL verification challenge received', [
                'integration_id' => $this->integration->id,
                'challenge' => $this->webhookPayload['challenge'] ?? 'missing',
            ]);

            return;
        }

        // Verify this is a valid event payload
        if (! isset($this->webhookPayload['event'])) {
            throw new Exception('Slack webhook payload missing event data');
        }

        // Verify Slack signature
        if (! $this->verifySlackSignature()) {
            throw new Exception('Invalid Slack webhook signature');
        }
    }

    protected function splitWebhookData(): array
    {
        $event = $this->webhookPayload['event'];
        $eventType = $event['type'] ?? 'unknown';

        Log::info('Slack: Processing webhook event', [
            'integration_id' => $this->integration->id,
            'event_type' => $eventType,
            'event_id' => $this->webhookPayload['event_id'] ?? 'unknown',
            'team_id' => $this->webhookPayload['team_id'] ?? 'unknown',
        ]);

        // Check if this event type is configured to be processed
        $config = $this->integration->configuration ?? [];
        $configuredEvents = $config['events'] ?? ['message', 'reaction_added', 'file_shared'];

        if (! in_array($eventType, $configuredEvents, true)) {
            Log::info('Slack: Event type not configured for processing', [
                'integration_id' => $this->integration->id,
                'event_type' => $eventType,
                'configured_events' => $configuredEvents,
            ]);

            return [];
        }

        // Convert the event data to our internal format
        return $this->convertEventData();
    }

    protected function dispatchProcessingJobs(array $processingData): void
    {
        if (empty($processingData['events'])) {
            Log::info('Slack: No events to process after conversion', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        // Dispatch the data processing job
        SlackEventsData::dispatch($this->integration, $processingData);
    }

    private function verifySlackSignature(): bool
    {
        $signature = $this->headers['x-slack-signature'][0] ?? null;
        $timestamp = $this->headers['x-slack-request-timestamp'][0] ?? null;
        $body = json_encode($this->webhookPayload);

        if (! $signature || ! $timestamp) {
            return false;
        }

        // Slack signatures are valid for 5 minutes
        $timestampInt = (int) $timestamp;
        if (abs(time() - $timestampInt) > 300) {
            return false;
        }

        $baseString = "v0:{$timestamp}:{$body}";
        $expectedSignature = 'v0=' . hash_hmac('sha256', $baseString, $this->integration->account_id);

        return hash_equals($expectedSignature, $signature);
    }

    private function convertEventData(): array
    {
        $event = $this->webhookPayload['event'];
        $eventType = $event['type'] ?? 'unknown';

        return match ($eventType) {
            'message' => $this->convertMessageEvent(),
            'reaction_added' => $this->convertReactionEvent(),
            'file_shared' => $this->convertFileEvent(),
            default => ['events' => []],
        };
    }

    private function convertMessageEvent(): array
    {
        $event = $this->webhookPayload['event'];

        $actor = [
            'concept' => 'user',
            'type' => 'slack_user',
            'title' => $event['user'] ?? 'Unknown User',
            'content' => $event['user'] ?? 'Unknown User',
            'metadata' => [
                'slack_user_id' => $event['user'] ?? null,
                'channel' => $event['channel'] ?? null,
            ],
            'url' => null,
        ];

        $target = [
            'concept' => 'message',
            'type' => 'slack_message',
            'title' => 'Message in ' . ($event['channel'] ?? 'unknown channel'),
            'content' => $event['text'] ?? '',
            'metadata' => [
                'slack_message_id' => $event['ts'] ?? null,
                'channel' => $event['channel'] ?? null,
                'thread_ts' => $event['thread_ts'] ?? null,
            ],
            'url' => null,
        ];

        return [
            'events' => [[
                'source_id' => $this->webhookPayload['event_id'] ?? 'slack_' . ($event['ts'] ?? time()),
                'time' => isset($event['ts']) ? date('Y-m-d H:i:s', $event['ts']) : now(),
                'actor' => $actor,
                'target' => $target,
                'action' => 'sent',
                'domain' => 'online',
                'service' => 'slack',
                'value' => 1,
                'value_multiplier' => 1,
                'value_unit' => 'message',
                'event_metadata' => [
                    'channel' => $event['channel'] ?? null,
                    'subtype' => $event['subtype'] ?? null,
                    'thread_ts' => $event['thread_ts'] ?? null,
                    'team' => $this->webhookPayload['team_id'] ?? null,
                ],
                'blocks' => [],
            ]],
        ];
    }

    private function convertReactionEvent(): array
    {
        $event = $this->webhookPayload['event'];

        $actor = [
            'concept' => 'user',
            'type' => 'slack_user',
            'title' => $event['user'] ?? 'Unknown User',
            'content' => $event['user'] ?? 'Unknown User',
            'metadata' => [
                'slack_user_id' => $event['user'] ?? null,
            ],
            'url' => null,
        ];

        $target = [
            'concept' => 'message',
            'type' => 'slack_message',
            'title' => 'Message',
            'content' => 'Message with reaction',
            'metadata' => [
                'slack_message_id' => $event['item']['ts'] ?? null,
                'channel' => $event['item']['channel'] ?? null,
            ],
            'url' => null,
        ];

        return [
            'events' => [[
                'source_id' => 'slack_reaction_' . ($event['event_ts'] ?? time()),
                'time' => isset($event['event_ts']) ? date('Y-m-d H:i:s', $event['event_ts']) : now(),
                'actor' => $actor,
                'target' => $target,
                'action' => 'added',
                'domain' => 'online',
                'service' => 'slack',
                'value' => 1,
                'value_multiplier' => 1,
                'value_unit' => 'reaction',
                'event_metadata' => [
                    'reaction' => $event['reaction'] ?? null,
                    'item_type' => $event['item']['type'] ?? null,
                    'channel' => $event['item']['channel'] ?? null,
                    'message_ts' => $event['item']['ts'] ?? null,
                    'team' => $this->webhookPayload['team_id'] ?? null,
                ],
                'blocks' => [],
            ]],
        ];
    }

    private function convertFileEvent(): array
    {
        $event = $this->webhookPayload['event'];

        $actor = [
            'concept' => 'user',
            'type' => 'slack_user',
            'title' => $event['user_id'] ?? 'Unknown User',
            'content' => $event['user_id'] ?? 'Unknown User',
            'metadata' => [
                'slack_user_id' => $event['user_id'] ?? null,
            ],
            'url' => null,
        ];

        $target = [
            'concept' => 'file',
            'type' => 'slack_file',
            'title' => $event['file']['name'] ?? 'Shared File',
            'content' => $event['file']['title'] ?? '',
            'metadata' => [
                'slack_file_id' => $event['file']['id'] ?? null,
                'file_type' => $event['file']['filetype'] ?? null,
                'file_size' => $event['file']['size'] ?? null,
            ],
            'url' => $event['file']['permalink'] ?? null,
        ];

        return [
            'events' => [[
                'source_id' => 'slack_file_' . ($event['event_ts'] ?? time()),
                'time' => isset($event['event_ts']) ? date('Y-m-d H:i:s', $event['event_ts']) : now(),
                'actor' => $actor,
                'target' => $target,
                'action' => 'shared',
                'domain' => 'online',
                'service' => 'slack',
                'value' => 1,
                'value_multiplier' => 1,
                'value_unit' => 'file',
                'event_metadata' => [
                    'channel' => $event['channel_id'] ?? null,
                    'file_id' => $event['file']['id'] ?? null,
                    'file_type' => $event['file']['filetype'] ?? null,
                    'file_size' => $event['file']['size'] ?? null,
                    'team' => $this->webhookPayload['team_id'] ?? null,
                ],
                'blocks' => [],
            ]],
        ];
    }
}
