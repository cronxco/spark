<?php

namespace App\Integrations\Receipt;

use App\Models\Event;
use App\Models\Relationship;
use App\Services\CurrencyConversionService;
use App\Services\Receipt\ReceiptMatchState;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ReceiptTransactionMatcher
{
    public const TRANSACTION_ACTIONS = [
        'card_payment_to', 'pot_transfer_to', 'card_refund_from', 'salary_received_from',
        'payment_to', 'payment_from', 'made_transaction',
    ];

    /**
     * Find candidate transaction matches for a receipt event
     */
    public function findCandidateMatches(Event $receiptEvent): Collection
    {
        $hints = $receiptEvent->event_metadata['matching_hints'] ?? null;
        $hasHints = is_array($hints)
            && isset($hints['suggested_amount'])
            && ! empty($hints['suggested_date_range']['start'])
            && ! empty($hints['suggested_date_range']['end']);
        $hints = $hasHints ? $hints : [
            'suggested_amount' => $receiptEvent->value,
            'suggested_date_range' => [
                'start' => $receiptEvent->time?->copy()->subHours(4)->toIso8601String(),
                'end' => $receiptEvent->time?->copy()->addHours(4)->toIso8601String(),
            ],
        ];
        if (! $receiptEvent->time || ! is_numeric($hints['suggested_amount'] ?? null)) {
            return collect();
        }

        $startTime = Carbon::parse($hints['suggested_date_range']['start'])->subHours(2);
        $endTime = Carbon::parse($hints['suggested_date_range']['end'])->addHours(2);

        Log::info('Receipt: Searching for transaction matches', [
            'receipt_id' => $receiptEvent->id,
            'amount' => $hints['suggested_amount'],
            'time_range' => [$startTime->toIso8601String(), $endTime->toIso8601String()],
        ]);

        // Query BOTH Monzo and GoCardless transactions, restricted to the user
        // who owns the receipt. Without this a receipt can be matched to — and
        // have a relationship written against — another tenant's transaction.
        $ownerId = $receiptEvent->integration?->user_id;

        if ($ownerId === null) {
            Log::warning('Receipt: Cannot resolve owning user for receipt', [
                'receipt_id' => $receiptEvent->id,
            ]);

            return collect();
        }

        $candidates = Event::forUser($ownerId)
            ->whereIn('service', ['monzo', 'gocardless'])
            ->where('domain', 'money')
            ->whereIn('action', self::TRANSACTION_ACTIONS)
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
            ->map(function ($txn) use ($receiptEvent, $hasHints) {
                return [
                    'transaction' => $txn,
                    // Event-only fallback can suggest a pair, but never silently auto-link it.
                    'confidence' => $hasHints
                        ? $this->calculateMatchConfidence($receiptEvent, $txn)
                        : min(0.79, $this->calculateReverseMatchConfidence($receiptEvent, $txn)),
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
     * Confidence that an unmatched receipt belongs to a newly arrived
     * transaction: amount 40%, time within four hours 30%, merchant name 30%.
     */
    public function calculateReverseMatchConfidence(Event $receipt, Event $transaction): float
    {
        $score = 0.0;

        $amountDiff = abs($receipt->value - $transaction->value);
        $amountScore = 1 - min(1, $amountDiff / max(1, $receipt->value));
        $score += $amountScore * 0.4;

        $timeDiff = abs($receipt->time->diffInMinutes($transaction->time));
        $timeScore = max(0, 1 - ($timeDiff / 240));
        $score += $timeScore * 0.3;

        $score += $this->merchantMatchScore($receipt, $transaction) * 0.3;

        return $score;
    }

    /**
     * Create a receipt_for relationship between receipt and transaction
     */
    public function createReceiptRelationship(
        Event $receipt,
        Event $transaction,
        ?float $confidence,
        string $method
    ): Relationship {
        $ownerId = $receipt->integration?->user_id;

        if ($ownerId === null || $ownerId !== $transaction->integration?->user_id) {
            throw new InvalidArgumentException('A receipt can only be linked to a transaction with the same owner.');
        }
        if ($receipt->service !== 'receipt' || $receipt->action !== 'had_receipt_from'
            || ! in_array($transaction->service, ['monzo', 'gocardless'], true)
            || $transaction->domain !== 'money'
            || ! in_array($transaction->action, self::TRANSACTION_ACTIONS, true)) {
            throw new InvalidArgumentException('Choose an eligible receipt and bank transaction.');
        }

        return DB::transaction(function () use ($receipt, $transaction, $confidence, $method, $ownerId): Relationship {
            // All receipt link writes take the same row lock. This prevents two
            // application workers from selecting different transactions at once.
            Event::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $existing = ReceiptMatchState::links()->where('from_id', $receipt->id)->first();
            if ($existing) {
                if ($existing->to_id === $transaction->id) {
                    return $existing;
                }
                throw new InvalidArgumentException('This receipt is already linked to another transaction.');
            }

            $relationship = Relationship::findOrCreateRelationship(
                // Lookup attributes (used for finding existing relationship)
                [
                    'user_id' => $ownerId,
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
                        ...($method === 'automatic' ? ['match_confidence' => $confidence] : []),
                        ...($method === 'manual' && $confidence !== null ? ['proposal_confidence' => $confidence] : []),
                        'match_method' => $method, // 'automatic' or 'manual'
                        'matched_at' => now()->toIso8601String(),
                    ],
                ]
            );
            ReceiptMatchState::update($receipt, [
                'status' => 'matched',
                'candidates' => [],
                'reason' => null,
                'matched_at' => now()->toIso8601String(),
            ]);

            Log::info('Receipt: Created receipt_for relationship', [
                'receipt_id' => $receipt->id,
                'transaction_id' => $transaction->id,
                'confidence' => $confidence,
                'method' => $method,
            ]);

            return $relationship;
        });
    }

    /**
     * Flag a receipt for manual review with candidate matches
     */
    public function flagForReview(Event $receipt, Collection $candidates): void
    {
        DB::transaction(function () use ($receipt, $candidates): void {
            Event::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if (ReceiptMatchState::isMatched($receipt)) {
                return;
            }
            ReceiptMatchState::update($receipt, [
                'status' => 'suggestions',
                'reason' => null,
                'attempted_at' => now()->toIso8601String(),
                'algorithm_version' => 1,
                'candidates' => $candidates->map(function ($m) {
                    return [
                        'transaction_id' => $m['transaction']->id,
                        'confidence' => $m['confidence'],
                        'source' => $m['source'],
                        'merchant' => $m['transaction']->target->title ?? null,
                        'amount' => $m['transaction']->value,
                        'time' => $m['transaction']->time->toIso8601String(),
                    ];
                })->values()->toArray(),
            ]);
        });

        Log::info('Receipt: Flagged for manual review', [
            'receipt_id' => $receipt->id,
            'candidate_count' => $candidates->count(),
        ]);
    }

    /** Run the same receipt decision path for intake, a late transaction, or a retry. */
    public function matchReceipt(Event $receipt, ?Event $incomingTransaction = null, bool $allowAutomatic = true): string
    {
        return DB::transaction(function () use ($receipt, $incomingTransaction, $allowAutomatic): string {
            $locked = Event::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            return $this->matchLockedReceipt($locked, $incomingTransaction, $allowAutomatic);
        });
    }

    private function matchLockedReceipt(Event $receipt, ?Event $incomingTransaction, bool $allowAutomatic): string
    {
        if (ReceiptMatchState::isMatched($receipt)) {
            return 'already_matched';
        }
        $userRejected = in_array(ReceiptMatchState::status($receipt), ['dismissed', 'no_match'], true);
        if ($userRejected && ! $incomingTransaction) {
            return ReceiptMatchState::status($receipt);
        }
        if ($userRejected) {
            $allowAutomatic = false;
        }

        $candidates = $userRejected ? collect() : $this->findCandidateMatches($receipt);
        if ($incomingTransaction && $receipt->time && $incomingTransaction->time
            && in_array($incomingTransaction->service, ['monzo', 'gocardless'], true)
            && $incomingTransaction->domain === 'money'
            && $incomingTransaction->integration?->user_id === $receipt->integration?->user_id
            && in_array($incomingTransaction->action, self::TRANSACTION_ACTIONS, true)) {
            $score = $this->calculateReverseMatchConfidence($receipt, $incomingTransaction);
            if ($score > config('services.receipt.review_threshold', 0.5)
                && ! $candidates->contains(fn (array $candidate) => $candidate['transaction']->id === $incomingTransaction->id)) {
                $candidates->push([
                    'transaction' => $incomingTransaction,
                    'confidence' => $score,
                    'source' => $incomingTransaction->service,
                ]);
            }
        }
        $candidates = $candidates->sortByDesc('confidence')->values();
        if ($userRejected && $candidates->isEmpty()) {
            return ReceiptMatchState::status($receipt);
        }

        if ($candidates->isEmpty()) {
            $hasAmount = is_numeric($receipt->value) && $receipt->value > 0;
            $status = $hasAmount && $receipt->time ? 'no_candidate' : 'needs_details';
            $alreadyMatched = DB::transaction(function () use ($receipt, $status): bool {
                Event::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
                if (ReceiptMatchState::isMatched($receipt)) {
                    return true;
                }
                ReceiptMatchState::update($receipt, [
                    'status' => $status,
                    'reason' => $status === 'needs_details' ? 'missing_amount_or_time' : 'no_eligible_transaction',
                    'attempted_at' => now()->toIso8601String(),
                    'algorithm_version' => 1,
                    'candidates' => [],
                ]);

                return false;
            });

            return $alreadyMatched ? 'already_matched' : $status;
        }

        $best = $candidates->first();
        if ($allowAutomatic && $best['confidence'] >= config('services.receipt.auto_match_threshold', 0.8)) {
            try {
                $this->createReceiptRelationship($receipt, $best['transaction'], $best['confidence'], 'automatic');
            } catch (InvalidArgumentException $exception) {
                if (ReceiptMatchState::isMatched($receipt)) {
                    return 'already_matched';
                }
                throw $exception;
            }

            return 'matched';
        }

        $this->flagForReview($receipt, $candidates->take(3));

        return ReceiptMatchState::isMatched($receipt) ? 'already_matched' : 'review_required';
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
        $merchantScore = $this->merchantMatchScore($receipt, $transaction);
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
        $conversionService = app(CurrencyConversionService::class);

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

    private function merchantMatchScore(Event $receipt, Event $transaction): float
    {
        $aliases = $receipt->event_metadata['matching_hints']['suggested_merchant_names'] ?? [];
        $names = is_array($aliases) ? $aliases : [];
        $names[] = $receipt->target->title ?? '';
        $names[] = $receipt->target->metadata['normalized_name'] ?? '';
        $transactionName = strtolower(trim($transaction->target->title ?? ''));
        $score = 0.0;
        foreach ($names as $name) {
            if (is_string($name) && trim($name) !== '') {
                $score = max($score, $this->fuzzyMatch(strtolower(trim($name)), $transactionName));
            }
        }

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
}

