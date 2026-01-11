<?php

namespace App\Integrations\Oyster;

use App\Integrations\Base\WebhookPlugin;
use App\Jobs\Data\Oyster\ProcessOysterEmailJob;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OysterPlugin extends WebhookPlugin
{
    public static function getIdentifier(): string
    {
        return 'oyster';
    }

    public static function getDisplayName(): string
    {
        return 'TfL Oyster Card';
    }

    public static function getDescription(): string
    {
        return 'Track your London transport journeys via weekly TfL Oyster card email statements.';
    }

    public static function getConfigurationSchema(?string $instanceType = null): array
    {
        return [];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'journeys' => [
                'label' => 'Journey Tracking',
                'schema' => self::getConfigurationSchema(),
            ],
        ];
    }

    public static function getIcon(): string
    {
        return 'fas.train-subway';
    }

    public static function getAccentColor(): string
    {
        return 'info';
    }

    public static function getDomain(): string
    {
        return 'online';
    }

    public static function getActionTypes(): array
    {
        return [
            'touched_in_at' => [
                'icon' => 'fas.arrow-right-to-bracket',
                'display_name' => 'Touched In',
                'description' => 'Started journey at station',
                'display_with_object' => true,
                'value_unit' => 'GBP',
                'value_formatter' => '<span class="text-[0.875em]">£</span>{{ number_format($value, 2) }}',
                'hidden' => false,
            ],
            'touched_out_at' => [
                'icon' => 'fas.arrow-right-from-bracket',
                'display_name' => 'Touched Out',
                'description' => 'Ended journey at station',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'topped_up_balance' => [
                'icon' => 'fas.credit-card',
                'display_name' => 'Topped Up',
                'description' => 'Added credit to Oyster card',
                'display_with_object' => false,
                'value_unit' => 'GBP',
                'value_formatter' => '<span class="text-[0.875em]">£</span>{{ number_format($value, 2) }}',
                'hidden' => false,
            ],
            'added_season_ticket' => [
                'icon' => 'fas.ticket',
                'display_name' => 'Season Ticket Added',
                'description' => 'Added season ticket to Oyster card',
                'display_with_object' => false,
                'value_unit' => null,
                'hidden' => false,
            ],
            'received_refund' => [
                'icon' => 'fas.rotate-left',
                'display_name' => 'Refund Received',
                'description' => 'Received a fare refund',
                'display_with_object' => false,
                'value_unit' => 'GBP',
                'value_formatter' => '<span class="text-[0.875em]">£</span>{{ number_format($value, 2) }}',
                'hidden' => false,
            ],
            'fare_adjustment' => [
                'icon' => 'fas.sliders',
                'display_name' => 'Fare Adjustment',
                'description' => 'Fare was adjusted',
                'display_with_object' => false,
                'value_unit' => 'GBP',
                'value_formatter' => '<span class="text-[0.875em]">£</span>{{ number_format($value, 2) }}',
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'oyster_journey_summary' => [
                'icon' => 'fas.route',
                'display_name' => 'Journey Summary',
                'description' => 'Summary of a complete journey',
                'display_with_object' => true,
                'value_unit' => 'GBP',
                'value_formatter' => '<span class="text-[0.875em]">£</span>{{ number_format($value, 2) }}',
                'hidden' => false,
            ],
            'oyster_weekly_stats' => [
                'icon' => 'fas.chart-bar',
                'display_name' => 'Weekly Stats',
                'description' => 'Weekly travel statistics',
                'display_with_object' => false,
                'value_unit' => 'GBP',
                'value_formatter' => '<span class="text-[0.875em]">£</span>{{ number_format($value, 2) }}',
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'oyster_card' => [
                'icon' => 'fas.credit-card',
                'display_name' => 'Oyster Card',
                'description' => 'TfL Oyster card for transport',
                'hidden' => false,
            ],
            'tfl_station' => [
                'icon' => 'fas.train-subway',
                'display_name' => 'TfL Station',
                'description' => 'Transport for London station or stop',
                'hidden' => false,
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
            Log::warning('Oyster: Invalid SNS notification received', [
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

            Log::info('Oyster: Processing email from SNS content', [
                'integration_id' => $integration->id,
                'content_length' => strlen($emailContent),
                'encoding' => $encoding,
            ]);

            // Dispatch job with the raw email content
            ProcessOysterEmailJob::dispatch($integration, null, $emailContent);

            return;
        }

        // Fall back to S3 object key extraction
        $s3ObjectKey = $this->extractS3ObjectKey($snsMessage);

        if (! $s3ObjectKey) {
            Log::warning('Oyster: No S3 object key or content found in SNS notification', [
                'integration_id' => $integration->id,
            ]);
            abort(400, 'No S3 object key or content in notification');
        }

        // Dispatch job to process Oyster email from S3
        ProcessOysterEmailJob::dispatch($integration, $s3ObjectKey);

        Log::info('Oyster: Email processing job dispatched', [
            'integration_id' => $integration->id,
            's3_object_key' => $s3ObjectKey,
        ]);
    }

    public function convertData(array $data, Integration $integration): array
    {
        // This plugin doesn't use the standard convertData pattern
        // Processing is handled by ProcessOysterEmailJob instead
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

        Log::debug('Oyster: Parsing SNS notification', [
            'content_type' => $request->header('Content-Type'),
            'has_type' => isset($payload['Type']),
            'type' => $payload['Type'] ?? null,
            'has_message' => isset($payload['Message']),
        ]);

        // Check if this is an SNS subscription confirmation
        if (isset($payload['Type']) && $payload['Type'] === 'SubscriptionConfirmation') {
            Log::info('Oyster: SNS subscription confirmation received', [
                'subscribe_url' => $payload['SubscribeURL'] ?? null,
            ]);

            // Verify the SNS message signature before confirming
            if (! $this->verifySnsSignature($payload)) {
                Log::warning('Oyster: SNS subscription confirmation failed signature verification');

                return null;
            }

            // Auto-confirm the subscription by hitting the SubscribeURL
            if (isset($payload['SubscribeURL'])) {
                try {
                    Http::get($payload['SubscribeURL']);
                    Log::info('Oyster: SNS subscription confirmed');
                } catch (Throwable $e) {
                    Log::error('Oyster: Failed to confirm SNS subscription', [
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
                Log::warning('Oyster: SNS Notification missing Message field', [
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

        Log::warning('Oyster: Unrecognized SNS payload format', [
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
        Log::debug('Oyster: Extracting S3 object key from message', [
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

            Log::debug('Oyster: Found receipt.action', [
                'action_type' => $action['type'] ?? null,
                'has_objectKey' => isset($action['objectKey']),
                'action_keys' => array_keys($action),
            ]);

            if (($action['type'] ?? null) === 'S3' && isset($action['objectKey'])) {
                return $action['objectKey'];
            }
        }

        // Alternative: Check if objectKey is at a different path
        if (isset($snsMessage['mail']['messageId'])) {
            Log::debug('Oyster: Checking mail.messageId as potential S3 key', [
                'messageId' => $snsMessage['mail']['messageId'],
            ]);
        }

        return null;
    }

    /**
     * Verify SNS message signature to prevent spoofing
     *
     * @see https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message.html
     */
    private function verifySnsSignature(array $payload): bool
    {
        // Required fields for signature verification
        $requiredFields = ['SigningCertURL', 'Signature', 'Type', 'SignatureVersion'];
        foreach ($requiredFields as $field) {
            if (! isset($payload[$field])) {
                Log::warning('Oyster: SNS message missing required field for verification', [
                    'missing_field' => $field,
                ]);

                return false;
            }
        }

        // Only support SignatureVersion 1 (SHA1)
        if ($payload['SignatureVersion'] !== '1') {
            Log::warning('Oyster: Unsupported SNS signature version', [
                'version' => $payload['SignatureVersion'],
            ]);

            return false;
        }

        // Validate that the certificate URL is from AWS
        $certUrl = $payload['SigningCertURL'];
        $parsedUrl = parse_url($certUrl);

        if (! $parsedUrl || ! isset($parsedUrl['host'])) {
            return false;
        }

        // Certificate must be from Amazon SNS
        if (! preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/i', $parsedUrl['host'])) {
            Log::warning('Oyster: SNS certificate URL is not from AWS', [
                'cert_url' => $certUrl,
            ]);

            return false;
        }

        // Must use HTTPS
        if (($parsedUrl['scheme'] ?? '') !== 'https') {
            Log::warning('Oyster: SNS certificate URL is not HTTPS', [
                'cert_url' => $certUrl,
            ]);

            return false;
        }

        try {
            // Fetch the certificate
            $certResponse = Http::timeout(10)->get($certUrl);
            if (! $certResponse->successful()) {
                Log::warning('Oyster: Failed to fetch SNS certificate', [
                    'status' => $certResponse->status(),
                ]);

                return false;
            }

            $certificate = $certResponse->body();

            // Build the string to sign based on message type
            $stringToSign = $this->buildSnsStringToSign($payload);

            // Decode the signature
            $signature = base64_decode($payload['Signature']);

            // Verify the signature
            $publicKey = openssl_pkey_get_public($certificate);
            if (! $publicKey) {
                Log::warning('Oyster: Failed to extract public key from SNS certificate');

                return false;
            }

            $verified = openssl_verify($stringToSign, $signature, $publicKey, OPENSSL_ALGO_SHA1);

            return $verified === 1;
        } catch (Throwable $e) {
            Log::warning('Oyster: SNS signature verification failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Build the string to sign for SNS signature verification
     */
    private function buildSnsStringToSign(array $payload): string
    {
        $type = $payload['Type'] ?? '';

        // Fields to include depend on message type
        if ($type === 'Notification') {
            $fields = ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'];
        } else {
            // SubscriptionConfirmation or UnsubscribeConfirmation
            $fields = ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        }

        $stringToSign = '';
        foreach ($fields as $field) {
            if (isset($payload[$field])) {
                $stringToSign .= "{$field}\n{$payload[$field]}\n";
            }
        }

        return $stringToSign;
    }
}
