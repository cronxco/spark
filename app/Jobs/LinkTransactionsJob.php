<?php

namespace App\Jobs;

use App\Models\Event;
use App\Services\TransactionLinking\TransactionLinkingService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class LinkTransactionsJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * Delete the job if its models no longer exist.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Event $event,
        public float $autoApproveThreshold = TransactionLinkingService::DEFAULT_AUTO_APPROVE_THRESHOLD
    ) {}

    /**
     * Execute the job.
     */
    public function handle(TransactionLinkingService $linkingService): void
    {
        // Only process money domain events
        if ($this->event->domain !== 'money') {
            return;
        }

        try {
            $stats = $linkingService->processEvent($this->event, $this->autoApproveThreshold);

            if ($stats['created'] > 0 || $stats['pending'] > 0) {
                Log::info('Transaction linking completed for event', [
                    'event_id' => $this->event->id,
                    'source_id' => $this->event->source_id,
                    'created' => $stats['created'],
                    'pending' => $stats['pending'],
                    'skipped' => $stats['skipped'],
                ]);
            }
        } catch (Exception $e) {
            Log::error('Failed to link transactions for event', [
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('LinkTransactionsJob failed after all retries', [
            'event_id' => $this->event->id,
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'link-transactions-'.$this->event->id;
    }
}
