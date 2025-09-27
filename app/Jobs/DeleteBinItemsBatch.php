<?php

namespace App\Jobs;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteBinItemsBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes
    public int $tries = 3;

    /**
     * The user ID to filter records by
     */
    public function __construct(
        private string $userId,
        private int $batchSize = 100
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting hard delete batch job', [
            'user_id' => $this->userId,
            'batch_size' => $this->batchSize,
        ]);

        $totalDeleted = 0;

        // Delete in dependency order: Blocks -> Events -> Integrations -> Integration Groups -> Objects
        $totalDeleted += $this->deleteBlocks();
        $totalDeleted += $this->deleteEvents();
        $totalDeleted += $this->deleteIntegrations();
        $totalDeleted += $this->deleteIntegrationGroups();
        $totalDeleted += $this->deleteObjects();

        Log::info('Hard delete batch completed', [
            'user_id' => $this->userId,
            'total_deleted' => $totalDeleted,
        ]);

        // If we deleted any records, dispatch another job to continue processing
        if ($totalDeleted > 0) {
            Log::info('Dispatching next batch job', [
                'user_id' => $this->userId,
                'previous_batch_deleted' => $totalDeleted,
            ]);

            self::dispatch($this->userId, $this->batchSize)
                ->delay(now()->addSeconds(5)); // Small delay to avoid overwhelming the system
        } else {
            Log::info('Hard delete process completed - no more records to delete', [
                'user_id' => $this->userId,
            ]);
        }
    }

    /**
     * Handle job failure
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Hard delete batch job failed', [
            'user_id' => $this->userId,
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    /**
     * Delete blocks in batches
     */
    private function deleteBlocks(): int
    {
        $query = Block::onlyTrashed()
            ->whereHas('event.integration', function ($q) {
                $q->where('user_id', $this->userId);
            });

        return $this->processBatch($query, 'blocks');
    }

    /**
     * Delete events in batches
     */
    private function deleteEvents(): int
    {
        $query = Event::onlyTrashed()
            ->whereHas('integration', function ($q) {
                $q->where('user_id', $this->userId);
            });

        return $this->processBatch($query, 'events');
    }

    /**
     * Delete integrations in batches
     */
    private function deleteIntegrations(): int
    {
        $query = Integration::onlyTrashed()
            ->where('user_id', $this->userId);

        return $this->processBatch($query, 'integrations');
    }

    /**
     * Delete integration groups in batches
     */
    private function deleteIntegrationGroups(): int
    {
        $query = IntegrationGroup::onlyTrashed()
            ->where('user_id', $this->userId);

        return $this->processBatch($query, 'integration_groups');
    }

    /**
     * Delete objects in batches (only if not referenced by any events)
     */
    private function deleteObjects(): int
    {
        $objects = EventObject::onlyTrashed()
            ->where('user_id', $this->userId)
            ->limit($this->batchSize)
            ->get();

        $deleted = 0;
        foreach ($objects as $object) {
            // Check if object is still referenced by any events (including trashed ones)
            $isReferenced = Event::withTrashed()
                ->where(function ($query) use ($object) {
                    $query->where('actor_id', $object->id)
                        ->orWhere('target_id', $object->id);
                })
                ->exists();

            if (! $isReferenced) {
                $object->forceDelete();
                $deleted++;
            }
        }

        if ($deleted > 0) {
            Log::info('Hard deleted objects', [
                'user_id' => $this->userId,
                'count' => $deleted,
            ]);
        }

        return $deleted;
    }

    /**
     * Process a batch of records for deletion
     */
    private function processBatch($query, string $type): int
    {
        $records = $query->limit($this->batchSize)->get();

        if ($records->isEmpty()) {
            return 0;
        }

        $deleted = 0;
        foreach ($records as $record) {
            $record->forceDelete();
            $deleted++;
        }

        if ($deleted > 0) {
            Log::info("Hard deleted {$type}", [
                'user_id' => $this->userId,
                'count' => $deleted,
            ]);
        }

        return $deleted;
    }
}
