<?php

namespace App\Jobs\IntegrationGroup;

use Illuminate\Support\Facades\Log;

class DeleteIntegrationsBatchJob extends BaseBatchDeletionJob
{
    public function handle(): void
    {
        $this->getOrCreateProgressRecord();

        $this->updateProgress('deleting_integrations', 'Deleting integration instances...', 90);

        $integrations = collect($this->deletionData['integrations'] ?? []);

        if ($integrations->isNotEmpty()) {
            // Dispatch individual deletion jobs for each integration
            foreach ($integrations as $integration) {
                DeleteIntegrationJob::dispatch(
                    $integration['id'],
                    $this->integrationGroupId,
                    $this->userId
                );
            }

            Log::info('Dispatched integration deletion jobs', [
                'count' => $integrations->count(),
                'group_id' => $this->integrationGroupId,
            ]);
        }

        // Dispatch next batch job
        DeleteOrphanedObjectsBatchJob::dispatch($this->integrationGroupId, $this->userId, $this->deletionData);
    }
}
