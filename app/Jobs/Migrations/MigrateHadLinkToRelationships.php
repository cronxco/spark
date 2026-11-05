<?php

namespace App\Jobs\Migrations;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Relationship;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateHadLinkToRelationships implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes

    public $tries = 1;

    public function __construct(
        public int $batchSize = 100,
        public ?int $limit = null
    ) {}

    public function handle(): void
    {
        Log::info('Migration: Starting had_link_to events to relationships migration', [
            'batch_size' => $this->batchSize,
            'limit' => $this->limit,
        ]);

        $totalMigrated = 0;
        $totalErrors = 0;
        $totalSkipped = 0;

        // Find all events with action = 'had_link_to' that are not soft-deleted
        $query = Event::where('action', 'had_link_to')
            ->whereNull('deleted_at')
            ->orderBy('created_at');

        if ($this->limit) {
            $query->limit($this->limit);
        }

        $totalEvents = $query->count();

        Log::info('Migration: Found events to migrate', [
            'total_events' => $totalEvents,
        ]);

        // Process in batches
        $query->chunk($this->batchSize, function ($events) use (&$totalMigrated, &$totalErrors, &$totalSkipped) {
            foreach ($events as $event) {
                try {
                    // Validate event has required data
                    if (! $event->actor_id || ! $event->target_id) {
                        Log::warning('Migration: Skipping event without actor or target', [
                            'event_id' => $event->id,
                            'actor_id' => $event->actor_id,
                            'target_id' => $event->target_id,
                        ]);
                        $totalSkipped++;

                        continue;
                    }

                    // Get user_id from integration
                    $integration = Integration::find($event->integration_id);
                    if (! $integration) {
                        Log::warning('Migration: Skipping event - integration not found', [
                            'event_id' => $event->id,
                            'integration_id' => $event->integration_id,
                        ]);
                        $totalSkipped++;

                        continue;
                    }

                    // Verify actor and target objects exist
                    $actorExists = EventObject::where('id', $event->actor_id)->exists();
                    $targetExists = EventObject::where('id', $event->target_id)->exists();

                    if (! $actorExists || ! $targetExists) {
                        Log::warning('Migration: Skipping event - actor or target object not found', [
                            'event_id' => $event->id,
                            'actor_id' => $event->actor_id,
                            'target_id' => $event->target_id,
                            'actor_exists' => $actorExists,
                            'target_exists' => $targetExists,
                        ]);
                        $totalSkipped++;

                        continue;
                    }

                    // Create relationship
                    DB::transaction(function () use ($event, $integration) {
                        Relationship::createRelationship([
                            'user_id' => $integration->user_id,
                            'from_type' => EventObject::class,
                            'from_id' => $event->actor_id,
                            'to_type' => EventObject::class,
                            'to_id' => $event->target_id,
                            'type' => 'linked_to',
                            'metadata' => $event->event_metadata ?? [],
                        ]);

                        // Soft-delete the original event
                        $event->delete();
                    });

                    $totalMigrated++;

                    if ($totalMigrated % 10 === 0) {
                        Log::info('Migration: Progress update', [
                            'migrated' => $totalMigrated,
                            'errors' => $totalErrors,
                            'skipped' => $totalSkipped,
                        ]);
                    }
                } catch (Exception $e) {
                    Log::error('Migration: Failed to migrate event', [
                        'event_id' => $event->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    $totalErrors++;
                }
            }
        });

        Log::info('Migration: Completed had_link_to events to relationships migration', [
            'total_events' => $totalEvents,
            'migrated' => $totalMigrated,
            'errors' => $totalErrors,
            'skipped' => $totalSkipped,
        ]);
    }
}
