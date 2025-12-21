<?php

namespace App\Integrations\Receipt;

use Exception;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class ReceiptExtractor
{
    /**
     * Extract structured receipt data from raw text using GPT-5
     */
    public function extract(string $receiptText, string $emailSubject = '', string $emailFrom = ''): array
    {
        $schemaExample = $this->getSchemaExample();

        $systemPrompt = <<<'PROMPT'
You are an expert receipt data extractor. Extract structured information from receipt text.

FIRST: Determine if this is actually a receipt/invoice/order confirmation.
If NOT a receipt (e.g., newsletter, marketing email, general correspondence, account statement), return:
{
  "is_valid_receipt": false,
  "rejection_reason": "Brief explanation of why this is not a receipt"
}

If it IS a valid receipt, extract the data with is_valid_receipt: true.

CRITICAL REQUIREMENTS:
1. ALL monetary amounts must be in smallest currency unit (pence for GBP, cents for USD, etc.)
2. Parse dates as ISO 8601 (YYYY-MM-DDTHH:mm:ssZ)
3. If receipt is not in English, extract data AND translate all text to English
4. confidence_score: 0.0-1.0 (only >0.8 if all key fields clearly visible)
5. Infer merchant name even if partially visible or abbreviated
6. Extract line items with exact descriptions as shown
7. For refunds, use negative amounts
8. Guess currency from context (£/GBP, €/EUR, $/USD, etc.)

Valid receipts include:
- Purchase receipts (retail, restaurant, etc.)
- Invoices
- Order confirmations with amounts
- Payment confirmations
- Refund confirmations

NOT valid receipts:
- Marketing emails
- Newsletters
- Account statements without specific transactions
- Shipping notifications without payment details
- Password resets, account alerts, etc.

The receipt may come from various sources:
- Email body (HTML or plain text)
- PDF attachment text
- Image OCR text (may have errors)

Be flexible with formatting and extract what you can.

Return JSON matching this schema:
SCHEMA_PLACEHOLDER
PROMPT;

        $systemPrompt = str_replace('SCHEMA_PLACEHOLDER', $schemaExample, $systemPrompt);

        $userPrompt = "Email Subject: {$emailSubject}\nEmail From: {$emailFrom}\n\nReceipt Text:\n{$receiptText}";

        try {
            Log::info('Receipt: Extracting receipt data with GPT-5', [
                'text_length' => strlen($receiptText),
                'subject' => $emailSubject,
                'from' => $emailFrom,
            ]);

            // Start Sentry AI request span
            $model = 'gpt-5-nano';
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ];
            $aiSpan = start_ai_request_span($model, $messages, [
                'temperature' => 1,
            ]);

            $response = OpenAI::chat()->create([
                'model' => $model,
                'temperature' => 1,
                'messages' => $messages,
                'response_format' => ['type' => 'json_object'],
            ]);

            // Finish AI request span with token usage
            $usage = $response->usage ? $response->usage->toArray() : [];
            $finishReason = $response->choices[0]->finishReason ?? null;
            finish_ai_request_span($aiSpan, $usage, $finishReason);

            $extracted = json_decode($response->choices[0]->message->content, true);

            if (! $extracted) {
                throw new Exception('Invalid response from GPT-5: could not parse JSON');
            }

            // Check if this was identified as not a valid receipt
            if (isset($extracted['is_valid_receipt']) && $extracted['is_valid_receipt'] === false) {
                Log::info('Receipt: Email identified as not a valid receipt', [
                    'subject' => $emailSubject,
                    'from' => $emailFrom,
                    'rejection_reason' => $extracted['rejection_reason'] ?? 'unknown',
                ]);

                return $extracted;
            }

            // Validate required fields for valid receipts
            if (! isset($extracted['transaction_summary'])) {
                throw new Exception('Invalid response from GPT-5: missing required fields');
            }

            // Ensure is_valid_receipt is set
            $extracted['is_valid_receipt'] = true;

            Log::info('Receipt: Successfully extracted receipt data', [
                'merchant' => $extracted['merchant']['name'] ?? 'unknown',
                'total' => $extracted['transaction_summary']['total_amount'] ?? 0,
                'currency' => $extracted['transaction_summary']['currency'] ?? 'unknown',
                'confidence' => $extracted['receipt_metadata']['confidence_score'] ?? 0,
                'line_items_count' => count($extracted['line_items'] ?? []),
            ]);

            return $extracted;
        } catch (Exception $e) {
            Log::error('Receipt: GPT-5 extraction failed', [
                'error' => $e->getMessage(),
                'text_preview' => substr($receiptText, 0, 500),
            ]);

            throw $e;
        }
    }

    /**
     * Get the JSON schema example for the prompt
     */
    private function getSchemaExample(): string
    {
        return json_encode([
            'is_valid_receipt' => true,
            'receipt_metadata' => [
                'email_subject' => 'string',
                'email_from' => 'string',
                'email_received_at' => '2025-01-15T14:32:00Z',
                'confidence_score' => 0.95,
                'extraction_model' => 'gpt-5-nano',
                'raw_text_preview' => 'string (first 200 chars)',
            ],
            'merchant' => [
                'name' => 'Tesco Extra',
                'address' => '123 High Street, London, SW1A 1AA',
                'phone' => '+44 20 1234 5678',
                'tax_id' => 'GB123456789',
                'merchant_id' => 'string or null',
            ],
            'transaction_summary' => [
                'total_amount' => 4523,
                'currency' => 'GBP',
                'subtotal' => 3950,
                'tax_total' => 573,
                'tax_rate' => 0.20,
                'discount_total' => 0,
                'tip_amount' => 0,
            ],
            'transaction_metadata' => [
                'transaction_date' => '2025-01-15T14:30:00Z',
                'receipt_number' => 'R-2025-0123456',
                'terminal_id' => 'T-005 or null',
                'payment_method' => 'card',
                'card_last_4' => '1234 or null',
            ],
            'line_items' => [
                [
                    'sequence' => 1,
                    'description' => 'Organic Bananas',
                    'quantity' => 1.5,
                    'unit' => 'kg',
                    'unit_price' => 180,
                    'total_price' => 270,
                    'category' => 'groceries',
                    'sku' => 'BAN-ORG-001 or null',
                    'tax_rate' => 0.0,
                ],
            ],
            'matching_hints' => [
                'suggested_amount' => 4523,
                'suggested_date_range' => [
                    'start' => '2025-01-15T12:30:00Z',
                    'end' => '2025-01-15T16:30:00Z',
                ],
                'suggested_merchant_names' => ['Tesco', 'Tesco Extra', 'TESCO'],
                'card_hint' => '1234 or null',
            ],
        ], JSON_PRETTY_PRINT);
    }
}
