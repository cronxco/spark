<?php

namespace App\Integrations\Receipt;

use App\Integrations\Base\WebhookPlugin;
use App\Jobs\Data\Receipt\ProcessReceiptEmailJob;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReceiptPlugin extends WebhookPlugin
{
    public static function getIdentifier(): string
    {
        return 'receipt';
    }

    public static function getDisplayName(): string
    {
        return 'Receipt';
    }

    public static function getDescription(): string
    {
        return 'Automatically process receipt emails and match them to financial transactions.';
    }

    public static function getConfigurationSchema(?string $instanceType = null): array
    {
        return [];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'receipts' => [
                'label' => 'Receipts',
                'schema' => self::getConfigurationSchema(),
            ],
        ];
    }

    public static function getIcon(): string
    {
        return 'fas.receipt';
    }

    public static function getAccentColor(): string
    {
        return 'success';
    }

    public static function getDomain(): string
    {
        return 'money';
    }

    public static function getActionTypes(): array
    {
        return [
            'had_receipt_from' => [
                'icon' => 'fas.receipt',
                'display_name' => 'Receipt',
                'description' => 'Receipt received from merchant',
                'display_with_object' => true,
                'value_unit' => 'GBP',
                'value_formatter' => '<span class="text-[0.875em]">£</span>{{ number_format($value, 2) }}',
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'receipt_line_item' => [
                'icon' => 'fas.list',
                'display_name' => 'Line Item',
                'description' => 'Individual receipt line item',
                'display_with_object' => true,
                'value_unit' => 'GBP',
                'hidden' => false,
            ],
            'receipt_tax_summary' => [
                'icon' => 'fas.calculator',
                'display_name' => 'Tax Summary',
                'description' => 'Tax breakdown',
                'display_with_object' => true,
                'value_unit' => 'GBP',
                'hidden' => false,
            ],
            'receipt_payment_method' => [
                'icon' => 'fas.credit-card',
                'display_name' => 'Payment Method',
                'description' => 'How the receipt was paid',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'receipt_merchant' => [
                'icon' => 'fas.store',
                'display_name' => 'Receipt Merchant',
                'description' => 'Merchant from receipt',
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
            Log::warning('Receipt: Invalid SNS notification received', [
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

            Log::info('Receipt: Processing email from SNS content', [
                'integration_id' => $integration->id,
                'content_length' => strlen($emailContent),
                'encoding' => $encoding,
            ]);

            // Dispatch job with the raw email content
            ProcessReceiptEmailJob::dispatch($integration, null, $emailContent);

            return;
        }

        // Fall back to S3 object key extraction
        $s3ObjectKey = $this->extractS3ObjectKey($snsMessage);

        if (! $s3ObjectKey) {
            Log::warning('Receipt: No S3 object key or content found in SNS notification', [
                'integration_id' => $integration->id,
            ]);
            abort(400, 'No S3 object key or content in notification');
        }

        // Dispatch job to process receipt email from S3
        ProcessReceiptEmailJob::dispatch($integration, $s3ObjectKey);

        Log::info('Receipt: Email processing job dispatched', [
            'integration_id' => $integration->id,
            's3_object_key' => $s3ObjectKey,
        ]);
    }

    public function convertData(array $data, Integration $integration): array
    {
        // This plugin doesn't use the standard convertData pattern
        // Processing is handled by ProcessReceiptEmailJob instead
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

        Log::debug('Receipt: Parsing SNS notification', [
            'content_type' => $request->header('Content-Type'),
            'has_type' => isset($payload['Type']),
            'type' => $payload['Type'] ?? null,
            'has_message' => isset($payload['Message']),
        ]);

        // Check if this is an SNS subscription confirmation
        if (isset($payload['Type']) && $payload['Type'] === 'SubscriptionConfirmation') {
            Log::info('Receipt: SNS subscription confirmation received', [
                'subscribe_url' => $payload['SubscribeURL'] ?? null,
            ]);

            // Auto-confirm the subscription by hitting the SubscribeURL
            if (isset($payload['SubscribeURL'])) {
                try {
                    Http::get($payload['SubscribeURL']);
                    Log::info('Receipt: SNS subscription confirmed');
                } catch (Throwable $e) {
                    Log::error('Receipt: Failed to confirm SNS subscription', [
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
                Log::warning('Receipt: SNS Notification missing Message field', [
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

        Log::warning('Receipt: Unrecognized SNS payload format', [
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
        Log::debug('Receipt: Extracting S3 object key from message', [
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

            Log::debug('Receipt: Found receipt.action', [
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
            Log::debug('Receipt: Checking mail.messageId as potential S3 key', [
                'messageId' => $snsMessage['mail']['messageId'],
            ]);
        }

        return null;
    }
}
