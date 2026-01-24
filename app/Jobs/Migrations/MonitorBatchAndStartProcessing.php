<?php

namespace App\Jobs\Migrations;

use App\Models\ActionProgress;
use App\Models\Integration;
use App\Traits\MigrationPauser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

class MonitorBatchAndStartProcessing implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, MigrationPauser, Queueable, SerializesModels;

    protected Integration $integration;

    protected string $targetBatchId;

    public function __construct(Integration $integration, string $batchId)
    {
        $this->integration = $integration;
        $this->targetBatchId = $batchId;
        $this->onConnection('redis');
        $this->onQueue('migration');
    }

    /**
     * Prevent overlapping monitor loops for the same batch by locking per batch id.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('monitor_batch_'.$this->targetBatchId))
                ->releaseAfter(10),
        ];
    }

    public function handle(): void
    {
        $batch = Bus::findBatch($this->targetBatchId);
        if (! $batch) {
            // If batch not found, update progress and start processing anyway
            $this->updateProgress('starting_processing', 'Starting data processing...', 60, [
                'note' => 'Batch not found, proceeding to processing phase',
            ]);

            StartProcessingIntegrationMigration::dispatch($this->integration)
                ->onConnection('redis')->onQueue('migration');

            return;
        }

        if ($batch->finished()) {
            // Update progress before starting processing
            $this->updateProgress('starting_processing', 'Fetch completed, starting data processing...', 60, [
                'batch_id' => $this->targetBatchId,
                'batch_finished' => true,
            ]);

            StartProcessingIntegrationMigration::dispatch($this->integration)
                ->onConnection('redis')->onQueue('migration');

            return;
        }

        // Update progress to show we're still monitoring
        $this->updateProgress('monitoring', 'Monitoring batch progress...', 45, [
            'batch_id' => $this->targetBatchId,
            'batch_pending_jobs' => $batch->pendingJobs,
            'batch_processed_jobs' => $batch->processedJobs(),
            'batch_total_jobs' => $batch->totalJobs,
        ]);

        // Re-dispatch self to check again shortly
        static::dispatch($this->integration, $this->targetBatchId)
            ->delay(now()->addSeconds(5))
            ->onConnection('redis')->onQueue('migration');
    }

    /**
     * Update the migration progress record
     */
    protected function updateProgress(string $step, string $message, int $progress, array $details = []): void
    {
        $progressRecord = ActionProgress::getLatestProgress(
            $this->integration->user_id,
            'migration',
            "integration_{$this->integration->id}"
        );

        if ($progressRecord) {
            $progressRecord->updateProgress($step, $message, $progress, $details);
        }
    }
}
