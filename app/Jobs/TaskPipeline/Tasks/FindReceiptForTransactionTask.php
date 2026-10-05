<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Integrations\Receipt\ReceiptTransactionMatcher;
use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Models\Event;
use Illuminate\Support\Facades\Log;

class FindReceiptForTransactionTask extends BaseTaskJob
{
    /**
     * Monzo and GoCardless actions a receipt can belong to.
     *
     * @var list<string>
     */
    public const TRANSACTION_ACTIONS = [
        'card_payment_to',
        'payment_to',
        'made_transaction',
        'card_refund_from',
        'payment_from',
    ];

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

        // Restrict candidates to the transaction's own owner. Without this a
        // transaction can be matched to another tenant's receipt.
        $ownerId = $this->model->integration?->user_id;

        if ($ownerId === null) {
            Log::warning('Receipt: Cannot resolve owning user for transaction', [
                'transaction_id' => $this->model->id,
            ]);

            return;
        }

        $unmatchedReceipts = Event::forUser($ownerId)
            ->where('service', 'receipt')
            ->where('domain', 'money')
            ->where('action', 'had_receipt_from')
            ->whereBetween('time', [$startTime, $endTime])
            ->where(function ($query) {
                // Amount within ±10% of transaction
                $tolerance = (int) ($this->model->value * 0.1);
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

            $this->recordOutcome('no_candidate');

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
            $confidence = $matcher->calculateReverseMatchConfidence($receipt, $this->model);

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

                $this->recordOutcome('matched');

                // Only match one receipt per transaction
                return;
            }
        }

        $this->recordOutcome('no_candidate');
    }
}
