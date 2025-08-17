<?php

namespace App\Jobs\Migrations;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class StartIntegrationMigration implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 3;
    public array $backoff = [60, 300, 600];

    protected Integration $integration;
    protected ?Carbon $timeboxUntil;
    protected array $options;

    public function __construct(Integration $integration, ?Carbon $timeboxUntil = null, array $options = [])
    {
        $this->integration = $integration;
        $this->timeboxUntil = $timeboxUntil;
        $this->options = $options;
        $this->onConnection('redis');
        $this->onQueue('migration');
    }

    public function handle(): void
    {
        $service = $this->integration->service;
        if ($service === 'oura') {
            $this->startOura();

            return;
        }
        if ($service === 'spotify') {
            $this->startSpotify();

            return;
        }
        if ($service === 'github') {
            $this->startGitHub();

            return;
        }
        if ($service === 'monzo') {
            $this->startMonzo();

            return;
        }
        Log::info('StartIntegrationMigration: unsupported service, skipping', [
            'service' => $service,
            'integration_id' => $this->integration->id,
        ]);
    }

    protected function startOura(): void
    {
        $type = $this->integration->instance_type ?: 'activity';
        // Date-window paging going backwards. Default windows: 30 days (daily endpoints), 7 days (heartrate)
        $now = Carbon::now();
        if ($type === 'heartrate') {
            $end = $now->copy();
            $start = $end->copy()->subDays(6);
            $context = [
                'service' => 'oura',
                'instance_type' => $type,
                'cursor' => [
                    'start_datetime' => $start->toIso8601String(),
                    'end_datetime' => $end->toIso8601String(),
                ],
                'window_days' => 7,
                'timebox_until' => $this->timeboxUntil?->toIso8601String(),
            ];
        } else {
            $end = $now->copy();
            $start = $end->copy()->subDays(29);
            $context = [
                'service' => 'oura',
                'instance_type' => $type,
                'cursor' => [
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                ],
                'window_days' => 30,
                'timebox_until' => $this->timeboxUntil?->toIso8601String(),
            ];
        }
        Bus::chain([
            new FetchIntegrationPage($this->integration, $context),
        ])->onConnection('redis')->onQueue('migration')->dispatch();
    }

    protected function startSpotify(): void
    {
        $nowMs = (int) round(microtime(true) * 1000);
        $context = [
            'service' => 'spotify',
            'instance_type' => $this->integration->instance_type ?: 'listening',
            'cursor' => [
                'before_ms' => $nowMs,
            ],
            'timebox_until' => $this->timeboxUntil?->toIso8601String(),
        ];
        Bus::chain([
            new FetchIntegrationPage($this->integration, $context),
        ])->onConnection('redis')->onQueue('migration')->dispatch();
    }

    protected function startGitHub(): void
    {
        $context = [
            'service' => 'github',
            'instance_type' => $this->integration->instance_type ?: 'activity',
            'cursor' => [
                'repo_index' => 0,
                'page' => 1,
            ],
            'timebox_until' => $this->timeboxUntil?->toIso8601String(),
        ];
        Bus::chain([
            new FetchIntegrationPage($this->integration, $context),
        ])->onConnection('redis')->onQueue('migration')->dispatch();
    }

    protected function startMonzo(): void
    {
        // Ensure master accounts instance exists before any migration
        $pluginClass = PluginRegistry::getPlugin('monzo');
        if ($pluginClass) {
            $plugin = new $pluginClass;
            // Create or find master 'accounts' instance for the group
            $group = $this->integration->group;
            if ($group) {
                $existingMaster = Integration::where('integration_group_id', $group->id)
                    ->where('service', 'monzo')
                    ->where('instance_type', 'accounts')
                    ->first();
                if (! $existingMaster) {
                    $existingMaster = $plugin->createInstance($group, 'accounts');
                }
                // Seed the master with account and pot objects (no events)
                SeedMonzoAccounts::dispatch($existingMaster)
                    ->onConnection('redis')->onQueue('migration');
            }
        }

        // We migrate three instance types independently: transactions, pots, balances
        // Transactions: page backwards using since cursor by 90-day windows until empty
        $now = Carbon::now();
        $contextTx = [
            'service' => 'monzo',
            'instance_type' => 'transactions',
            'cursor' => [
                'end_iso' => $now->toIso8601String(),
                'window_days' => 89,
            ],
            'timebox_until' => $this->timeboxUntil?->toIso8601String(),
        ];
        $contextPots = [
            'service' => 'monzo',
            'instance_type' => 'pots',
            'cursor' => [],
            'timebox_until' => $this->timeboxUntil?->toIso8601String(),
        ];
        $contextBalances = [
            'service' => 'monzo',
            'instance_type' => 'balances',
            'cursor' => [
                'end_date' => $now->toDateString(),
            ],
            'timebox_until' => $this->timeboxUntil?->toIso8601String(),
        ];

        $batchBuilder = Bus::batch([
            // Exclude master 'accounts' from fetching; just seed once above
            new FetchIntegrationPage($this->integration, $contextTx),
            new FetchIntegrationPage($this->integration, $contextPots),
            new FetchIntegrationPage($this->integration, $contextBalances),
        ])->name('monzo_fetch_' . $this->integration->id)
            ->onConnection('redis')->onQueue('migration');

        $batch = $batchBuilder->dispatch();

        // Store batch id on the integration so UI can monitor progress
        $this->integration->update(['migration_batch_id' => $batch->id]);

        // Monitor completion without closures (avoids SerializableClosure issues)
        MonitorBatchAndStartProcessing::dispatch($this->integration, $batch->id)
            ->onConnection('redis')->onQueue('migration');
    }
}
