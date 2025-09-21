<?php

namespace App\Jobs;

use App\Models\ActionProgress;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;
use Throwable;

class DeleteIntegrationGroupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?ActionProgress $progressRecord = null;

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

        // Create progress record
        $this->progressRecord = ActionProgress::createProgress(
            $this->userId,
            'deletion',
            $this->integrationGroupId,
            'starting',
            'Starting deletion process...',
            0
        );

        Log::info('Starting integration group deletion', [
            'group_id' => $this->integrationGroupId,
            'user_id' => $this->userId,
            'service' => $group->service,
            'account_id' => $group->account_id,
        ]);

        // Step 1: Get all related data
        $this->updateProgress('analyzing', 'Analyzing related data...', 10);
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

        // Step 2: Delete blocks first (they depend on events)
        $this->updateProgress('deleting_blocks', 'Deleting blocks...', 30);
        DB::transaction(function () use ($blocks) {
            $this->deleteBlocks($blocks);
        });

        // Step 3: Delete events
        $this->updateProgress('deleting_events', 'Deleting events...', 50);
        DB::transaction(function () use ($events) {
            $this->deleteEvents($events);
        });

        // Step 4: Find and delete orphaned objects (after events are deleted)
        $this->updateProgress('finding_orphans', 'Finding orphaned objects...', 60);
        $orphanedObjects = $this->findOrphanedObjects($user);
        $this->updateProgress('deleting_objects', 'Deleting orphaned objects...', 70);
        DB::transaction(function () use ($orphanedObjects) {
            $this->deleteOrphanedObjects($orphanedObjects);
        });

        // Step 5: Delete activity logs for all deleted models
        $this->updateProgress('cleaning_logs', 'Cleaning up activity logs...', 80);
        DB::transaction(function () use ($events, $blocks, $objects, $orphanedObjects) {
            $this->deleteActivityLogs($events, $blocks, $objects, $orphanedObjects);
        });

        // Step 6: Permanently delete integrations
        $this->updateProgress('deleting_integrations', 'Deleting integration instances...', 90);
        DB::transaction(function () use ($integrations) {
            $this->forceDeleteIntegrations($integrations);
        });

        // Step 7: Permanently delete integration group
        $this->updateProgress('deleting_group', 'Deleting integration group...', 95);
        DB::transaction(function () use ($group) {
            $group->forceDelete();
        });

        Log::info('Integration group deletion completed successfully', [
            'group_id' => $this->integrationGroupId,
            'deleted_counts' => [
                'integrations' => $integrations->count(),
                'events' => $events->count(),
                'blocks' => $blocks->count(),
                'objects' => $objects->count() + $orphanedObjects->count(),
            ],
        ]);

        // Update progress and mark as completed
        $this->updateProgress('completed', 'Deletion completed successfully!', 100, [
            'deleted_counts' => [
                'integrations' => $integrations->count(),
                'events' => $events->count(),
                'blocks' => $blocks->count(),
                'objects' => $objects->count() + $orphanedObjects->count(),
            ],
        ]);

        // Mark as completed
        if ($this->progressRecord) {
            $this->progressRecord->markCompleted([
                'deleted_counts' => [
                    'integrations' => $integrations->count(),
                    'events' => $events->count(),
                    'blocks' => $blocks->count(),
                    'objects' => $objects->count() + $orphanedObjects->count(),
                ],
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Integration group deletion failed', [
            'group_id' => $this->integrationGroupId,
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Mark as failed
        if ($this->progressRecord) {
            $this->progressRecord->markFailed($exception->getMessage(), [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function getRelatedEvents($integrations)
    {
        return Event::whereIn('integration_id', $integrations->pluck('id'))
            ->with(['blocks', 'actor', 'target'])
            ->get();
    }

    private function getRelatedBlocks($events)
    {
        return Block::whereIn('event_id', $events->pluck('id'))->get();
    }

    private function getRelatedObjects($events)
    {
        $actorIds = $events->pluck('actor_id')->filter();
        $targetIds = $events->pluck('target_id')->filter();

        return EventObject::whereIn('id', $actorIds->merge($targetIds))->get();
    }

    private function deleteBlocks($blocks): void
    {
        foreach ($blocks as $block) {
            $block->forceDelete();
        }
    }

    private function deleteEvents($events): void
    {
        foreach ($events as $event) {
            $event->forceDelete();
        }
    }

    private function findOrphanedObjects(User $user)
    {
        // Find objects that are no longer referenced by any events
        return EventObject::where('user_id', $user->id)
            ->whereDoesntHave('actorEvents')
            ->whereDoesntHave('targetEvents')
            ->get();
    }

    private function deleteOrphanedObjects($orphanedObjects): void
    {
        foreach ($orphanedObjects as $object) {
            $object->forceDelete();
        }
    }

    private function deleteActivityLogs($events, $blocks, $objects, $orphanedObjects): void
    {
        $allObjects = $objects->merge($orphanedObjects);

        // Delete activity logs for events
        Activity::where('log_name', 'changelog')
            ->whereIn('subject_id', $events->pluck('id'))
            ->where('subject_type', Event::class)
            ->delete();

        // Delete activity logs for blocks
        Activity::where('log_name', 'changelog')
            ->whereIn('subject_id', $blocks->pluck('id'))
            ->where('subject_type', Block::class)
            ->delete();

        // Delete activity logs for objects
        Activity::where('log_name', 'changelog')
            ->whereIn('subject_id', $allObjects->pluck('id'))
            ->where('subject_type', EventObject::class)
            ->delete();
    }

    private function forceDeleteIntegrations($integrations): void
    {
        foreach ($integrations as $integration) {
            $integration->forceDelete();
        }
    }

    private function updateProgress(string $step, string $message, int $progress, array $details = []): void
    {
        if ($this->progressRecord) {
            $this->progressRecord->updateProgress($step, $message, $progress, $details);
        }
    }
}
