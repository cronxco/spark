<?php

namespace App\Jobs;

use App\Jobs\IntegrationGroup\AnalyzeDataJob;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteIntegrationGroupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $integrationGroupId,
        public string $userId
    ) {
        $this->onQueue('pull');
    }

    public function handle(): void
    {
        $user = User::findOrFail($this->userId);
        $group = IntegrationGroup::where('id', $this->integrationGroupId)
            ->where('user_id', $this->userId)
            ->firstOrFail();

        // Check if group has already been deleted
        if ($group->trashed()) {
            Log::info('Integration group already deleted, skipping', [
                'group_id' => $this->integrationGroupId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        Log::info('Starting integration group deletion chain', [
            'group_id' => $this->integrationGroupId,
            'user_id' => $this->userId,
            'service' => $group->service,
            'account_id' => $group->account_id,
        ]);

        // Dispatch the first job in the chain
        AnalyzeDataJob::dispatch($this->integrationGroupId, $this->userId);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Integration group deletion failed', [
            'group_id' => $this->integrationGroupId,
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }
}
