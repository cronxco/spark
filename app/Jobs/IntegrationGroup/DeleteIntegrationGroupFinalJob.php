<?php

namespace App\Jobs\IntegrationGroup;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\IntegrationGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

class DeleteIntegrationGroupFinalJob extends BaseBatchDeletionJob
{
    public function handle(): void
    {
        $this->getOrCreateProgressRecord();

        $this->updateProgress('cleaning_logs', 'Cleaning up activity logs...', 80);

        $events = collect($this->deletionData['events'] ?? []);
        $blocks = collect($this->deletionData['blocks'] ?? []);
        $objects = collect($this->deletionData['objects'] ?? []);

        DB::transaction(function () use ($events, $blocks, $objects) {
            // Delete activity logs for events
            if ($events->isNotEmpty()) {
                Activity::where('log_name', 'changelog')
                    ->whereIn('subject_id', $events->pluck('id'))
                    ->where('subject_type', Event::class)
                    ->delete();
            }

            // Delete activity logs for blocks
            if ($blocks->isNotEmpty()) {
                Activity::where('log_name', 'changelog')
                    ->whereIn('subject_id', $blocks->pluck('id'))
                    ->where('subject_type', Block::class)
                    ->delete();
            }

            // Delete activity logs for objects
            if ($objects->isNotEmpty()) {
                Activity::where('log_name', 'changelog')
                    ->whereIn('subject_id', $objects->pluck('id'))
                    ->where('subject_type', EventObject::class)
                    ->delete();
            }
        });

        Log::info('Cleaned up activity logs', [
            'group_id' => $this->integrationGroupId,
        ]);

        $this->updateProgress('deleting_group', 'Deleting integration group...', 95);

        DB::transaction(function () {
            $group = IntegrationGroup::where('id', $this->integrationGroupId)
                ->where('user_id', $this->userId)
                ->first();

            if ($group) {
                $group->forceDelete();
            }
        });

        Log::info('Deleted integration group', [
            'group_id' => $this->integrationGroupId,
        ]);

        // Mark as completed
        $this->updateProgress('completed', 'Deletion completed successfully!', 100, [
            'deleted_counts' => [
                'integrations' => count($this->deletionData['integrations'] ?? []),
                'events' => count($this->deletionData['events'] ?? []),
                'blocks' => count($this->deletionData['blocks'] ?? []),
                'objects' => count($this->deletionData['objects'] ?? []),
            ],
        ]);

        $this->markCompleted([
            'deleted_counts' => [
                'integrations' => count($this->deletionData['integrations'] ?? []),
                'events' => count($this->deletionData['events'] ?? []),
                'blocks' => count($this->deletionData['blocks'] ?? []),
                'objects' => count($this->deletionData['objects'] ?? []),
            ],
        ]);
    }
}
