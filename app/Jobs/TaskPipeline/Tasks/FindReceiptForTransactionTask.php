<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Integrations\Receipt\ReceiptTransactionMatcher;
use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Models\Event;
use App\Services\Receipt\ReceiptMatchState;

class FindReceiptForTransactionTask extends BaseTaskJob
{
    /** @var list<string> */
    public const TRANSACTION_ACTIONS = [
        'card_payment_to', 'payment_to', 'made_transaction', 'card_refund_from', 'payment_from',
    ];

    protected function execute(): void
    {
        $ownerId = $this->model->integration?->user_id;
        if ($ownerId === null) {
            $this->recordOutcome('no_candidate');

            return;
        }

        $tolerance = (int) ($this->model->value * 0.1);
        $receipts = Event::forUser($ownerId)
            ->where('service', 'receipt')
            ->where('domain', 'money')
            ->where('action', 'had_receipt_from')
            ->whereBetween('time', [$this->model->time->copy()->subHours(4), $this->model->time->copy()->addHours(4)])
            ->whereBetween('value', [max(0, $this->model->value - $tolerance), $this->model->value + $tolerance])
            ->whereNotIn('id', ReceiptMatchState::links()->select('from_id'))
            ->with(['target', 'integration'])
            ->get();

        $matcher = app(ReceiptTransactionMatcher::class);
        $outcome = 'no_candidate';
        foreach ($receipts as $receipt) {
            $result = $matcher->matchReceipt($receipt, $this->model);
            if ($result === 'matched') {
                $outcome = 'matched';
                break;
            }
            if ($result === 'review_required') {
                $outcome = 'review_required';
            }
        }
        $this->recordOutcome($outcome);
    }
}
