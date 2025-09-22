<?php

namespace App\Jobs\IntegrationGroup;

use Illuminate\Support\Facades\Log;

class DeleteBlocksBatchJob extends BaseBatchDeletionJob
{
    public function handle(): void
    {
        $this->getOrCreateProgressRecord();

        $this->updateProgress('deleting_blocks', 'Deleting blocks...', 30);

        $blocks = collect($this->deletionData['blocks'] ?? []);

        if ($blocks->isNotEmpty()) {
            // Dispatch individual deletion jobs for each block
            foreach ($blocks as $block) {
                DeleteBlockJob::dispatch(
                    $block['id'],
                    $this->integrationGroupId,
                    $this->userId
                );
            }

            Log::info('Dispatched block deletion jobs', [
                'count' => $blocks->count(),
                'group_id' => $this->integrationGroupId,
            ]);
        }

        // Dispatch next batch job
        DeleteEventsBatchJob::dispatch($this->integrationGroupId, $this->userId, $this->deletionData);
    }
}
