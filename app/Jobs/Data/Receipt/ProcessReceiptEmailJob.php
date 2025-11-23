<?php

namespace App\Jobs\Data\Receipt;

use App\Integrations\Receipt\ReceiptExtractor;
use App\Jobs\Concerns\EnhancedIdempotency;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Carbon\Carbon;
use Exception;
use Html2Text\Html2Text;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser as PdfParser;
use ZBateson\MailMimeParser\MailMimeParser;

class ProcessReceiptEmailJob implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes for email processing + AI

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function __construct(
        public Integration $integration,
        public ?string $s3ObjectKey = null,
        public ?string $rawEmailContent = null
    ) {}

    public function handle(): void
    {
        Log::info('Receipt: Processing receipt email', [
            'integration_id' => $this->integration->id,
            's3_object_key' => $this->s3ObjectKey,
            'has_raw_content' => ! empty($this->rawEmailContent),
        ]);

        try {
            // Get email content - either from raw content or S3
            if (! empty($this->rawEmailContent)) {
                $emailContent = $this->rawEmailContent;
            } elseif (! empty($this->s3ObjectKey)) {
                $emailContent = $this->downloadEmailFromS3($this->s3ObjectKey);
            } else {
                throw new Exception('No email content or S3 key provided');
            }

            // Parse email to extract text
            $parsedEmail = $this->parseEmail($emailContent);

            // Extract receipt data using OpenAI
            $extractor = new ReceiptExtractor;
            $receiptData = $extractor->extract(
                $parsedEmail['combined_text'],
                $parsedEmail['subject'],
                $parsedEmail['from']
            );

            // Check if this was identified as not a valid receipt
            if (isset($receiptData['is_valid_receipt']) && $receiptData['is_valid_receipt'] === false) {
                Log::info('Receipt: Skipping non-receipt email', [
                    'integration_id' => $this->integration->id,
                    'subject' => $parsedEmail['subject'],
                    'from' => $parsedEmail['from'],
                    'rejection_reason' => $receiptData['rejection_reason'] ?? 'unknown',
                ]);

                // Don't create any events for non-receipts
                return;
            }

            // Create receipt event and related data
            $receiptEvent = $this->createReceiptEvent($receiptData, $parsedEmail);

            // Dispatch matching job
            MatchReceiptToTransactionJob::dispatch($receiptEvent);

            Log::info('Receipt: Successfully processed receipt email', [
                'integration_id' => $this->integration->id,
                's3_object_key' => $this->s3ObjectKey,
                'receipt_event_id' => $receiptEvent->id,
            ]);
        } catch (Exception $e) {
            Log::error('Receipt: Failed to process email', [
                'integration_id' => $this->integration->id,
                's3_object_key' => $this->s3ObjectKey,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function uniqueId(): string
    {
        $contentHash = $this->s3ObjectKey
            ? md5($this->s3ObjectKey)
            : md5($this->rawEmailContent ?? '');

        return 'process_receipt_email_' . $this->integration->id . '_' . $contentHash;
    }

    /**
     * Download email file from S3
     */
    private function downloadEmailFromS3(string $objectKey): string
    {
        $disk = Storage::disk('s3-receipts');

        if (! $disk->exists($objectKey)) {
            throw new Exception("Email file not found in S3: {$objectKey}");
        }

        $content = $disk->get($objectKey);

        Log::info('Receipt: Downloaded email from S3', [
            's3_object_key' => $objectKey,
            'size_bytes' => strlen($content),
        ]);

        return $content;
    }

    /**
     * Parse email content to extract text, subject, from, etc.
     *
     * This uses zbateson/mail-mime-parser to parse the MIME structure
     */
    private function parseEmail(string $emailContent): array
    {
        try {
            // Use MailMimeParser to parse the email
            $parser = new MailMimeParser;
            $message = $parser->parse($emailContent, false);

            // Extract basic fields
            $subject = $message->getHeaderValue('subject') ?: 'No Subject';
            $from = $message->getHeaderValue('from') ?: '';
            $date = $message->getHeaderValue('date') ?: now()->toRfc2822String();

            // Extract text content
            $textPlain = $message->getTextContent() ?: '';
            $textHtml = $message->getHtmlContent() ?: '';

            // Convert HTML to plain text if needed
            if ($textHtml && ! $textPlain) {
                $textPlain = $this->htmlToText($textHtml);
            }

            // Extract PDF attachments and parse them
            $pdfText = '';
            $attachments = $message->getAllAttachmentParts();
            foreach ($attachments as $attachment) {
                if ($attachment->getContentType() === 'application/pdf') {
                    $pdfContent = $attachment->getContent();
                    $pdfText .= $this->extractPdfText($pdfContent);
                }
            }

            // Combine all text sources
            $combinedText = trim($textPlain . "\n\n" . $pdfText);

            Log::info('Receipt: Parsed email', [
                'subject' => $subject,
                'from' => $from,
                'text_length' => strlen($combinedText),
                'attachments_count' => count($attachments),
            ]);

            return [
                'subject' => $subject,
                'from' => $from,
                'date' => $date,
                'text_plain' => $textPlain,
                'text_html' => $textHtml,
                'pdf_text' => $pdfText,
                'combined_text' => $combinedText,
            ];
        } catch (Exception $e) {
            Log::error('Receipt: Email parsing failed', [
                'error' => $e->getMessage(),
            ]);

            throw new Exception('Failed to parse email: ' . $e->getMessage());
        }
    }

    /**
     * Convert HTML to plain text using html2text
     */
    private function htmlToText(string $html): string
    {
        try {
            $converter = new Html2Text($html);

            return $converter->getText();
        } catch (Exception $e) {
            Log::warning('Receipt: HTML conversion failed, using strip_tags', [
                'error' => $e->getMessage(),
            ]);

            return strip_tags($html);
        }
    }

    /**
     * Extract text from PDF using smalot/pdfparser
     */
    private function extractPdfText(string $pdfContent): string
    {
        try {
            $parser = new PdfParser;
            $pdf = $parser->parseContent($pdfContent);
            $text = $pdf->getText();

            Log::info('Receipt: Extracted text from PDF', [
                'text_length' => strlen($text),
            ]);

            return $text;
        } catch (Exception $e) {
            Log::warning('Receipt: PDF parsing failed', [
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Create receipt event and related objects/blocks
     */
    private function createReceiptEvent(array $receiptData, array $parsedEmail): Event
    {
        // Create merchant EventObject
        $merchant = EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'merchant',
                'type' => 'receipt_merchant',
                'title' => $receiptData['merchant']['name'],
            ],
            [
                'time' => now(),
                'content' => implode(', ', array_filter([
                    $receiptData['merchant']['address'] ?? null,
                    $receiptData['merchant']['phone'] ?? null,
                ])),
                'metadata' => [
                    'address' => $receiptData['merchant']['address'] ?? null,
                    'phone' => $receiptData['merchant']['phone'] ?? null,
                    'tax_id' => $receiptData['merchant']['tax_id'] ?? null,
                    'merchant_id' => $receiptData['merchant']['merchant_id'] ?? null,
                    'normalized_name' => strtolower($receiptData['merchant']['name']),
                    'is_matched' => false,
                    'needs_review' => false,
                    'raw_email_s3_key' => $this->s3ObjectKey,
                ],
            ]
        );

        // Create user actor EventObject
        $actor = EventObject::firstOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'user',
                'type' => 'user',
                'title' => 'Me',
            ],
            [
                'time' => now(),
                'metadata' => [],
            ]
        );

        // Parse transaction date
        $transactionTime = isset($receiptData['transaction_metadata']['transaction_date'])
            ? Carbon::parse($receiptData['transaction_metadata']['transaction_date'])
            : Carbon::parse($parsedEmail['date']);

        // Create receipt event
        $event = Event::create([
            'source_id' => 'receipt_' . Str::uuid(),
            'time' => $transactionTime,
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'actor_metadata' => [],
            'service' => 'receipt',
            'domain' => 'money',
            'action' => 'receipt_received_from',
            'value' => $receiptData['transaction_summary']['total_amount'],
            'value_multiplier' => 100,
            'value_unit' => $receiptData['transaction_summary']['currency'],
            'event_metadata' => [
                'receipt_metadata' => $receiptData['receipt_metadata'],
                'transaction_metadata' => $receiptData['transaction_metadata'],
                'transaction_summary' => $receiptData['transaction_summary'],
                'matching_hints' => $receiptData['matching_hints'],
                'raw_extraction' => $receiptData, // Store full extraction for debugging
            ],
            'target_id' => $merchant->id,
            'target_metadata' => [],
        ]);

        // Create line item blocks (only if there are line items with descriptions)
        $lineItems = $receiptData['line_items'] ?? [];
        foreach ($lineItems as $item) {
            // Skip line items without descriptions or prices
            if (empty($item['description']) || ! isset($item['total_price'])) {
                continue;
            }

            $event->createBlock([
                'time' => $transactionTime,
                'block_type' => 'receipt_line_item',
                'title' => $item['description'],
                'value' => $item['total_price'],
                'value_multiplier' => 100,
                'value_unit' => $receiptData['transaction_summary']['currency'],
                'metadata' => [
                    'sequence' => $item['sequence'] ?? null,
                    'quantity' => $item['quantity'] ?? null,
                    'unit' => $item['unit'] ?? null,
                    'unit_price' => $item['unit_price'] ?? null,
                    'category' => $item['category'] ?? null,
                    'sku' => $item['sku'] ?? null,
                    'tax_rate' => $item['tax_rate'] ?? null,
                ],
            ]);
        }

        // Create tax summary block (only if there's tax data)
        $taxTotal = $receiptData['transaction_summary']['tax_total'] ?? null;
        $taxRate = $receiptData['transaction_summary']['tax_rate'] ?? null;
        if ($taxTotal !== null && $taxTotal > 0) {
            $event->createBlock([
                'time' => $transactionTime,
                'block_type' => 'receipt_tax_summary',
                'title' => 'Tax Summary',
                'value' => $taxTotal,
                'value_multiplier' => 100,
                'value_unit' => $receiptData['transaction_summary']['currency'],
                'metadata' => [
                    'tax_rate' => $taxRate,
                    'subtotal' => $receiptData['transaction_summary']['subtotal'] ?? null,
                    'discount_total' => $receiptData['transaction_summary']['discount_total'] ?? 0,
                    'tip_amount' => $receiptData['transaction_summary']['tip_amount'] ?? 0,
                ],
            ]);
        }

        // Create payment method block (only if there's meaningful payment data)
        $paymentMethod = $receiptData['transaction_metadata']['payment_method'] ?? null;
        $cardLast4 = $receiptData['transaction_metadata']['card_last_4'] ?? null;
        $receiptNumber = $receiptData['transaction_metadata']['receipt_number'] ?? null;
        $terminalId = $receiptData['transaction_metadata']['terminal_id'] ?? null;

        // Only create if we have at least one meaningful field (not just 'unknown')
        $hasPaymentData = ($paymentMethod && $paymentMethod !== 'unknown')
            || $cardLast4
            || $receiptNumber
            || $terminalId;

        if ($hasPaymentData) {
            $event->createBlock([
                'time' => $transactionTime,
                'block_type' => 'receipt_payment_method',
                'title' => 'Payment Method',
                'metadata' => [
                    'payment_method' => $paymentMethod,
                    'card_last_4' => $cardLast4,
                    'receipt_number' => $receiptNumber,
                    'terminal_id' => $terminalId,
                ],
            ]);
        }

        Log::info('Receipt: Created receipt event and blocks', [
            'event_id' => $event->id,
            'merchant' => $merchant->title,
            'amount' => $event->value,
            'currency' => $event->value_unit,
            'line_items_count' => count($receiptData['line_items'] ?? []),
        ]);

        return $event;
    }
}
