<?php

namespace App\Jobs\Migrations;

use App\Integrations\PluginRegistry;
use App\Jobs\Outline\OutlineMigrationPull;
use App\Models\ActionProgress;
use App\Models\Integration;
use App\Notifications\MigrationFailed;
use App\Traits\MigrationPauser;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class StartIntegrationMigration implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, MigrationPauser, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public array $backoff = [60, 300, 600];

    public ?ActionProgress $progressRecord = null;

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
        // Pause the integration during migration to prevent regular updates
        static::pauseDuringMigration($this->integration);

        // Create progress record for migration
        $this->progressRecord = ActionProgress::createProgress(
            $this->integration->user_id,
            'migration',
            "integration_{$this->integration->id}",
            'starting',
            'Starting integration migration...',
            0
        );

        try {
            $service = $this->integration->service;

            $this->updateProgress('initializing', "Initializing {$service} migration...", 10, [
                'service' => $service,
                'integration_id' => $this->integration->id,
            ]);

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
            if ($service === 'gocardless') {
                $this->startGoCardless();

                return;
            }

            if ($service === 'outline') {
                $this->startOutline();

                return;
            }

            if ($service === 'karakeep') {
                $this->startKarakeep();

                return;
            }

            $this->updateProgress('failed', 'Unsupported service', 0, [
                'service' => $service,
                'integration_id' => $this->integration->id,
            ]);

            Log::info('StartIntegrationMigration: unsupported service, skipping', [
                'service' => $service,
                'integration_id' => $this->integration->id,
            ]);
        } catch (Throwable $e) {
            $this->markFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle job failure
     */
    public function failed(Throwable $exception): void
    {
        Log::error('StartIntegrationMigration failed', [
            'integration_id' => $this->integration->id,
            'service' => $this->integration->service,
            'error' => $exception->getMessage(),
        ]);

        $this->markFailed($exception->getMessage(), [
            'integration_id' => $this->integration->id,
            'service' => $this->integration->service,
        ]);

        // Ensure integration is unpaused on job failure
        static::unpauseAfterMigration($this->integration);

        // Notify user of migration failure
        try {
            $this->integration->user->notify(
                new MigrationFailed(
                    $this->integration,
                    $exception->getMessage(),
                    [
                        'service' => $this->integration->service,
                        'instance_type' => $this->integration->instance_type,
                        'attempts' => $this->attempts(),
                    ]
                )
            );
        } catch (Exception $e) {
            Log::error('Failed to send MigrationFailed notification', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function startOura(): void
    {
        $type = $this->integration->instance_type ?: 'activity';

        $this->updateProgress('configuring', "Configuring Oura {$type} migration...", 20, [
            'service' => 'oura',
            'instance_type' => $type,
        ]);

        // Track migration start time for statistics
        $this->integration->update([
            'configuration->migration_started_at' => now()->toIso8601String(),
        ]);

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

        $this->updateProgress('fetching', 'Starting data fetch...', 30, [
            'service' => 'oura',
            'instance_type' => $type,
            'window_days' => $context['window_days'],
        ]);

        Bus::chain([
            new FetchIntegrationPage($this->integration, $context),
        ])->onConnection('redis')->onQueue('migration')->dispatch();
    }

    protected function startSpotify(): void
    {
        $this->updateProgress('configuring', 'Configuring Spotify migration...', 20, [
            'service' => 'spotify',
            'instance_type' => $this->integration->instance_type ?: 'listening',
        ]);

        // Track migration start time for statistics
        $this->integration->update([
            'configuration->migration_started_at' => now()->toIso8601String(),
        ]);

        $nowMs = (int) round(microtime(true) * 1000);
        $context = [
            'service' => 'spotify',
            'instance_type' => $this->integration->instance_type ?: 'listening',
            'cursor' => [
                'before_ms' => $nowMs,
            ],
            'timebox_until' => $this->timeboxUntil?->toIso8601String(),
        ];

        $this->updateProgress('fetching', 'Starting Spotify data fetch...', 30, [
            'service' => 'spotify',
            'instance_type' => $context['instance_type'],
        ]);

        Bus::chain([
            new FetchIntegrationPage($this->integration, $context),
        ])->onConnection('redis')->onQueue('migration')->dispatch();
    }

    protected function startGitHub(): void
    {
        $this->updateProgress('configuring', 'Configuring GitHub migration...', 20, [
            'service' => 'github',
            'instance_type' => $this->integration->instance_type ?: 'activity',
        ]);

        // Track migration start time for statistics
        $this->integration->update([
            'configuration->migration_started_at' => now()->toIso8601String(),
        ]);

        $context = [
            'service' => 'github',
            'instance_type' => $this->integration->instance_type ?: 'activity',
            'cursor' => [
                'repo_index' => 0,
                'page' => 1,
            ],
            'timebox_until' => $this->timeboxUntil?->toIso8601String(),
        ];

        $this->updateProgress('fetching', 'Starting GitHub data fetch...', 30, [
            'service' => 'github',
            'instance_type' => $context['instance_type'],
        ]);

        Bus::chain([
            new FetchIntegrationPage($this->integration, $context),
        ])->onConnection('redis')->onQueue('migration')->dispatch();
    }

    protected function startMonzo(): void
    {
        $this->updateProgress('configuring', 'Configuring Monzo migration...', 20, [
            'service' => 'monzo',
            'instance_type' => $this->integration->instance_type ?: 'transactions',
        ]);

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
                    $existingMaster = $plugin->createInstance($group, 'accounts', [], true);
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

        $this->updateProgress('fetching', 'Starting Monzo data fetch (transactions, pots, balances)...', 30, [
            'service' => 'monzo',
            'instance_types' => ['transactions', 'pots', 'balances'],
            'window_days' => 89,
        ]);

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

        $this->updateProgress('monitoring', 'Monitoring batch progress...', 40, [
            'service' => 'monzo',
            'batch_id' => $batch->id,
            'instance_types' => ['transactions', 'pots', 'balances'],
        ]);

        // Monitor completion without closures (avoids SerializableClosure issues)
        MonitorBatchAndStartProcessing::dispatch($this->integration, $batch->id)
            ->onConnection('redis')->onQueue('migration');
    }

    protected function startGoCardless(): void
    {
        $this->updateProgress('configuring', 'Configuring GoCardless migration...', 20, [
            'service' => 'gocardless',
            'instance_type' => $this->integration->instance_type ?: 'transactions',
        ]);

        // Seed contexts for transactions and balances. Accounts (master) is handled by plugin when needed.
        $now = Carbon::now();
        $contextTx = [
            'service' => 'gocardless',
            'instance_type' => 'transactions',
            'cursor' => [
                'end_iso' => $now->toIso8601String(),
                'window_days' => 89,
            ],
            'timebox_until' => $this->timeboxUntil?->toIso8601String(),
        ];
        $contextBalances = [
            'service' => 'gocardless',
            'instance_type' => 'balances',
            'cursor' => [
                'end_date' => $now->toDateString(),
            ],
            'timebox_until' => $this->timeboxUntil?->toIso8601String(),
        ];

        $this->updateProgress('fetching', 'Starting GoCardless data fetch (transactions, balances)...', 30, [
            'service' => 'gocardless',
            'instance_types' => ['transactions', 'balances'],
            'window_days' => 89,
        ]);

        $batch = Bus::batch([
            new FetchIntegrationPage($this->integration, $contextTx),
            new FetchIntegrationPage($this->integration, $contextBalances),
        ])->name('gocardless_fetch_' . $this->integration->id)
            ->onConnection('redis')->onQueue('migration')
            ->dispatch();

        $this->integration->update(['migration_batch_id' => $batch->id]);

        $this->updateProgress('monitoring', 'Monitoring batch progress...', 40, [
            'service' => 'gocardless',
            'batch_id' => $batch->id,
            'instance_types' => ['transactions', 'balances'],
        ]);

        MonitorBatchAndStartProcessing::dispatch($this->integration, $batch->id)
            ->onConnection('redis')->onQueue('migration');
    }

    protected function startOutline(): void
    {
        $instanceType = $this->integration->instance_type ?: 'recent_documents';

        $this->updateProgress('configuring', 'Configuring Outline migration...', 20, [
            'service' => 'outline',
            'instance_type' => $instanceType,
        ]);

        // Skip migration for task instances - they don't need backfill
        if ($instanceType === 'task') {
            // Mark as completed without sending notification
            if ($this->progressRecord) {
                $this->progressRecord->markCompleted([
                    'service' => 'outline',
                    'instance_type' => $instanceType,
                    'skipped' => true,
                    'reason' => 'Task instances do not perform data backfill',
                ]);
            }

            // Unpause immediately since we're not running a migration
            static::unpauseAfterMigration($this->integration);

            Log::info('Outline migration skipped for task instance', [
                'integration_id' => $this->integration->id,
                'instance_type' => $instanceType,
            ]);

            return;
        }

        // Update migration status to started
        $this->integration->update([
            'configuration->migration_status' => 'started',
            'configuration->migration_started_at' => now()->toIso8601String(),
        ]);

        // Build context for collection filtering based on instance type
        $context = $this->buildOutlineContext($instanceType);

        $this->updateProgress('fetching', 'Starting Outline migration pull...', 30, [
            'service' => 'outline',
            'instance_type' => $instanceType,
            'context' => $context,
        ]);

        // Dispatch the Outline migration job with context
        OutlineMigrationPull::dispatch($this->integration, 0, 50, $context)
            ->onConnection('redis')
            ->onQueue('migration');

        $this->updateProgress('monitoring', 'Outline migration started...', 40, [
            'service' => 'outline',
            'instance_type' => $instanceType,
            'context' => $context,
            'note' => 'Outline migration runs independently and will update its own status',
        ]);
    }

    /**
     * Update progress for the migration
     */
    protected function updateProgress(string $step, string $message, int $progress, array $details = []): void
    {
        if ($this->progressRecord) {
            $this->progressRecord->updateProgress($step, $message, $progress, $details);
        }
    }

    /**
     * Mark migration as failed
     */
    protected function markFailed(string $errorMessage, array $details = []): void
    {
        if ($this->progressRecord) {
            $this->progressRecord->markFailed($errorMessage, $details);
        }

        // Unpause the integration when migration fails so it's not stuck
        static::unpauseAfterMigration($this->integration);
    }

    protected function startKarakeep(): void
    {
        $instanceType = $this->integration->instance_type ?: 'bookmarks';

        $this->updateProgress('configuring', 'Configuring Karakeep migration...', 20, [
            'service' => 'karakeep',
            'instance_type' => $instanceType,
        ]);

        // Track migration start time for statistics
        $this->integration->update([
            'configuration->migration_started_at' => now()->toIso8601String(),
        ]);

        // Set up initial context with larger page size for migration
        $context = [
            'service' => 'karakeep',
            'instance_type' => $instanceType,
            'cursor' => [
                'page' => 1,
                'per_page' => 100, // Use maximum page size for migration
            ],
            'timebox_until' => $this->timeboxUntil?->toIso8601String(),
        ];

        $this->updateProgress('fetching', 'Starting Karakeep data fetch...', 30, [
            'service' => 'karakeep',
            'instance_type' => $instanceType,
        ]);

        // Dispatch the fetch job to begin migration
        Bus::chain([
            new FetchIntegrationPage($this->integration, $context),
        ])->onConnection('redis')->onQueue('migration')->dispatch();
    }

    /**
     * Build context array for Outline migration collection filtering
     */
    private function buildOutlineContext(string $instanceType): array
    {
        $context = [
            'timebox_until' => $this->timeboxUntil?->toIso8601String(),
        ];

        // Get the daynotes collection ID from the integration group
        $daynotesCollectionId = null;
        if ($this->integration->group && $this->integration->group->auth_metadata) {
            $daynotesCollectionId = $this->integration->group->auth_metadata['daynotes_collection_id'] ?? null;
        }

        // Fallback to config if not in group
        if (! $daynotesCollectionId) {
            $daynotesCollectionId = config('services.outline.daynotes_collection_id');
        }

        // Apply collection filtering based on instance type
        if ($instanceType === 'recent_daynotes' && $daynotesCollectionId) {
            // Include only day notes collection
            $context['include_collection_ids'] = [$daynotesCollectionId];
        } elseif ($instanceType === 'recent_documents' && $daynotesCollectionId) {
            // Exclude day notes collection for regular documents
            $context['exclude_collection_ids'] = [$daynotesCollectionId];
        }
        // For other instance types or when no collection ID is configured, fetch all documents

        return $context;
    }
}
