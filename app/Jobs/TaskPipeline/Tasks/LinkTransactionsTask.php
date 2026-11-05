<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Services\TransactionLinking\TransactionLinkingService;
use Illuminate\Support\Facades\Log;

class LinkTransactionsTask extends BaseTaskJob
{
    /**
     * Execute the transaction linking task
     */
    protected function execute(): void
    {
        // Only process money domain events
        if ($this->model->domain !== 'money') {
            return;
        }

        $linkingService = app(TransactionLinkingService::class);
        $autoApproveThreshold = TransactionLinkingService::DEFAULT_AUTO_APPROVE_THRESHOLD;

        $stats = $linkingService->processEvent($this->model, $autoApproveThreshold);

        if ($stats['created'] > 0 || $stats['pending'] > 0) {
            Log::info('Transaction linking completed via TaskPipeline', [
                'event_id' => $this->model->id,
                'source_id' => $this->model->source_id,
                'created' => $stats['created'],
                'pending' => $stats['pending'],
                'skipped' => $stats['skipped'],
            ]);
        }
    }
}
