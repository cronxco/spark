<?php

namespace App\Jobs\Migrations;

use App\Models\ActionProgress;
use App\Models\Event;
use App\Models\Integration;
use App\Notifications\MigrationCompleted;
use App\Traits\MigrationPauser;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CompleteMigration implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, MigrationPauser, Queueable, SerializesModels;

    protected Integration $integration;

    protected string $service;

    public function __construct(Integration $integration, string $service = 'monzo')
    {
        $this->integration = $integration;
        $this->service = $service;
        $this->onConnection('redis');
        $this->onQueue('migration');
    }

    public function handle(): void
    {
        // Update progress to completed
        $progressRecord = ActionProgress::getLatestProgress(
            $this->integration->user_id,
            'migration',
            "integration_{$this->integration->id}"
        );

        if ($progressRecord) {
            $progressRecord->markCompleted([
                'service' => $this->service,
                'completed_at' => now()->toIso8601String(),
            ]);
        }

        // Unpause integration when processing batch completes
        static::unpauseAfterMigration($this->integration);

        Log::info('Migration processing completed - unpausing integration', [
            'integration_id' => $this->integration->id,
            'service' => $this->service,
        ]);

        // Gather migration statistics and send completion notification
        try {
            $statistics = $this->gatherMigrationStatistics();

            $this->integration->user->notify(
                new MigrationCompleted($this->integration, $statistics)
            );

            Log::info('Migration completion notification sent', [
                'integration_id' => $this->integration->id,
                'service' => $this->service,
                'statistics' => $statistics,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to send MigrationCompleted notification', [
                'integration_id' => $this->integration->id,
                'service' => $this->service,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Gather statistics about the completed migration
     */
    protected function gatherMigrationStatistics(): array
    {
        $statistics = [];

        // Get migration start time from configuration
        $migrationStartedAt = $this->integration->configuration['migration_started_at'] ?? null;

        // Count events imported during this migration
        if ($migrationStartedAt) {
            $startTime = Carbon::parse($migrationStartedAt);
            $eventsCount = Event::where('integration_id', $this->integration->id)
                ->where('created_at', '>=', $startTime)
                ->count();

            if ($eventsCount > 0) {
                $statistics['events_imported'] = $eventsCount;
            }

            // Calculate migration duration
            $duration = $startTime->diffForHumans(now(), true);
            $statistics['duration'] = $duration;
        }

        // Get date range of imported events
        $oldestEvent = Event::where('integration_id', $this->integration->id)
            ->orderBy('time', 'asc')
            ->first();

        $newestEvent = Event::where('integration_id', $this->integration->id)
            ->orderBy('time', 'desc')
            ->first();

        if ($oldestEvent && $newestEvent) {
            $oldestTime = Carbon::parse($oldestEvent->time);
            $newestTime = Carbon::parse($newestEvent->time);

            // Format as "Jan 2023 - Dec 2024" or just "Dec 2024" if same month/year
            if ($oldestTime->format('Y-m') === $newestTime->format('Y-m')) {
                $statistics['date_range'] = $newestTime->format('M Y');
            } else {
                $statistics['date_range'] = $oldestTime->format('M Y') . ' - ' . $newestTime->format('M Y');
            }
        }

        return $statistics;
    }
}
