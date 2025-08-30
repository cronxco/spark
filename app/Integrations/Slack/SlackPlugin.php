<?php

namespace App\Integrations\Slack;

use App\Integrations\Base\WebhookPlugin;
use App\Models\Integration;
use Illuminate\Http\Request;

class SlackPlugin extends WebhookPlugin
{
    public static function getIdentifier(): string
    {
        return 'slack';
    }

    public static function getDisplayName(): string
    {
        return 'Slack';
    }

    public static function getDescription(): string
    {
        return 'Receive Slack events via webhook';
    }

    public static function getConfigurationSchema(): array
    {
        return [
            'events' => [
                'type' => 'array',
                'label' => 'Events to track',
                'options' => [
                    'message' => 'Message events',
                    'reaction_added' => 'Reaction events',
                    'file_shared' => 'File sharing events',
                ],
                'required' => true,
            ],
        ];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'events' => [
                'label' => 'Events',
                'schema' => self::getConfigurationSchema(),
            ],
        ];
    }

    public static function getIcon(): string
    {
        return 'o-chat-bubble-left-right';
    }

    public static function getAccentColor(): string
    {
        return 'primary';
    }

    public static function getDomain(): string
    {
        return 'communication';
    }

    public static function getActionTypes(): array
    {
        return [
            'sent' => [
                'icon' => 'o-chat-bubble-left',
                'display_name' => 'Message Sent',
                'description' => 'A message was sent in Slack',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'added' => [
                'icon' => 'o-heart',
                'display_name' => 'Reaction Added',
                'description' => 'A reaction was added to a message',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'shared' => [
                'icon' => 'o-document',
                'display_name' => 'File Shared',
                'description' => 'A file was shared in Slack',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [];
    }

    public static function getObjectTypes(): array
    {
        return [
            'slack_user' => [
                'icon' => 'o-user',
                'display_name' => 'Slack User',
                'description' => 'A Slack user account',
                'hidden' => false,
            ],
            'slack_message' => [
                'icon' => 'o-chat-bubble-left',
                'display_name' => 'Slack Message',
                'description' => 'A Slack message',
                'hidden' => false,
            ],
            'slack_reaction' => [
                'icon' => 'o-heart',
                'display_name' => 'Slack Reaction',
                'description' => 'A Slack reaction',
                'hidden' => false,
            ],
            'slack_file' => [
                'icon' => 'o-document',
                'display_name' => 'Slack File',
                'description' => 'A Slack file',
                'hidden' => false,
            ],
        ];
    }

    public function handleWebhook(Request $request, Integration $integration): void
    {
        $payload = $request->all();

        // Verify Slack signature
        if (! $this->verifySlackSignature($request, $integration)) {
            abort(401, 'Invalid Slack signature');
        }

        // Handle Slack URL verification
        if ($payload['type'] === 'url_verification') {
            // For URL verification, we need to return a response
            // This is handled by the controller, so we'll just return
            return;
        }

        // Process the event
        $convertedData = $this->convertData($payload, $integration);
        $this->createEventsFromWebhook($convertedData, $integration);
    }

    public function verifyWebhookSignature(Request $request, Integration $integration): bool
    {
        return $this->verifySlackSignature($request, $integration);
    }

    public function convertData(array $externalData, Integration $integration): array
    {
        $event = $externalData['event'] ?? [];
        $eventType = $event['type'] ?? '';

        switch ($eventType) {
            case 'message':
                return $this->convertMessageEvent($externalData, $integration);
            case 'reaction_added':
                return $this->convertReactionEvent($externalData, $integration);
            case 'file_shared':
                return $this->convertFileEvent($externalData, $integration);
            default:
                return [];
        }
    }

    protected function verifySlackSignature(Request $request, Integration $integration): bool
    {
        $signature = $request->header('X-Slack-Signature');
        $timestamp = $request->header('X-Slack-Request-Timestamp');
        $body = $request->getContent();

        $baseString = "v0:{$timestamp}:{$body}";
        $expectedSignature = 'v0=' . hash_hmac('sha256', $baseString, $integration->account_id);

        return hash_equals($expectedSignature, $signature);
    }

    protected function convertMessageEvent(array $data, Integration $integration): array
    {
        $event = $data['event'];

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
                'source_id' => $data['event_id'],
                'time' => date('Y-m-d H:i:s', $event['ts'] ?? time()),
                'actor' => $actor,
                'target' => $target,
                'domain' => self::getDomain(),
                'action' => 'sent',
                'value' => 1,
                'value_unit' => 'message',
                'event_metadata' => [
                    'channel' => $event['channel'] ?? null,
                    'subtype' => $event['subtype'] ?? null,
                ],
            ]],
        ];
    }

    protected function convertReactionEvent(array $data, Integration $integration): array
    {
        $event = $data['event'];

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
            'concept' => 'reaction',
            'type' => 'slack_reaction',
            'title' => 'Reaction: ' . $event['reaction'],
            'content' => $event['reaction'],
            'metadata' => [
                'reaction' => $event['reaction'],
                'item_type' => $event['item']['type'] ?? null,
                'item_id' => $event['item']['ts'] ?? null,
            ],
            'url' => null,
        ];

        return [
            'events' => [[
                'source_id' => $data['event_id'],
                'time' => date('Y-m-d H:i:s', $event['event_ts'] ?? time()),
                'actor' => $actor,
                'target' => $target,
                'domain' => self::getDomain(),
                'action' => 'added',
                'value' => 1,
                'value_unit' => 'reaction',
                'event_metadata' => [
                    'reaction' => $event['reaction'],
                ],
            ]],
        ];
    }

    protected function convertFileEvent(array $data, Integration $integration): array
    {
        $event = $data['event'];
        $file = $event['file'] ?? [];

        $actor = [
            'concept' => 'user',
            'type' => 'slack_user',
            'title' => $file['user'] ?? 'Unknown User',
            'content' => $file['user'] ?? 'Unknown User',
            'metadata' => [
                'slack_user_id' => $file['user'] ?? null,
            ],
            'url' => null,
        ];

        $target = [
            'concept' => 'file',
            'type' => 'slack_file',
            'title' => $file['name'] ?? 'Unknown File',
            'content' => $file['title'] ?? '',
            'metadata' => [
                'slack_file_id' => $file['id'] ?? null,
                'file_type' => $file['filetype'] ?? null,
                'size' => $file['size'] ?? null,
            ],
            'url' => $file['url_private'] ?? null,
        ];

        return [
            'events' => [[
                'source_id' => $data['event_id'],
                'time' => date('Y-m-d H:i:s', $file['timestamp'] ?? time()),
                'actor' => $actor,
                'target' => $target,
                'domain' => self::getDomain(),
                'action' => 'shared',
                'value' => $file['size'] ?? 1,
                'value_unit' => 'bytes',
                'event_metadata' => [
                    'file_type' => $file['filetype'] ?? null,
                    'channels' => $file['channels'] ?? [],
                ],
            ]],
        ];
    }
}
