<?php

namespace App\Jobs\IntegrationGroup;

use App\Models\ActionProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class BaseBatchDeletionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?ActionProgress $progressRecord = null;

    public function __construct(
        public string $integrationGroupId,
        public string $userId,
        public array $deletionData = []
    ) {
        $this->onQueue('pull');
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Batch deletion job failed', [
            'job' => static::class,
            'group_id' => $this->integrationGroupId,
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);

        $this->markFailed($exception->getMessage());
    }

    protected function updateProgress(string $step, string $message, int $progress, array $details = []): void
    {
        if ($this->progressRecord) {
            $this->progressRecord->updateProgress($step, $message, $progress, $details);
        }
    }

    protected function getOrCreateProgressRecord(): ActionProgress
    {
        if (! $this->progressRecord) {
            $this->progressRecord = ActionProgress::getLatestProgress(
                $this->userId,
                'deletion',
                $this->integrationGroupId
            );

            if (! $this->progressRecord) {
                $this->progressRecord = ActionProgress::createProgress(
                    $this->userId,
                    'deletion',
                    $this->integrationGroupId,
                    'starting',
                    'Starting deletion process...',
                    0
                );
            }
        }

        return $this->progressRecord;
    }

    protected function markCompleted(array $details = []): void
    {
        if ($this->progressRecord) {
            $this->progressRecord->markCompleted($details);
        }
    }

    protected function markFailed(string $errorMessage, array $details = []): void
    {
        if ($this->progressRecord) {
            $this->progressRecord->markFailed($errorMessage, $details);
        }
    }
}
