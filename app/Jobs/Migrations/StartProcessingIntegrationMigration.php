<?php

namespace App\Jobs\Migrations;

use App\Models\Integration;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    protected Integration $integration;

    public function __construct(Integration $integration)
    {
        $this->integration = $integration;
        $this->onConnection('redis');
        $this->onQueue('migration');
    }

    public function handle(): void
    {
        // Build one job per fetched window for accurate batch progress
        $jobs = [];
        $baseContext = [
            'service' => 'monzo',
            'processing_phase' => true,
        ];

        // Transactions windows
        $windows = (array) (\Illuminate\Support\Facades\Cache::get('monzo:migration:' . $this->integration->id . ':tx_windows') ?? []);
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
        $lastDate = \Illuminate\Support\Facades\Cache::get('monzo:migration:' . $this->integration->id . ':balances_last_date');
        if ($lastDate) {
            $jobs[] = (new ProcessIntegrationPage($this->integration, [[
                'kind' => 'balance_snapshot',
                'date' => $lastDate,
            ]], array_merge($baseContext, ['instance_type' => 'balances'])))
                ->onConnection('redis')->onQueue('migration');
        }

        $batch = Bus::batch($jobs)
            ->name('monzo_process_' . $this->integration->id)
            ->onConnection('redis')->onQueue('migration')
            ->dispatch();

        // Hint the UI by swapping batch id to the processing batch
        $this->integration->update(['migration_batch_id' => $batch->id]);
    }
}


