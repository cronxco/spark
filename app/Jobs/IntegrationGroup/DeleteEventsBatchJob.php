<?php

namespace App\Jobs\IntegrationGroup;

use Illuminate\Support\Facades\Log;

class DeleteEventsBatchJob extends BaseBatchDeletionJob
{
    public function handle(): void
    {
        $this->getOrCreateProgressRecord();

        $this->updateProgress('deleting_events', 'Deleting events...', 50);

        $events = collect($this->deletionData['events'] ?? []);

        if ($events->isNotEmpty()) {
            // Dispatch individual deletion jobs for each event
            foreach ($events as $event) {
                DeleteEventJob::dispatch(
                    $event['id'],
                    $this->integrationGroupId,
                    $this->userId
                );
            }

            Log::info('Dispatched event deletion jobs', [
                'count' => $events->count(),
                'group_id' => $this->integrationGroupId,
            ]);
        }

        // Dispatch next batch job
        DeleteIntegrationsBatchJob::dispatch($this->integrationGroupId, $this->userId, $this->deletionData);
    }
}
