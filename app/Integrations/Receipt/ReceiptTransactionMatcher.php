<?php

namespace App\Integrations\Receipt;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Relationship;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ReceiptTransactionMatcher
{
    /**
     * Find candidate transaction matches for a receipt event
     */
    public function findCandidateMatches(Event $receiptEvent): Collection
    {
        $hints = $receiptEvent->event_metadata['matching_hints'] ?? null;

        if (! $hints || ! isset($hints['suggested_amount'])) {
            Log::warning('Receipt: No matching hints available', [
                'receipt_id' => $receiptEvent->id,
            ]);

            return collect();
        }

        $startTime = Carbon::parse($hints['suggested_date_range']['start'])->subHours(2);
        $endTime = Carbon::parse($hints['suggested_date_range']['end'])->addHours(2);

        Log::info('Receipt: Searching for transaction matches', [
            'receipt_id' => $receiptEvent->id,
            'amount' => $hints['suggested_amount'],
            'time_range' => [$startTime->toIso8601String(), $endTime->toIso8601String()],
        ]);

        // Query BOTH Monzo and GoCardless transactions
        $candidates = Event::whereIn('service', ['monzo', 'gocardless'])
            ->where('domain', 'money')
            ->where(function ($query) {
                // Monzo payment actions
                $query->whereIn('action', [
                    'card_payment_to',
                    'pot_transfer_to',
                    'card_refund_from',
                    'salary_received_from',
                ])
                    // GoCardless payment actions
                    ->orWhereIn('action', [
                        'payment_to',
                        'payment_from',
                        'made_transaction',
                    ]);
            })
            ->whereBetween('time', [$startTime, $endTime])
            ->where(function ($q) use ($hints) {
                // Match exact amount OR within 5% variance
                // Cast to int because the value column is bigint in PostgreSQL
                $amount = (int) $hints['suggested_amount'];
                $tolerance = (int) ($amount * 0.05);
                $q->whereBetween('value', [
                    max(0, $amount - $tolerance),
                    $amount + $tolerance,
                ]);
            })
            ->with(['target', 'integration'])
            ->get()
            ->map(function ($txn) use ($receiptEvent) {
                return [
                    'transaction' => $txn,
                    'confidence' => $this->calculateMatchConfidence($receiptEvent, $txn),
                    'source' => $txn->service,
                ];
            })
            ->filter(fn ($m) => $m['confidence'] > 0.5)
            ->sortByDesc('confidence');

        Log::info('Receipt: Found transaction candidates', [
            'receipt_id' => $receiptEvent->id,
            'candidate_count' => $candidates->count(),
            'top_confidence' => $candidates->first()['confidence'] ?? null,
        ]);

        return $candidates;
    }

    /**
     * Create a receipt_for relationship between receipt and transaction
     */
    public function createReceiptRelationship(
        Event $receipt,
        Event $transaction,
        float $confidence,
        string $method
    ): Relationship {
        $relationship = Relationship::findOrCreateRelationship(
            // Lookup attributes (used for finding existing relationship)
            [
                'user_id' => $receipt->integration->user_id,
                'from_type' => Event::class,
                'from_id' => $receipt->id,
                'to_type' => Event::class,
                'to_id' => $transaction->id,
                'type' => 'receipt_for',
            ],
            // Values to set when creating (not used for lookup to avoid JSON comparison)
            [
                'value' => $receipt->value,
                'value_multiplier' => 100,
                'value_unit' => $receipt->value_unit ?? 'GBP',
                'metadata' => [
                    'match_confidence' => $confidence,
                    'match_method' => $method, // 'automatic' or 'manual'
                    'matched_at' => now()->toIso8601String(),
                ],
            ]
        );

        // Update receipt merchant EventObject metadata
        $receiptObject = $receipt->target;
        if ($receiptObject) {
            $metadata = $receiptObject->metadata ?? [];
            $metadata['is_matched'] = true;
            $metadata['matched_at'] = now()->toIso8601String();
            $metadata['matched_transaction_id'] = $transaction->id;
            $metadata['match_confidence'] = $confidence;
            $metadata['match_method'] = $method;
            $receiptObject->update(['metadata' => $metadata]);
        }

        Log::info('Receipt: Created receipt_for relationship', [
            'receipt_id' => $receipt->id,
            'transaction_id' => $transaction->id,
            'confidence' => $confidence,
            'method' => $method,
        ]);

        return $relationship;
    }

    /**
     * Flag a receipt for manual review with candidate matches
     */
    public function flagForReview(Event $receipt, Collection $candidates): void
    {
        $receiptObject = $receipt->target;
        if (! $receiptObject) {
            return;
        }

        $metadata = $receiptObject->metadata ?? [];
        $metadata['is_matched'] = false;
        $metadata['needs_review'] = true;
        $metadata['candidate_matches'] = $candidates->map(function ($m) {
            return [
                'transaction_id' => $m['transaction']->id,
                'confidence' => $m['confidence'],
                'source' => $m['source'],
                'merchant' => $m['transaction']->target->title ?? null,
                'amount' => $m['transaction']->value,
                'time' => $m['transaction']->time->toIso8601String(),
            ];
        })->toArray();

        $receiptObject->update(['metadata' => $metadata]);

        Log::info('Receipt: Flagged for manual review', [
            'receipt_id' => $receipt->id,
            'candidate_count' => $candidates->count(),
        ]);
    }

    /**
     * Calculate match confidence between receipt and transaction
     */
    private function calculateMatchConfidence(Event $receipt, Event $transaction): float
    {
        $score = 0.0;

        // Amount match (40% weight) - with currency conversion
        $receiptCurrency = strtoupper($receipt->value_unit ?? 'GBP');
        $txnCurrency = strtoupper($transaction->value_unit ?? 'GBP');
        $receiptAmount = $receipt->value;
        $txnAmount = $transaction->value;

        // Try to match amounts intelligently with currency conversion
        [$amountDiff, $matchedInterpretation] = $this->calculateAmountDifference(
            $receiptAmount,
            $receiptCurrency,
            $txnAmount,
            $txnCurrency,
            $receipt,
            $transaction
        );

        $baseAmount = max(1, abs($receiptAmount));
        $amountScore = 1 - min(1, $amountDiff / $baseAmount);

        // Apply currency tolerance
        $currencyTolerance = config('services.receipt.currency_tolerance_percent', 2.0) / 100;
        if ($receiptCurrency !== $txnCurrency && $amountScore > (1 - $currencyTolerance)) {
            // Give slight boost for cross-currency matches within tolerance
            $amountScore = min(1.0, $amountScore + 0.05);
        }

        $score += $amountScore * 0.4;

        // Time proximity (20% weight)
        $timeDiff = abs($receipt->time->diffInMinutes($transaction->time));
        $timeScore = max(0, 1 - ($timeDiff / 120)); // 2-hour window
        $score += $timeScore * 0.2;

        // Merchant name fuzzy match (30% weight)
        $receiptMerchant = $receipt->target->metadata['normalized_name'] ?? strtolower($receipt->target->title ?? '');
        $txnMerchant = strtolower($transaction->target->title ?? '');
        $merchantScore = $this->fuzzyMatch($receiptMerchant, $txnMerchant);
        $score += $merchantScore * 0.3;

        // Card hint match (10% weight)
        $hints = $receipt->event_metadata['matching_hints'] ?? [];
        $cardHint = $hints['card_hint'] ?? null;
        if ($cardHint) {
            // Check if transaction metadata contains card info
            $txnMetadata = $transaction->event_metadata ?? [];
            $txnCardLast4 = $txnMetadata['card_last_4'] ?? null;

            if ($txnCardLast4 && $txnCardLast4 === $cardHint) {
                $score += 0.1;
            }
        }

        Log::debug('Receipt: Calculated match confidence', [
            'receipt_id' => $receipt->id,
            'transaction_id' => $transaction->id,
            'receipt_currency' => $receiptCurrency,
            'transaction_currency' => $txnCurrency,
            'matched_interpretation' => $matchedInterpretation,
            'confidence' => $score,
            'amount_score' => $amountScore * 0.4,
            'time_score' => $timeScore * 0.2,
            'merchant_score' => $merchantScore * 0.3,
        ]);

        return $score;
    }

    /**
     * Calculate amount difference with currency conversion support.
     * Handles multiple scenarios including Monzo local_currency and ambiguous receipts.
     *
     * @return array [amountDiff, matchedInterpretation]
     */
    private function calculateAmountDifference(
        int $receiptAmount,
        string $receiptCurrency,
        int $txnAmount,
        string $txnCurrency,
        Event $receipt,
        Event $transaction
    ): array {
        $conversionService = app(\App\Services\CurrencyConversionService::class);

        // Scenario 1: Check if receipt has pre-computed GBP conversion in metadata
        $receiptMetadata = $receipt->event_metadata ?? [];
        $preConvertedGbp = $receiptMetadata['currency_conversion']['converted_to_gbp'] ?? null;

        if ($preConvertedGbp !== null && $txnCurrency === 'GBP') {
            // Use pre-converted amount
            $amountDiff = abs($preConvertedGbp - $txnAmount);

            return [$amountDiff, 'preconverted_to_gbp'];
        }

        // Scenario 2: Check if transaction has Monzo local_currency matching receipt
        $txnMetadata = $transaction->event_metadata ?? [];
        $txnLocalCurrency = strtoupper($txnMetadata['local_currency'] ?? '');
        $txnLocalAmount = abs((int) ($txnMetadata['local_amount'] ?? 0));

        if ($txnLocalCurrency === $receiptCurrency && $txnLocalAmount > 0) {
            // Perfect match: receipt currency matches Monzo's foreign currency
            $amountDiff = abs($receiptAmount - $txnLocalAmount);

            return [$amountDiff, 'monzo_local_currency'];
        }

        // Scenario 3: Direct match - same currency
        if ($receiptCurrency === $txnCurrency) {
            $amountDiff = abs($receiptAmount - $txnAmount);

            return [$amountDiff, 'same_currency'];
        }

        // Scenario 4: Currency conversion needed - try two interpretations
        try {
            // Primary interpretation: use stated receipt currency
            $convertedPrimary = $conversionService->convert(
                $receiptAmount,
                $receiptCurrency,
                $txnCurrency,
                $receipt->time
            );
            $diffPrimary = abs($convertedPrimary - $txnAmount);

            // Fallback interpretation: assume receipt is actually GBP (misidentified)
            $convertedFallback = $conversionService->convert(
                $receiptAmount,
                'GBP',
                $txnCurrency,
                $receipt->time
            );
            $diffFallback = abs($convertedFallback - $txnAmount);

            // Use whichever interpretation gives better match
            if ($diffFallback < $diffPrimary && $receiptCurrency !== 'GBP') {
                Log::info('Receipt: Using fallback GBP interpretation', [
                    'receipt_id' => $receipt->id,
                    'stated_currency' => $receiptCurrency,
                    'primary_diff' => $diffPrimary,
                    'fallback_diff' => $diffFallback,
                ]);

                return [$diffFallback, 'fallback_gbp'];
            }

            return [$diffPrimary, 'converted_' . strtolower($receiptCurrency) . '_to_' . strtolower($txnCurrency)];
        } catch (Exception $e) {
            // Conversion failed - fall back to direct comparison
            Log::warning('Receipt: Currency conversion failed in matching', [
                'receipt_id' => $receipt->id,
                'transaction_id' => $transaction->id,
                'receipt_currency' => $receiptCurrency,
                'transaction_currency' => $txnCurrency,
                'error' => $e->getMessage(),
            ]);

            $amountDiff = abs($receiptAmount - $txnAmount);

            return [$amountDiff, 'direct_fallback'];
        }
    }

    /**
     * Fuzzy string matching using similar_text
     */
    private function fuzzyMatch(string $a, string $b): float
    {
        if (empty($a) || empty($b)) {
            return 0.0;
        }

        similar_text($a, $b, $percent);

        return $percent / 100;
    }
}
