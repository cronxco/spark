<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Integrations\Receipt\ReceiptTransactionMatcher;
use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Services\Receipt\ReceiptMatchState;
use Throwable;

class MatchReceiptToTransactionTask extends BaseTaskJob
{
    public function failed(Throwable $error): void
    {
        if (! ReceiptMatchState::isMatched($this->model)) {
            ReceiptMatchState::update($this->model, [
                'status' => 'failed',
                'reason' => 'matching_failed',
                'attempted_at' => now()->toIso8601String(),
            ]);
        }
    }

    protected function execute(): void
    {
        $this->recordOutcome(app(ReceiptTransactionMatcher::class)->matchReceipt($this->model));
    }
}
