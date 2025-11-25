<?php

namespace App\Jobs\Data\Receipt;

use App\Integrations\Receipt\ReceiptTransactionMatcher;
use App\Jobs\Concerns\EnhancedIdempotency;
use App\Models\Event;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FindReceiptForTransactionJob implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 3;

    public $backoff = [30, 120, 300];

    public function __construct(
        public Event $transactionEvent
    ) {}

    public function handle(): void
    {
        Log::info('Receipt: Searching for receipt for transaction', [
            'transaction_id' => $this->transactionEvent->id,
            'service' => $this->transactionEvent->service,
            'amount' => $this->transactionEvent->value,
        ]);

        try {
            // Find unmatched receipt events within ±4 hours of transaction
            $startTime = $this->transactionEvent->time->copy()->subHours(4);
            $endTime = $this->transactionEvent->time->copy()->addHours(4);

            $unmatchedReceipts = Event::where('service', 'receipt')
                ->where('domain', 'money')
                ->where('action', 'had_receipt_from')
                ->whereBetween('time', [$startTime, $endTime])
                ->where(function ($query) {
                    // Amount within ±10% of transaction
                    $tolerance = $this->transactionEvent->value * 0.1;
                    $query->whereBetween('value', [
                        max(0, $this->transactionEvent->value - $tolerance),
                        $this->transactionEvent->value + $tolerance,
                    ]);
                })
                ->whereHas('target', function ($query) {
                    // Only unmatched receipts
                    $query->where(function ($q) {
                        $q->whereJsonContains('metadata->is_matched', false)
                            ->orWhereNull('metadata->is_matched');
                    });
                })
                ->with(['target', 'integration'])
                ->get();

            if ($unmatchedReceipts->isEmpty()) {
                Log::info('Receipt: No unmatched receipts found for transaction', [
                    'transaction_id' => $this->transactionEvent->id,
                ]);

                return;
            }

            Log::info('Receipt: Found unmatched receipts', [
                'transaction_id' => $this->transactionEvent->id,
                'receipt_count' => $unmatchedReceipts->count(),
            ]);

            $matcher = new ReceiptTransactionMatcher;
            $autoMatchThreshold = config('services.receipt.auto_match_threshold', 0.8);

            // Try to match each receipt
            foreach ($unmatchedReceipts as $receipt) {
                $confidence = $this->calculateReverseMatchConfidence($receipt, $this->transactionEvent);

                if ($confidence >= $autoMatchThreshold) {
                    $matcher->createReceiptRelationship(
                        $receipt,
                        $this->transactionEvent,
                        $confidence,
                        'automatic'
                    );

                    Log::info('Receipt: Reverse matched receipt to transaction', [
                        'receipt_id' => $receipt->id,
                        'transaction_id' => $this->transactionEvent->id,
                        'confidence' => $confidence,
                    ]);

                    // Only match one receipt per transaction
                    break;
                }
            }
        } catch (Exception $e) {
            Log::error('Receipt: Reverse matching failed', [
                'transaction_id' => $this->transactionEvent->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function uniqueId(): string
    {
        return 'find_receipt_for_transaction_' . $this->transactionEvent->id;
    }

    /**
     * Calculate match confidence for reverse matching
     * (Similar to ReceiptTransactionMatcher but simplified)
     */
    private function calculateReverseMatchConfidence(Event $receipt, Event $transaction): float
    {
        $score = 0.0;

        // Amount match (40%)
        $amountDiff = abs($receipt->value - $transaction->value);
        $amountScore = 1 - min(1, $amountDiff / max(1, $receipt->value));
        $score += $amountScore * 0.4;

        // Time proximity (30%)
        $timeDiff = abs($receipt->time->diffInMinutes($transaction->time));
        $timeScore = max(0, 1 - ($timeDiff / 240)); // 4-hour window
        $score += $timeScore * 0.3;

        // Merchant name fuzzy match (30%)
        $receiptMerchant = strtolower($receipt->target->title ?? '');
        $txnMerchant = strtolower($transaction->target->title ?? '');
        similar_text($receiptMerchant, $txnMerchant, $percent);
        $score += ($percent / 100) * 0.3;

        return $score;
    }
}
