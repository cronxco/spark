<?php

namespace App\Integrations\Newsletter;

use App\Integrations\Base\WebhookPlugin;
use App\Jobs\Data\Newsletter\ProcessNewsletterEmailJob;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class NewsletterPlugin extends WebhookPlugin
{
    public static function getIdentifier(): string
    {
        return 'newsletter';
    }

    public static function getDisplayName(): string
    {
        return 'Newsletter';
    }

    public static function getDescription(): string
    {
        return 'Automatically process newsletter emails with AI-powered summaries and content extraction.';
    }

    public static function getConfigurationSchema(?string $instanceType = null): array
    {
        return [];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'newsletters' => [
                'label' => 'Newsletters',
                'schema' => self::getConfigurationSchema(),
            ],
        ];
    }

    public static function getIcon(): string
    {
        return 'fas.newspaper';
    }

    public static function getAccentColor(): string
    {
        return 'info';
    }

    public static function getDomain(): string
    {
        return 'knowledge';
    }

    public static function getActionTypes(): array
    {
        return [
            'received_post' => [
                'icon' => 'fas.envelope-open-text',
                'display_name' => 'Newsletter Post',
                'description' => 'Newsletter article received',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'newsletter_summary_tweet' => [
                'icon' => 'fab.twitter',
                'display_name' => 'Tweet Summary',
                'description' => 'Tweet-length summary (280 characters)',
                'display_with_object' => true,
                'value_unit' => null,
                'accent_color' => 'info',
                'hidden' => false,
            ],
            'newsletter_summary_short' => [
                'icon' => 'fas.align-left',
                'display_name' => 'Short Summary',
                'description' => 'Concise summary (40 words)',
                'display_with_object' => true,
                'value_unit' => null,
                'accent_color' => 'info',
                'hidden' => false,
            ],
            'newsletter_summary_paragraph' => [
                'icon' => 'fas.paragraph',
                'display_name' => 'Paragraph Summary',
                'description' => 'Detailed summary (150 words)',
                'display_with_object' => true,
                'value_unit' => null,
                'accent_color' => 'info',
                'hidden' => false,
            ],
            'newsletter_key_takeaways' => [
                'icon' => 'fas.list-check',
                'display_name' => 'Key Takeaways',
                'description' => 'Important points (3-5 bullets)',
                'display_with_object' => true,
                'value_unit' => null,
                'accent_color' => 'info',
                'hidden' => false,
            ],
            'newsletter_tldr' => [
                'icon' => 'fas.bolt',
                'display_name' => 'TL;DR',
                'description' => 'Ultra-brief summary (20 words)',
                'display_with_object' => true,
                'value_unit' => null,
                'accent_color' => 'info',
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'newsletter_publication' => [
                'icon' => 'fas.newspaper',
                'display_name' => 'Publication',
                'description' => 'Newsletter publication source',
                'hidden' => false,
            ],
            'newsletter_user' => [
                'icon' => 'fas.user-circle',
                'display_name' => 'Newsletter Reader',
                'description' => 'Newsletter system user (Me)',
                'hidden' => true,
            ],
        ];
    }

    public function handleWebhook(Request $request, Integration $integration): void
    {
        // Log the webhook payload
        $payload = $request->all();
        $headers = $request->headers->all();
        $this->logWebhookPayload(static::getIdentifier(), $integration->id, $payload, $headers);

        // Parse SNS notification
        $snsMessage = $this->parseSnsNotification($request);

        if (! $snsMessage) {
            Log::warning('Newsletter: Invalid SNS notification received', [
                'integration_id' => $integration->id,
            ]);
            abort(400, 'Invalid SNS notification');
        }

        // Check if email content is included directly (SNS action type)
        if (isset($snsMessage['content'])) {
            $emailContent = $snsMessage['content'];

            // Check encoding - SES can send base64 encoded content
            $encoding = $snsMessage['receipt']['action']['encoding'] ?? null;
            if ($encoding === 'BASE64') {
                $emailContent = base64_decode($emailContent);
            }

            Log::info('Newsletter: Processing email from SNS content', [
                'integration_id' => $integration->id,
                'content_length' => strlen($emailContent),
                'encoding' => $encoding,
            ]);

            // Dispatch job with the raw email content
            ProcessNewsletterEmailJob::dispatch($integration, null, $emailContent);

            return;
        }

        // Fall back to S3 object key extraction
        $s3ObjectKey = $this->extractS3ObjectKey($snsMessage);

        if (! $s3ObjectKey) {
            Log::warning('Newsletter: No S3 object key or content found in SNS notification', [
                'integration_id' => $integration->id,
            ]);
            abort(400, 'No S3 object key or content in notification');
        }

        // Dispatch job to process newsletter email from S3
        ProcessNewsletterEmailJob::dispatch($integration, $s3ObjectKey);

        Log::info('Newsletter: Email processing job dispatched', [
            'integration_id' => $integration->id,
            's3_object_key' => $s3ObjectKey,
        ]);
    }

    public function convertData(array $data, Integration $integration): array
    {
        // This plugin doesn't use the standard convertData pattern
        // Processing is handled by ProcessNewsletterEmailJob instead
        return ['events' => []];
    }

    /**
     * Parse SNS notification and extract the message
     */
    private function parseSnsNotification(Request $request): ?array
    {
        // AWS SNS sends content as text/plain, so we need to parse the raw body
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);

        // Fall back to request->all() if raw body isn't valid JSON
        if (! $payload) {
            $payload = $request->all();
        }

        Log::debug('Newsletter: Parsing SNS notification', [
            'content_type' => $request->header('Content-Type'),
            'has_type' => isset($payload['Type']),
            'type' => $payload['Type'] ?? null,
            'has_message' => isset($payload['Message']),
        ]);

        // Check if this is an SNS subscription confirmation
        if (isset($payload['Type']) && $payload['Type'] === 'SubscriptionConfirmation') {
            Log::info('Newsletter: SNS subscription confirmation received', [
                'subscribe_url' => $payload['SubscribeURL'] ?? null,
            ]);

            // Auto-confirm the subscription by hitting the SubscribeURL
            if (isset($payload['SubscribeURL'])) {
                try {
                    Http::get($payload['SubscribeURL']);
                    Log::info('Newsletter: SNS subscription confirmed');
                } catch (Throwable $e) {
                    Log::error('Newsletter: Failed to confirm SNS subscription', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return null;
        }

        // Handle SNS Notification type
        if (isset($payload['Type']) && $payload['Type'] === 'Notification') {
            // Extract the Message field (SES notification is JSON inside this)
            if (! isset($payload['Message'])) {
                Log::warning('Newsletter: SNS Notification missing Message field', [
                    'payload_keys' => array_keys($payload),
                ]);

                return null;
            }

            $message = json_decode($payload['Message'], true);

            return $message ?: null;
        }

        // If no Type field, maybe the payload IS the message (direct SES notification)
        if (isset($payload['receipt']) || isset($payload['mail'])) {
            return $payload;
        }

        Log::warning('Newsletter: Unrecognized SNS payload format', [
            'payload_keys' => array_keys($payload),
        ]);

        return null;
    }

    /**
     * Extract S3 object key from SES notification
     */
    private function extractS3ObjectKey(array $snsMessage): ?string
    {
        // Log the message structure for debugging
        Log::debug('Newsletter: Extracting S3 object key from message', [
            'message_keys' => array_keys($snsMessage),
            'has_receipt' => isset($snsMessage['receipt']),
            'has_mail' => isset($snsMessage['mail']),
            'has_content' => isset($snsMessage['content']),
        ]);

        // SES notification structure:
        // {
        //   "receipt": {
        //     "action": {
        //       "type": "S3",
        //       "bucketName": "...",
        //       "objectKey": "..."
        //     }
        //   }
        // }

        if (isset($snsMessage['receipt']['action'])) {
            $action = $snsMessage['receipt']['action'];

            Log::debug('Newsletter: Found receipt.action', [
                'action_type' => $action['type'] ?? null,
                'has_objectKey' => isset($action['objectKey']),
                'action_keys' => array_keys($action),
            ]);

            if (($action['type'] ?? null) === 'S3' && isset($action['objectKey'])) {
                return $action['objectKey'];
            }
        }

        // Alternative: Check if objectKey is at a different path
        // Some SES configurations put it differently
        if (isset($snsMessage['mail']['messageId'])) {
            // The messageId might be the S3 key in some configurations
            Log::debug('Newsletter: Checking mail.messageId as potential S3 key', [
                'messageId' => $snsMessage['mail']['messageId'],
            ]);
        }

        return null;
    }
}
