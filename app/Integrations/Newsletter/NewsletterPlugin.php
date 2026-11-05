<?php

namespace App\Integrations\Newsletter;

use App\Integrations\Base\WebhookPlugin;
use App\Integrations\Contracts\SupportsTaskPipeline;
use App\Jobs\Data\Newsletter\ProcessNewsletterEmailJob;
use App\Jobs\TaskPipeline\Tasks\NewsletterExpandLinksTask;
use App\Jobs\TaskPipeline\Tasks\NewsletterExtractContentTask;
use App\Jobs\TaskPipeline\Tasks\NewsletterGenerateSummariesTask;
use App\Models\Event;
use App\Models\Integration;
use App\Services\TaskPipeline\TaskDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class NewsletterPlugin extends WebhookPlugin implements SupportsTaskPipeline
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
        return [
            'expand_links' => [
                'type' => 'boolean',
                'label' => 'Bookmark articles from digest newsletters',
                'required' => false,
                'default' => true,
                'description' => 'When list detection is on, the articles a roundup or digest issue links to are bookmarked and fetched individually.',
            ],
        ];
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
            'newsletter_content' => [
                'icon' => 'fas.file-lines',
                'display_name' => 'Issue Content',
                'description' => 'The extracted text of this issue',
                'display_with_object' => false,
                'value_unit' => null,
                'accent_color' => 'info',
                'hidden' => true,
            ],
            'newsletter_link_list' => [
                'icon' => 'fas.list-ol',
                'display_name' => 'Articles Found',
                'description' => 'Articles this digest links to, and which were bookmarked',
                'display_with_object' => true,
                'value_unit' => 'articles',
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

    public static function getTaskDefinitions(): array
    {
        return [
            new TaskDefinition(
                key: 'newsletter_extract_content',
                name: 'Newsletter: Extract Content',
                description: 'Extract clean Markdown from raw newsletter HTML using AI',
                jobClass: NewsletterExtractContentTask::class,
                appliesTo: ['event'],
                conditions: ['service' => 'newsletter', 'domain' => 'knowledge'],
                runOnCreate: true,
                runOnUpdate: false,
                shouldRun: fn (Event $event) => ! empty($event->event_metadata['raw_html'])
                    && ! $event->blocks()->where('block_type', 'newsletter_content')->whereNull('deleted_at')->exists(),
            ),
            new TaskDefinition(
                key: 'newsletter_expand_links',
                name: 'Newsletter: Bookmark Digest Articles',
                description: 'Bookmark and fetch the articles a digest newsletter links to',
                jobClass: NewsletterExpandLinksTask::class,
                appliesTo: ['event'],
                conditions: ['service' => 'newsletter', 'domain' => 'knowledge'],
                runOnCreate: true,
                runOnUpdate: false,
                shouldRun: fn (Event $event) => NewsletterExpandLinksTask::isEnabledFor($event)
                    && ! empty($event->event_metadata['raw_html'])
                    && empty($event->event_metadata['link_assessment']),
            ),
            new TaskDefinition(
                key: 'newsletter_generate_summaries',
                name: 'Newsletter: Generate Summaries',
                description: 'Generate AI summary blocks and tags for a newsletter event',
                jobClass: NewsletterGenerateSummariesTask::class,
                appliesTo: ['event'],
                conditions: ['service' => 'newsletter', 'domain' => 'knowledge'],
                dependencies: ['newsletter_extract_content'],
                runOnCreate: true,
                runOnUpdate: false,
                shouldRun: fn (Event $event) => ! empty($event->issueContent() ?? $event->target?->content)
                    && ! $event->blocks()->where('block_type', 'newsletter_tldr')
                        ->whereNotNull('metadata->content')
                        ->whereNull('deleted_at')
                        ->exists(),
            ),
        ];
    }

    public function handleWebhook(Request $request, Integration $integration): void
    {
        if (! $this->verifyWebhookSignature($request, $integration)) {
            abort(401, 'Invalid webhook secret');
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            $payload = $request->all();
        }

        if (isset($payload['Type'])) {
            $topicArn = config('services.newsletter.sns_topic_arn');
            if (! is_string($topicArn) || $topicArn === '' || ($payload['TopicArn'] ?? null) !== $topicArn) {
                abort(403, 'Untrusted SNS topic');
            }

            if (! $this->isTrustedSnsUrl($payload['SigningCertURL'] ?? null, $topicArn)) {
                abort(403, 'Untrusted SNS certificate URL');
            }

            try {
                $validSignature = $this->hasValidSnsSignature($payload);
            } catch (Throwable $e) {
                Log::warning('Newsletter: SNS signature validation failed', [
                    'integration_id' => $integration->id,
                ]);
                abort(403, 'Invalid SNS signature');
            }

            if (! $validSignature) {
                abort(403, 'Invalid SNS signature');
            }

            if ($payload['Type'] === 'SubscriptionConfirmation') {
                if (! $this->isTrustedSnsUrl($payload['SubscribeURL'] ?? null, $topicArn)) {
                    abort(403, 'Untrusted SNS subscription URL');
                }
                Http::withoutRedirecting()->timeout(10)->get($payload['SubscribeURL'])->throw();

                return;
            }

            if ($payload['Type'] !== 'Notification') {
                abort(400, 'Unsupported SNS message type');
            }
        }

        $loggedPayload = $payload;
        unset($loggedPayload['Signature'], $loggedPayload['Token'], $loggedPayload['SubscribeURL'], $loggedPayload['UnsubscribeURL']);
        $this->logWebhookPayload(static::getIdentifier(), $integration->id, $loggedPayload, $request->headers->all());

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

    protected function sanitizeHeaders(array $headers): array
    {
        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), ['x-original-url', 'x-forwarded-uri', 'referer'])) {
                $headers[$key] = ['[REDACTED]'];
            }
        }

        return parent::sanitizeHeaders($headers);
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

    private function hasValidSnsSignature(array $payload): bool
    {
        $algorithm = match ($payload['SignatureVersion'] ?? null) {
            '1' => OPENSSL_ALGO_SHA1,
            '2' => OPENSSL_ALGO_SHA256,
            default => null,
        };
        $fields = match ($payload['Type'] ?? null) {
            'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
            'SubscriptionConfirmation', 'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
            default => [],
        };
        if ($algorithm === null || $fields === [] || ! is_string($payload['Signature'] ?? null)) {
            return false;
        }

        $signature = base64_decode($payload['Signature'], true);
        if ($signature === false || $signature === '') {
            return false;
        }

        // SNS signs these fields in byte-sort order, with one trailing newline.
        $stringToSign = '';
        foreach ($fields as $field) {
            if ($field === 'Subject' && ! array_key_exists($field, $payload)) {
                continue;
            }
            if (! is_string($payload[$field] ?? null)) {
                return false;
            }
            $stringToSign .= $field . "\n" . $payload[$field] . "\n";
        }

        $url = $payload['SigningCertURL'];
        $parts = parse_url($url);
        if (! preg_match('~^/SimpleNotificationService-[a-zA-Z0-9-]+\\.pem$~', $parts['path'] ?? '') || isset($parts['query'])) {
            return false;
        }

        // The caller restricts this URL to the configured topic's HTTPS SNS host.
        $response = Http::withoutRedirecting()->timeout(10)->get($url);
        $response->throw();
        if ($response->status() !== 200) {
            return false;
        }
        $publicKey = openssl_pkey_get_public($response->body());

        return $publicKey !== false
            && openssl_verify($stringToSign, $signature, $publicKey, $algorithm) === 1;
    }

    private function isTrustedSnsUrl(mixed $url, string $topicArn): bool
    {
        if (! is_string($url)) {
            return false;
        }
        $arn = explode(':', $topicArn);
        if (count($arn) !== 6 || $arn[0] !== 'arn' || $arn[1] !== 'aws' || $arn[2] !== 'sns') {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === 'sns.' . $arn[3] . '.amazonaws.com'
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && (! isset($parts['port']) || $parts['port'] === 443)
            && ! isset($parts['fragment']);
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
