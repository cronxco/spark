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
        ReceiptMatchState::failIfPending($this->model);
    }

    protected function execute(): void
    {
        $this->recordOutcome(app(ReceiptTransactionMatcher::class)->matchReceipt($this->model));
    }
}
