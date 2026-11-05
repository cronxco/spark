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

abstract class BaseIndividualDeletionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $recordId,
        public string $integrationGroupId,
        public string $userId
    ) {
        $this->onQueue('pull');
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Individual deletion job failed', [
            'job' => static::class,
            'record_id' => $this->recordId,
            'group_id' => $this->integrationGroupId,
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }

    protected function getProgressRecord(): ?ActionProgress
    {
        return ActionProgress::getLatestProgress(
            $this->userId,
            'deletion',
            $this->integrationGroupId
        );
    }

    protected function logDeletion(string $recordType, string $recordId): void
    {
        Log::info("Deleted {$recordType}", [
            'record_id' => $recordId,
            'group_id' => $this->integrationGroupId,
            'user_id' => $this->userId,
        ]);
    }
}
