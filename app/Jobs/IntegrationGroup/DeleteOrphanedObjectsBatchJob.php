<?php

namespace App\Jobs\IntegrationGroup;

use App\Models\EventObject;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class DeleteOrphanedObjectsBatchJob extends BaseBatchDeletionJob
{
    public function handle(): void
    {
        $this->getOrCreateProgressRecord();

        $this->updateProgress('finding_orphans', 'Finding orphaned objects...', 60);

        $user = User::findOrFail($this->userId);
        $orphanedObjects = $this->findOrphanedObjects($user);

        $this->updateProgress('deleting_objects', 'Deleting orphaned objects...', 70);

        if ($orphanedObjects->isNotEmpty()) {
            // Dispatch individual deletion jobs for each orphaned object
            foreach ($orphanedObjects as $object) {
                DeleteEventObjectJob::dispatch(
                    $object->id,
                    $this->integrationGroupId,
                    $this->userId
                );
            }

            Log::info('Dispatched orphaned object deletion jobs', [
                'count' => $orphanedObjects->count(),
                'group_id' => $this->integrationGroupId,
            ]);
        }

        // Dispatch next batch job
        DeleteIntegrationGroupFinalJob::dispatch($this->integrationGroupId, $this->userId, $this->deletionData);
    }

    private function findOrphanedObjects(User $user)
    {
        // Find objects that are no longer referenced by any events
        return EventObject::where('user_id', $user->id)
            ->whereDoesntHave('actorEvents')
            ->whereDoesntHave('targetEvents')
            ->get();
    }
}
