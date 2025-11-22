<?php

namespace App\Integrations\Receipt;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Relationship;
use Carbon\Carbon;
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
                $tolerance = $hints['suggested_amount'] * 0.05;
                $q->whereBetween('value', [
                    max(0, $hints['suggested_amount'] - $tolerance),
                    $hints['suggested_amount'] + $tolerance,
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
     * Calculate match confidence between receipt and transaction
     */
    private function calculateMatchConfidence(Event $receipt, Event $transaction): float
    {
        $score = 0.0;

        // Amount match (40% weight)
        $amountDiff = abs($receipt->value - $transaction->value);
        $amountScore = 1 - min(1, $amountDiff / max(1, $receipt->value));
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
            'confidence' => $score,
            'amount_score' => $amountScore * 0.4,
            'time_score' => $timeScore * 0.2,
            'merchant_score' => $merchantScore * 0.3,
        ]);

        return $score;
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
}
