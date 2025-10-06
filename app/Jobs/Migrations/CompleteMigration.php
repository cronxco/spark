<?php

namespace App\Jobs\Migrations;

use App\Models\ActionProgress;
use App\Models\Integration;
use App\Traits\MigrationPauser;
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
    }
}
