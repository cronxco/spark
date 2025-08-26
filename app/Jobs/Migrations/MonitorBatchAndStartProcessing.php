<?php

namespace App\Jobs\Migrations;

use App\Models\Integration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

class MonitorBatchAndStartProcessing implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
            (new WithoutOverlapping('monitor_batch_' . $this->targetBatchId))
                ->releaseAfter(10),
        ];
    }

    public function handle(): void
    {
        $batch = Bus::findBatch($this->targetBatchId);
        if (! $batch) {
            // If batch not found, start processing anyway
            StartProcessingIntegrationMigration::dispatch($this->integration)
                ->onConnection('redis')->onQueue('migration');

            return;
        }

        if ($batch->finished()) {
            StartProcessingIntegrationMigration::dispatch($this->integration)
                ->onConnection('redis')->onQueue('migration');

            return;
        }

        // Re-dispatch self to check again shortly
        static::dispatch($this->integration, $this->targetBatchId)
            ->delay(now()->addSeconds(5))
            ->onConnection('redis')->onQueue('migration');
    }
}
