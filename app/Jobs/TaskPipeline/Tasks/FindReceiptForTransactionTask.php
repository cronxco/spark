<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Integrations\Receipt\ReceiptTransactionMatcher;
use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Models\Event;
use Illuminate\Support\Facades\Log;

class FindReceiptForTransactionTask extends BaseTaskJob
{
    /**
     * Execute the find-receipt-for-transaction task
     */
    protected function execute(): void
    {
        Log::info('Receipt: Searching for receipt for transaction via TaskPipeline', [
            'transaction_id' => $this->model->id,
            'service' => $this->model->service,
            'amount' => $this->model->value,
        ]);

        // Find unmatched receipt events within ±4 hours of transaction
        $startTime = $this->model->time->copy()->subHours(4);
        $endTime = $this->model->time->copy()->addHours(4);

        $unmatchedReceipts = Event::where('service', 'receipt')
            ->where('domain', 'money')
            ->where('action', 'had_receipt_from')
            ->whereBetween('time', [$startTime, $endTime])
            ->where(function ($query) {
                // Amount within ±10% of transaction
                $tolerance = $this->model->value * 0.1;
                $query->whereBetween('value', [
                    max(0, $this->model->value - $tolerance),
                    $this->model->value + $tolerance,
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
                'transaction_id' => $this->model->id,
            ]);

            return;
        }

        Log::info('Receipt: Found unmatched receipts', [
            'transaction_id' => $this->model->id,
            'receipt_count' => $unmatchedReceipts->count(),
        ]);

        $matcher = new ReceiptTransactionMatcher;
        $autoMatchThreshold = config('services.receipt.auto_match_threshold', 0.8);

        // Try to match each receipt
        foreach ($unmatchedReceipts as $receipt) {
            $confidence = $this->calculateReverseMatchConfidence($receipt, $this->model);

            if ($confidence >= $autoMatchThreshold) {
                $matcher->createReceiptRelationship(
                    $receipt,
                    $this->model,
                    $confidence,
                    'automatic'
                );

                Log::info('Receipt: Reverse matched receipt to transaction via TaskPipeline', [
                    'receipt_id' => $receipt->id,
                    'transaction_id' => $this->model->id,
                    'confidence' => $confidence,
                ]);

                // Only match one receipt per transaction
                break;
            }
        }
    }

    /**
     * Calculate confidence score for reverse matching
     */
    protected function calculateReverseMatchConfidence(Event $receipt, Event $transaction): float
    {
        $confidence = 0.0;

        // Time proximity (max 0.3)
        $timeDiff = abs($receipt->time->diffInMinutes($transaction->time));
        if ($timeDiff <= 60) {
            $confidence += 0.3 * (1 - ($timeDiff / 60));
        }

        // Amount match (max 0.4)
        $amountDiff = abs($receipt->value - $transaction->value) / max($receipt->value, $transaction->value);
        if ($amountDiff <= 0.05) {
            $confidence += 0.4;
        } elseif ($amountDiff <= 0.10) {
            $confidence += 0.3;
        }

        // Merchant name similarity would go here (max 0.3)
        // For now, add a base score if receipt has merchant info
        if ($receipt->target && $receipt->target->title) {
            $confidence += 0.2;
        }

        return min($confidence, 1.0);
    }
}
