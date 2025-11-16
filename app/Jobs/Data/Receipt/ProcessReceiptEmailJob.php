<?php

namespace App\Jobs\Data\Receipt;

use App\Integrations\Receipt\ReceiptExtractor;
use App\Jobs\Concerns\EnhancedIdempotency;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessReceiptEmailJob implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes for email processing + AI

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function __construct(
        public Integration $integration,
        public string $s3ObjectKey
    ) {}

    public function handle(): void
    {
        Log::info('Receipt: Processing receipt email from S3', [
            'integration_id' => $this->integration->id,
            's3_object_key' => $this->s3ObjectKey,
        ]);

        try {
            // Download email from S3
            $emailContent = $this->downloadEmailFromS3($this->s3ObjectKey);

            // Parse email to extract text
            $parsedEmail = $this->parseEmail($emailContent);

            // Extract receipt data using OpenAI
            $extractor = new ReceiptExtractor();
            $receiptData = $extractor->extract(
                $parsedEmail['combined_text'],
                $parsedEmail['subject'],
                $parsedEmail['from']
            );

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
        return 'process_receipt_email_' . $this->integration->id . '_' . md5($this->s3ObjectKey);
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
     * This uses php-mime-mail-parser to parse the MIME structure
     */
    private function parseEmail(string $emailContent): array
    {
        try {
            // Use PhpMimeMailParser to parse the email
            $parser = new \PhpMimeMailParser\Parser();
            $parser->setText($emailContent);

            // Extract basic fields
            $subject = $parser->getHeader('subject') ?: 'No Subject';
            $from = $parser->getHeader('from') ?: '';
            $date = $parser->getHeader('date') ?: now()->toRfc2822String();

            // Extract text content
            $textPlain = $parser->getMessageBody('text') ?: '';
            $textHtml = $parser->getMessageBody('html') ?: '';

            // Convert HTML to plain text if needed
            if ($textHtml && ! $textPlain) {
                $textPlain = $this->htmlToText($textHtml);
            }

            // Extract PDF attachments and parse them
            $pdfText = '';
            $attachments = $parser->getAttachments();
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
            $converter = new \Html2Text\Html2Text($html);

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
            $parser = new \Smalot\PdfParser\Parser();
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

        // Create line item blocks
        foreach ($receiptData['line_items'] ?? [] as $item) {
            $event->createBlock([
                'time' => $transactionTime,
                'block_type' => 'receipt_line_item',
                'title' => $item['description'],
                'value' => $item['total_price'],
                'value_multiplier' => 100,
                'value_unit' => $receiptData['transaction_summary']['currency'],
                'metadata' => [
                    'sequence' => $item['sequence'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'unit_price' => $item['unit_price'],
                    'category' => $item['category'] ?? null,
                    'sku' => $item['sku'] ?? null,
                    'tax_rate' => $item['tax_rate'],
                ],
            ]);
        }

        // Create tax summary block
        $event->createBlock([
            'time' => $transactionTime,
            'block_type' => 'receipt_tax_summary',
            'title' => 'Tax Summary',
            'value' => $receiptData['transaction_summary']['tax_total'],
            'value_multiplier' => 100,
            'value_unit' => $receiptData['transaction_summary']['currency'],
            'metadata' => [
                'tax_rate' => $receiptData['transaction_summary']['tax_rate'],
                'subtotal' => $receiptData['transaction_summary']['subtotal'],
                'discount_total' => $receiptData['transaction_summary']['discount_total'] ?? 0,
                'tip_amount' => $receiptData['transaction_summary']['tip_amount'] ?? 0,
            ],
        ]);

        // Create payment method block
        $event->createBlock([
            'time' => $transactionTime,
            'block_type' => 'receipt_payment_method',
            'title' => 'Payment Method',
            'metadata' => [
                'payment_method' => $receiptData['transaction_metadata']['payment_method'] ?? 'unknown',
                'card_last_4' => $receiptData['transaction_metadata']['card_last_4'] ?? null,
                'receipt_number' => $receiptData['transaction_metadata']['receipt_number'] ?? null,
                'terminal_id' => $receiptData['transaction_metadata']['terminal_id'] ?? null,
            ],
        ]);

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
