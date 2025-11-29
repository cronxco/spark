<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;

class MatchReceiptToTransactionTask extends BaseTaskJob
{
    /**
     * Execute the receipt matching task (forward: receipt → transaction)
     */
    protected function execute(): void
    {
        // TODO: Implement receipt to transaction matching
        // This task finds matching transactions for a receipt

        // Example implementation:
        // $matcher = app(ReceiptTransactionMatcher::class);
        //
        // // Search for transactions within ±4 hours
        // $startTime = $this->model->time->copy()->subHours(4);
        // $endTime = $this->model->time->copy()->addHours(4);
        //
        // $transactions = Event::where('user_id', $this->model->user_id)
        //     ->whereIn('service', ['monzo', 'gocardless'])
        //     ->where('domain', 'money')
        //     ->whereBetween('time', [$startTime, $endTime])
        //     ->get();
        //
        // $match = $matcher->findBestMatch($this->model, $transactions);
        //
        // if ($match && $match['confidence'] >= config('services.receipt.auto_match_threshold', 0.8)) {
        //     // Auto-link receipt to transaction
        //     $this->model->update([
        //         'linked_transaction_id' => $match['transaction']->id,
        //         'match_confidence' => $match['confidence'],
        //     ]);
        // } elseif ($match && $match['confidence'] >= config('services.receipt.review_threshold', 0.5)) {
        //     // Flag for manual review
        //     $this->model->update([
        //         'suggested_transaction_id' => $match['transaction']->id,
        //         'match_confidence' => $match['confidence'],
        //         'needs_review' => true,
        //     ]);
        // }
    }
}
