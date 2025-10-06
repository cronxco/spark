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
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

class StartProcessingIntegrationMigration implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, MigrationPauser, Queueable, SerializesModels;

    protected Integration $integration;

    public function __construct(Integration $integration)
    {
        $this->integration = $integration;
        $this->onConnection('redis');
        $this->onQueue('migration');
    }

    public function handle(): void
    {
        // Update progress to show processing phase has started
        $this->updateProgress('processing', 'Processing migration data...', 70, [
            'service' => 'monzo',
            'phase' => 'processing',
        ]);

        // Build one job per fetched window for accurate batch progress
        $jobs = [];
        $baseContext = [
            'service' => 'monzo',
            'processing_phase' => true,
        ];

        // Transactions windows
        $windows = (array) (Cache::get('monzo:migration:' . $this->integration->id . ':tx_windows') ?? []);
        foreach ($windows as $win) {
            $jobs[] = (new ProcessIntegrationPage($this->integration, [[
                'kind' => 'transactions_window',
                'since' => $win['since'] ?? null,
                'before' => $win['before'] ?? null,
            ]], array_merge($baseContext, ['instance_type' => 'transactions'])))
                ->onConnection('redis')->onQueue('migration');
        }

        // Pots snapshot
        $jobs[] = (new ProcessIntegrationPage($this->integration, [['kind' => 'pots_snapshot']], array_merge($baseContext, ['instance_type' => 'pots'])))
            ->onConnection('redis')->onQueue('migration');

        // Balances snapshot using last fetched date if present
        $lastDate = Cache::get('monzo:migration:' . $this->integration->id . ':balances_last_date');
        if ($lastDate) {
            $jobs[] = (new ProcessIntegrationPage($this->integration, [[
                'kind' => 'balance_snapshot',
                'date' => $lastDate,
            ]], array_merge($baseContext, ['instance_type' => 'balances'])))
                ->onConnection('redis')->onQueue('migration');
        }

        // Add completion job to the end of the batch
        $jobs[] = new CompleteMigration($this->integration, 'monzo');

        $this->updateProgress('processing_batch', 'Starting processing batch...', 75, [
            'service' => 'monzo',
            'jobs_count' => count($jobs),
            'transaction_windows' => count($windows),
        ]);

        $batch = Bus::batch($jobs)
            ->name('monzo_process_' . $this->integration->id)
            ->onConnection('redis')->onQueue('migration')
            ->dispatch();

        // Hint the UI by swapping batch id to the processing batch
        $this->integration->update(['migration_batch_id' => $batch->id]);
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
