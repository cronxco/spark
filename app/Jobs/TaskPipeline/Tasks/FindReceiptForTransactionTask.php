<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;

class FindReceiptForTransactionTask extends BaseTaskJob
{
    /**
     * Execute the receipt finding task (reverse: transaction → receipt)
     */
    protected function execute(): void
    {
        // TODO: Implement transaction to receipt matching
        // This task finds matching receipts for a transaction

        // Example implementation:
        // $matcher = app(ReceiptTransactionMatcher::class);
        //
        // // Search for receipts within ±4 hours
        // $startTime = $this->model->time->copy()->subHours(4);
        // $endTime = $this->model->time->copy()->addHours(4);
        //
        // $receipts = Event::where('user_id', $this->model->user_id)
        //     ->where('service', 'receipt')
        //     ->where('action', 'had_receipt_from')
        //     ->whereBetween('time', [$startTime, $endTime])
        //     ->whereNull('linked_transaction_id')
        //     ->get();
        //
        // $match = $matcher->findBestMatch($this->model, $receipts);
        //
        // if ($match && $match['confidence'] >= config('services.receipt.auto_match_threshold', 0.8)) {
        //     // Auto-link transaction to receipt
        //     $match['receipt']->update([
        //         'linked_transaction_id' => $this->model->id,
        //         'match_confidence' => $match['confidence'],
        //     ]);
        // } elseif ($match && $match['confidence'] >= config('services.receipt.review_threshold', 0.5)) {
        //     // Flag for manual review
        //     $match['receipt']->update([
        //         'suggested_transaction_id' => $this->model->id,
        //         'match_confidence' => $match['confidence'],
        //         'needs_review' => true,
        //     ]);
        // }
    }
}
