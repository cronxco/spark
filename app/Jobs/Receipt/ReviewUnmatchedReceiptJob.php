<?php

namespace App\Jobs\Receipt;

use App\Integrations\Receipt\ReceiptTransactionMatcher;
use App\Models\Event;
use App\Services\Receipt\ReceiptMatchState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Historical and scheduled sweeps generate proposals, never automatic links. */
class ReviewUnmatchedReceiptJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(public string $receiptId, public string $batchId)
    {
        $this->onQueue('tasks');
    }

    public function handle(ReceiptTransactionMatcher $matcher): void
    {
        $receipt = Event::query()->with(['integration', 'target'])->find($this->receiptId);
        if (! $receipt || $receipt->service !== 'receipt' || ReceiptMatchState::isMatched($receipt)) {
            return;
        }

        $outcome = $matcher->matchReceipt($receipt, allowAutomatic: false);
        ReceiptMatchState::update($receipt, [
            'backfill_batch_id' => $this->batchId,
            'backfill_attempted_at' => now()->toIso8601String(),
        ]);
        Log::info('Receipt matching sweep completed', [
            'receipt_id' => $receipt->id,
            'batch_id' => $this->batchId,
            'outcome' => $outcome,
        ]);
    }

    public function failed(Throwable $error): void
    {
        $receipt = Event::find($this->receiptId);
        if ($receipt && ! ReceiptMatchState::isMatched($receipt)) {
            ReceiptMatchState::update($receipt, [
                'status' => 'failed',
                'reason' => 'matching_failed',
                'attempted_at' => now()->toIso8601String(),
            ]);
        }
        Log::error('Receipt matching sweep failed', [
            'receipt_id' => $this->receiptId,
            'batch_id' => $this->batchId,
            'error' => $error->getMessage(),
        ]);
    }
}
