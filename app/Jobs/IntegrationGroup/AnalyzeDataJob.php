<?php

namespace App\Jobs\IntegrationGroup;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\IntegrationGroup;
use Illuminate\Support\Facades\Log;

class AnalyzeDataJob extends BaseBatchDeletionJob
{
    public function handle(): void
    {
        $this->getOrCreateProgressRecord();

        $this->updateProgress('analyzing', 'Analyzing related data...', 10);

        $group = IntegrationGroup::where('id', $this->integrationGroupId)
            ->where('user_id', $this->userId)
            ->firstOrFail();

        // Check if group has already been deleted
        if ($group->trashed()) {
            Log::info('Integration group already deleted, skipping', [
                'group_id' => $this->integrationGroupId,
                'user_id' => $this->userId,
            ]);

            $this->markCompleted();

            return;
        }

        $integrations = $group->integrations;
        $events = $this->getRelatedEvents($integrations);
        $blocks = $this->getRelatedBlocks($events);
        $objects = $this->getRelatedObjects($events);

        Log::info('Found related data for deletion', [
            'integrations_count' => $integrations->count(),
            'events_count' => $events->count(),
            'blocks_count' => $blocks->count(),
            'objects_count' => $objects->count(),
        ]);

        $this->updateProgress('analyzing', 'Found data to delete', 20, [
            'integrations' => $integrations->count(),
            'events' => $events->count(),
            'blocks' => $blocks->count(),
            'objects' => $objects->count(),
        ]);

        // Store data for next jobs
        $this->deletionData = [
            'integrations' => $integrations->toArray(),
            'events' => $events->toArray(),
            'blocks' => $blocks->toArray(),
            'objects' => $objects->toArray(),
        ];

        // Dispatch next job
        DeleteBlocksBatchJob::dispatch($this->integrationGroupId, $this->userId, $this->deletionData);
    }

    private function getRelatedEvents($integrations)
    {
        $integrationIds = $integrations->pluck('id');

        return Event::whereIn('integration_id', $integrationIds)->get();
    }

    private function getRelatedBlocks($events)
    {
        $eventIds = $events->pluck('id');

        return Block::whereIn('event_id', $eventIds)->get();
    }

    private function getRelatedObjects($events)
    {
        $objectIds = $events->pluck('actor_id')
            ->merge($events->pluck('target_id'))
            ->filter()
            ->unique();

        return EventObject::whereIn('id', $objectIds)->get();
    }
}
