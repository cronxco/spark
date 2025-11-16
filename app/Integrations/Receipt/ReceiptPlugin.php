<?php

namespace App\Integrations\Receipt;

use App\Integrations\Base\WebhookPlugin;
use App\Jobs\Data\Receipt\ProcessReceiptEmailJob;
use App\Models\Integration;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        return 'o-document-text';
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
            'receipt_received_from' => [
                'icon' => 'o-document-text',
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
                'icon' => 'o-list-bullet',
                'display_name' => 'Line Item',
                'description' => 'Individual receipt line item',
                'hidden' => false,
            ],
            'receipt_tax_summary' => [
                'icon' => 'o-calculator',
                'display_name' => 'Tax Summary',
                'description' => 'Tax breakdown',
                'hidden' => false,
            ],
            'receipt_payment_method' => [
                'icon' => 'o-credit-card',
                'display_name' => 'Payment Method',
                'description' => 'How the receipt was paid',
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'receipt_merchant' => [
                'icon' => 'o-building-storefront',
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

        // Extract S3 object key from SES notification
        $s3ObjectKey = $this->extractS3ObjectKey($snsMessage);

        if (! $s3ObjectKey) {
            Log::warning('Receipt: No S3 object key found in SNS notification', [
                'integration_id' => $integration->id,
            ]);
            abort(400, 'No S3 object key in notification');
        }

        // Dispatch job to process receipt email
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
        $payload = $request->all();

        // Check if this is an SNS subscription confirmation
        if (isset($payload['Type']) && $payload['Type'] === 'SubscriptionConfirmation') {
            Log::info('Receipt: SNS subscription confirmation received', [
                'subscribe_url' => $payload['SubscribeURL'] ?? null,
            ]);
            // This should be handled by the route, not here
            return null;
        }

        // Extract the Message field (SES notification is JSON inside this)
        if (! isset($payload['Message'])) {
            return null;
        }

        $message = json_decode($payload['Message'], true);

        return $message ?: null;
    }

    /**
     * Extract S3 object key from SES notification
     */
    private function extractS3ObjectKey(array $snsMessage): ?string
    {
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

        if (! isset($snsMessage['receipt']['action'])) {
            return null;
        }

        $action = $snsMessage['receipt']['action'];

        if ($action['type'] !== 'S3' || ! isset($action['objectKey'])) {
            return null;
        }

        return $action['objectKey'];
    }
}
