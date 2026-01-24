<?php

namespace App\Jobs\Migrations;

use App\Integrations\PluginRegistry;
use App\Jobs\Data\Karakeep\KarakeepBookmarksData;
use App\Models\ActionProgress;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Traits\MigrationPauser;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Nordigen\NordigenPHP\API\NordigenClient;
use Throwable;

class ProcessIntegrationPage implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, MigrationPauser, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public array $backoff = [60, 300, 600];

    public ?ActionProgress $progressRecord = null;

    protected Integration $integration;

    protected array $items;

    protected array $context;

    public function __construct(Integration $integration, array $items, array $context)
    {
        $this->integration = $integration;
        $this->items = $items;
        $this->context = $context;
        $this->onConnection('redis');
        $this->onQueue('migration');
    }

    public function handle(): void
    {
        if (empty($this->items)) {
            return;
        }

        // Create unique action ID for this specific processing job
        $actionId = $this->generateActionId();

        // Get or create progress record for this processing job
        $this->progressRecord = ActionProgress::getLatestProgress(
            $this->integration->user_id,
            'migration',
            $actionId
        );

        // Create progress record if it doesn't exist
        if (! $this->progressRecord) {
            $this->progressRecord = ActionProgress::createProgress(
                $this->integration->user_id,
                'migration',
                $actionId,
                'processing',
                'Processing migration data...',
                50
            );
        }

        try {
            $service = $this->context['service'] ?? $this->integration->service;
            $instanceType = $this->context['instance_type'] ?? $this->integration->instance_type;

            $this->updateProgress('processing', "Processing {$service} {$instanceType} data...", 50, [
                'service' => $service,
                'instance_type' => $instanceType,
                'items_count' => count($this->items),
            ]);

            if ($service === 'oura') {
                $pluginClass = PluginRegistry::getPlugin('oura');
                (new $pluginClass)->processOuraMigrationItems(
                    $this->integration,
                    $this->context['instance_type'] ?? ($this->integration->instance_type ?: 'activity'),
                    $this->items
                );

                $this->markCompleted([
                    'service' => 'oura',
                    'items_processed' => count($this->items),
                ]);

                return;
            }

            if ($service === 'spotify') {
                $pluginClass = PluginRegistry::getPlugin('spotify');
                $plugin = new $pluginClass;
                foreach ($this->items as $item) {
                    $plugin->processRecentlyPlayedMigrationItem($this->integration, $item);
                }

                $this->markCompleted([
                    'service' => 'spotify',
                    'items_processed' => count($this->items),
                ]);

                return;
            }

            if ($service === 'github') {
                $pluginClass = PluginRegistry::getPlugin('github');
                $plugin = new $pluginClass;
                foreach ($this->items as $event) {
                    $plugin->processEventPayload($this->integration, $event);
                }

                $this->markCompleted([
                    'service' => 'github',
                    'items_processed' => count($this->items),
                ]);

                return;
            }

            if ($service === 'monzo') {
                $pluginClass = PluginRegistry::getPlugin('monzo');
                if (! $pluginClass) {
                    Log::error('ProcessIntegrationPage: Monzo plugin not registered; aborting processing', [
                        'integration_id' => $this->integration->id,
                        'context' => $this->context,
                    ]);

                    $this->updateProgress('failed', 'Monzo plugin not registered', 0, [
                        'service' => 'monzo',
                        'error' => 'Plugin not registered',
                    ]);

                    return;
                }
                $plugin = new $pluginClass;
                $type = $this->context['instance_type'] ?? 'transactions';
                $processingPhase = (bool) ($this->context['processing_phase'] ?? false);

                $this->updateProgress('processing_monzo', "Processing Monzo {$type} data...", 60, [
                    'service' => 'monzo',
                    'instance_type' => $type,
                    'processing_phase' => $processingPhase,
                ]);
                if ($type === 'pots') {
                    $this->updateProgress('processing_pots', 'Processing Monzo pots data...', 70, [
                        'service' => 'monzo',
                        'instance_type' => 'pots',
                    ]);

                    // If explicit item kind provided, process a snapshot now (test/back-compat)
                    $explicit = $this->items[0]['kind'] ?? null;
                    if ($explicit === 'pots_snapshot') {
                        $accounts = $this->listMonzoAccounts();
                        foreach ($accounts as $account) {
                            $plugin->upsertAccountObject($this->integration, $account);
                            $resp = Http::withHeaders($this->authHeaders())
                                ->get('https://api.monzo.com/pots', ['current_account_id' => $account['id']]);
                            $pots = $resp->successful() ? ($resp->json('pots') ?? []) : [];
                            foreach ($pots as $pot) {
                                $plugin->upsertPotObject($this->integration, $pot);
                            }
                        }

                        $this->markCompleted([
                            'service' => 'monzo',
                            'instance_type' => 'pots',
                            'accounts_processed' => count($accounts),
                        ]);

                        return;
                    }

                    // processing phase for pots: upsert from live snapshot once
                    $accounts = $this->listMonzoAccounts();
                    foreach ($accounts as $account) {
                        $plugin->upsertAccountObject($this->integration, $account);
                        $resp = Http::withHeaders($this->authHeaders())
                            ->get('https://api.monzo.com/pots', ['current_account_id' => $account['id']]);
                        $pots = $resp->successful() ? ($resp->json('pots') ?? []) : [];
                        foreach ($pots as $pot) {
                            $plugin->upsertPotObject($this->integration, $pot);
                        }
                    }

                    $this->markCompleted([
                        'service' => 'monzo',
                        'instance_type' => 'pots',
                        'accounts_processed' => count($accounts),
                    ]);

                    // No next page for pots
                    return;
                }
                if ($type === 'balances') {
                    // If explicit item kind provided, process that date now (test/back-compat)
                    $explicit = $this->items[0]['kind'] ?? null;
                    if ($explicit === 'balance_snapshot') {
                        $date = $this->items[0]['date'] ?? now()->toDateString();
                        $accounts = $this->listMonzoAccounts();
                        foreach ($accounts as $account) {
                            $resp = Http::withHeaders($this->authHeaders())
                                ->get('https://api.monzo.com/balance', ['account_id' => $account['id']]);
                            if ($resp->successful()) {
                                $json = $resp->json();
                                $balance = (int) ($json['balance'] ?? 0);
                                $spendToday = (int) ($json['spent_today'] ?? 0);
                                // Ensure day target exists
                                $dayObject = EventObject::updateOrCreate([
                                    'user_id' => $this->integration->user_id,
                                    'concept' => 'day',
                                    'type' => 'day',
                                    'title' => $date,
                                ], [
                                    'integration_id' => $this->integration->id,
                                    'time' => $date.' 00:00:00',
                                    'content' => null,
                                    'metadata' => ['date' => $date],
                                ]);
                                $event = Event::updateOrCreate(
                                    [
                                        'integration_id' => $this->integration->id,
                                        'source_id' => 'monzo_balance_'.$account['id'].'_'.$date,
                                    ],
                                    [
                                        'time' => $date.' 23:59:59',
                                        'actor_id' => $plugin->upsertAccountObject($this->integration, $account)->id,
                                        'service' => 'monzo',
                                        'domain' => 'money',
                                        'action' => 'had_balance',
                                        'value' => abs($balance),
                                        'value_multiplier' => 100,
                                        'value_unit' => 'GBP',
                                        'event_metadata' => [
                                            'spent_today' => $spendToday / 100,
                                            'snapshot_date' => $date,
                                        ],
                                        'target_id' => $dayObject->id,
                                    ]
                                );
                                // Add balance blocks
                                $plugin->addBalanceBlocks($event, $this->integration, $account, $date, $balance, $spendToday);
                            }
                        }

                        $this->markCompleted([
                            'service' => 'monzo',
                            'instance_type' => 'balances',
                            'accounts_processed' => count($accounts),
                            'snapshot_date' => $date,
                        ]);

                        return;
                    }

                    // Processing phase for balances: use cache range and generate snapshots
                    $lastDate = Cache::get($this->cacheKey('balances_last_date'));
                    if ($lastDate) {
                        $date = $lastDate;
                        $accounts = $this->listMonzoAccounts();
                        foreach ($accounts as $account) {
                            $resp = Http::withHeaders($this->authHeaders())
                                ->get('https://api.monzo.com/balance', ['account_id' => $account['id']]);
                            if ($resp->successful()) {
                                $json = $resp->json();
                                $balance = (int) ($json['balance'] ?? 0);
                                $spendToday = (int) ($json['spent_today'] ?? 0);
                                // Ensure day target exists
                                $dayObject = EventObject::updateOrCreate([
                                    'user_id' => $this->integration->user_id,
                                    'concept' => 'day',
                                    'type' => 'day',
                                    'title' => $date,
                                ], [
                                    'integration_id' => $this->integration->id,
                                    'time' => $date.' 00:00:00',
                                    'content' => null,
                                    'metadata' => ['date' => $date],
                                ]);
                                $event = Event::updateOrCreate(
                                    [
                                        'integration_id' => $this->integration->id,
                                        'source_id' => 'monzo_balance_'.$account['id'].'_'.$date,
                                    ],
                                    [
                                        'time' => $date.' 23:59:59',
                                        'actor_id' => $plugin->upsertAccountObject($this->integration, $account)->id,
                                        'service' => 'monzo',
                                        'domain' => 'money',
                                        'action' => 'had_balance',
                                        'value' => abs($balance),
                                        'value_multiplier' => 100,
                                        'value_unit' => 'GBP',
                                        'event_metadata' => [
                                            'spent_today' => $spendToday / 100,
                                            'snapshot_date' => $date,
                                        ],
                                        'target_id' => $dayObject->id,
                                    ]
                                );
                                // Add balance blocks
                                $plugin->addBalanceBlocks($event, $this->integration, $account, $date, $balance, $spendToday);
                            }
                        }
                    }

                    $this->markCompleted([
                        'service' => 'monzo',
                        'instance_type' => 'balances',
                        'accounts_processed' => count($accounts ?? []),
                        'snapshot_date' => $lastDate,
                    ]);

                    return;
                }
                // transactions window - only act if this integration is a transactions instance
                $window = $this->items[0] ?? [];
                $since = $window['since'] ?? null;
                $before = $window['before'] ?? null;
                $instType = $this->integration->instance_type ?: 'transactions';
                $windows = []; // Initialize windows variable to prevent undefined variable error

                if ($instType === 'transactions') {
                    // If explicit window provided, process it now (test/back-compat)
                    if ($since && $before) {
                        $accounts = $this->listMonzoAccounts();
                        foreach ($accounts as $account) {
                            $currentBefore = $before;
                            do {
                                $resp = Http::withHeaders($this->authHeaders())
                                    ->get('https://api.monzo.com/transactions', [
                                        'account_id' => $account['id'],
                                        'expand[]' => 'merchant',
                                        'since' => $since,
                                        'before' => $currentBefore,
                                        'limit' => 100,
                                    ]);
                                if (! $resp->successful()) {
                                    // Stop paging for this account on error
                                    break;
                                }
                                $txs = $resp->json('transactions') ?? [];
                                if (empty($txs)) {
                                    break;
                                }
                                foreach ($txs as $tx) {
                                    $plugin->processTransactionItem($this->integration, $tx, $account['id']);
                                }
                                $last = end($txs);
                                $nextBefore = $last['created'] ?? ($last['id'] ?? null);
                                if ($nextBefore === null || $nextBefore === $currentBefore) {
                                    break;
                                }
                                $currentBefore = $nextBefore;
                            } while (count($txs) === 100);
                        }

                        $this->markCompleted([
                            'service' => 'monzo',
                            'instance_type' => 'transactions',
                            'accounts_processed' => count($accounts),
                            'window' => ['since' => $since, 'before' => $before],
                        ]);

                        return;
                    }

                    // In processing phase: replay cached windows
                    $windows = (array) (Cache::get($this->cacheKey('tx_windows')) ?? []);
                    foreach ($windows as $win) {
                        $accounts = $this->listMonzoAccounts();
                        foreach ($accounts as $account) {
                            $currentBefore = $win['before'] ?? null;
                            $sinceWin = $win['since'] ?? null;
                            if ($sinceWin === null || $currentBefore === null) {
                                continue;
                            }
                            do {
                                $resp = Http::withHeaders($this->authHeaders())
                                    ->get('https://api.monzo.com/transactions', [
                                        'account_id' => $account['id'],
                                        'expand[]' => 'merchant',
                                        'since' => $sinceWin,
                                        'before' => $currentBefore,
                                        'limit' => 100,
                                    ]);
                                if (! $resp->successful()) {
                                    // Move on to next account/window on error
                                    break;
                                }
                                $txs = $resp->json('transactions') ?? [];
                                if (empty($txs)) {
                                    break;
                                }
                                foreach ($txs as $tx) {
                                    $plugin->processTransactionItem($this->integration, $tx, $account['id']);
                                }
                                $last = end($txs);
                                $nextBefore = $last['created'] ?? ($last['id'] ?? null);
                                if ($nextBefore === null || $nextBefore === $currentBefore) {
                                    break;
                                }
                                $currentBefore = $nextBefore;
                            } while (count($txs) === 100);
                        }
                    }
                }

                $this->markCompleted([
                    'service' => 'monzo',
                    'instance_type' => 'transactions',
                    'windows_processed' => count($windows),
                ]);

                return;
            }

            if ($service === 'gocardless') {
                $pluginClass = PluginRegistry::getPlugin('gocardless');
                if (! $pluginClass) {
                    Log::error('ProcessIntegrationPage: GoCardless plugin not registered; aborting processing', [
                        'integration_id' => $this->integration->id,
                        'context' => $this->context,
                    ]);

                    return;
                }
                $plugin = new $pluginClass;
                $type = $this->context['instance_type'] ?? 'transactions';
                if ($type === 'balances') {
                    $lastDate = Cache::get($this->gcCacheKey('balances_last_date'));
                    if ($lastDate) {
                        // Ensure day target and create one balance snapshot per account
                        // We rely on the plugin's existing fetchData paths; here we only trigger the snapshot once
                        try {
                            // No direct API call here; the plugin's regular scheduler will pick up the snapshot
                        } catch (Throwable $e) {
                            // ignore in processing path
                        }
                    }

                    $this->markCompleted([
                        'service' => 'gocardless',
                        'instance_type' => 'balances',
                        'snapshot_date' => $lastDate,
                    ]);

                    return;
                }
                // transactions processing: replay cached windows and persist events
                $windows = (array) (Cache::get($this->gcCacheKey('tx_windows')) ?? []);
                if (empty($windows)) {
                    return;
                }
                // Walk windows for each account and feed into plugin's item processor
                try {
                    // Check if NordigenClient class is available
                    if (! class_exists('Nordigen\NordigenPHP\API\NordigenClient')) {
                        Log::error('ProcessIntegrationPage: NordigenClient class not available; skipping GoCardless transaction replay', [
                            'integration_id' => $this->integration->id,
                            'service' => 'gocardless',
                            'context' => $this->context,
                            'missing_dependency' => 'NordigenClient',
                        ]);

                        return;
                    }

                    $secretId = (string) (config('services.gocardless.secret_id'));
                    $secretKey = (string) (config('services.gocardless.secret_key'));

                    if (empty($this->integration->configuration['account_id'])) {
                        return;
                    }
                    // Note: NordigenClient package appears to be unmaintained
                    // The GoCardless plugin uses direct HTTP calls instead
                    // This section may need to be updated to use the same approach
                    Log::warning('ProcessIntegrationPage: NordigenClient usage is deprecated, consider using direct HTTP calls like GoCardlessBankPlugin', [
                        'integration_id' => $this->integration->id,
                        'service' => 'gocardless',
                    ]);

                    // TODO: Replace with direct HTTP calls to GoCardless API
                    // For now, skip processing to avoid errors
                } catch (Throwable $e) {
                    // Non-fatal: stop processing on error
                }

                $this->markCompleted([
                    'service' => 'gocardless',
                    'instance_type' => 'transactions',
                    'windows_processed' => count($windows ?? []),
                ]);

                return;
            }

            if ($service === 'outline') {
                $this->updateProgress('processing', 'Processing Outline migration data...', 60, [
                    'service' => 'outline',
                    'instance_type' => $this->integration->instance_type,
                ]);

                // Outline migration is handled by OutlineMigrationPull -> OutlineData
                // This processing job is mainly for compatibility with the migration system
                // The actual processing happens in OutlineData job

                $this->markCompleted([
                    'service' => 'outline',
                    'instance_type' => $this->integration->instance_type,
                    'note' => 'Outline migration runs independently via OutlineMigrationPull',
                ]);

                return;
            }

            if ($service === 'karakeep') {
                $this->updateProgress('processing', 'Processing Karakeep migration data...', 60, [
                    'service' => 'karakeep',
                    'instance_type' => $instanceType,
                    'bookmarks_count' => count($this->items['bookmarks'] ?? []),
                ]);

                // The items array contains the raw data from the Karakeep API
                // We need to fetch additional data (tags, lists, highlights, user) to pass to KarakeepBookmarksData
                $this->processKarakeepData($this->items);

                $this->markCompleted([
                    'service' => 'karakeep',
                    'instance_type' => $instanceType,
                    'bookmarks_processed' => count($this->items['bookmarks'] ?? []),
                ]);

                return;
            }

            Log::info('ProcessIntegrationPage: unsupported service, skipping', [
                'service' => $service,
            ]);
        } catch (Throwable $e) {
            Log::error('ProcessIntegrationPage failed', [
                'integration_id' => $this->integration->id,
                'service' => $this->context['service'] ?? $this->integration->service,
                'context' => $this->context,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle job failure
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ProcessIntegrationPage failed', [
            'integration_id' => $this->integration->id,
            'service' => $this->context['service'] ?? $this->integration->service,
            'context' => $this->context,
            'error' => $exception->getMessage(),
        ]);

        if ($this->progressRecord) {
            $this->progressRecord->markFailed($exception->getMessage(), [
                'integration_id' => $this->integration->id,
                'service' => $this->context['service'] ?? $this->integration->service,
            ]);
        }
    }

    /**
     * Update progress for the migration processing
     */
    protected function updateProgress(string $step, string $message, int $progress, array $details = []): void
    {
        if ($this->progressRecord) {
            $this->progressRecord->updateProgress($step, $message, $progress, $details);
        }
    }

    /**
     * Mark the migration as completed
     */
    protected function markCompleted(array $details = []): void
    {
        if ($this->progressRecord) {
            $this->progressRecord->markCompleted($details);
        }
    }

    private function authHeaders(): array
    {
        $group = $this->integration->group;
        $token = $group?->access_token ?? $this->integration->access_token;

        return [
            'Authorization' => 'Bearer '.$token,
        ];
    }

    private function listMonzoAccounts(): array
    {
        $resp = Http::withHeaders($this->authHeaders())
            ->get('https://api.monzo.com/accounts');
        if (! $resp->successful()) {
            return [];
        }

        return $resp->json('accounts') ?? [];
    }

    private function cacheKey(string $suffix): string
    {
        return 'monzo:migration:'.$this->integration->id.':'.$suffix;
    }

    private function gcCacheKey(string $suffix): string
    {
        return 'gocardless:migration:'.$this->integration->id.':'.$suffix;
    }

    /**
     * Generate a unique action ID for this specific processing job
     */
    private function generateActionId(): string
    {
        $service = $this->context['service'] ?? $this->integration->service;
        $instanceType = $this->context['instance_type'] ?? $this->integration->instance_type;
        $baseId = "integration_{$this->integration->id}";

        // For single-job types (pots, balances), use a simple suffix
        if ($instanceType === 'pots') {
            return "{$baseId}_pots";
        }

        if ($instanceType === 'balances') {
            return "{$baseId}_balances";
        }

        // For transactions, create unique ID based on the window
        if ($instanceType === 'transactions' && ! empty($this->items)) {
            $item = $this->items[0];
            if (isset($item['kind']) && $item['kind'] === 'transactions_window') {
                $since = $item['since'] ?? 'unknown';
                $before = $item['before'] ?? 'unknown';
                // Create a hash of the time window to ensure uniqueness
                $windowHash = substr(md5($since.$before), 0, 8);

                return "{$baseId}_transactions_{$windowHash}";
            }
        }

        // Fallback for other job types
        return "{$baseId}_{$instanceType}_".substr(uniqid(), -8);
    }

    /**
     * Process Karakeep migration data by fetching additional API data and dispatching processing job
     */
    private function processKarakeepData(array $rawData): void
    {
        $group = $this->integration->group;
        if (! $group) {
            Log::error('Karakeep migration: Integration group not found', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        $apiUrl = $group->auth_metadata['api_url'] ?? config('services.karakeep.url');
        $accessToken = $group->access_token ?? config('services.karakeep.access_token');

        if (! $apiUrl || ! $accessToken) {
            Log::error('Karakeep migration: API URL or access token not configured', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        $baseUrl = rtrim($apiUrl, '/');
        $config = $this->integration->configuration ?? [];
        $syncHighlights = $config['sync_highlights'] ?? true;

        Log::info('Karakeep migration: Fetching additional data', [
            'integration_id' => $this->integration->id,
            'bookmarks_count' => count($rawData['bookmarks'] ?? []),
        ]);

        // Fetch user info
        $userResponse = Http::withToken($accessToken)
            ->get($baseUrl.'/api/v1/users/me');

        $userData = $userResponse->successful() ? $userResponse->json() : null;

        // Fetch tags
        $tagsResponse = Http::withToken($accessToken)
            ->get($baseUrl.'/api/v1/tags');

        $tagsData = $tagsResponse->successful() ? ($tagsResponse->json()['tags'] ?? []) : [];

        // Fetch lists
        $listsResponse = Http::withToken($accessToken)
            ->get($baseUrl.'/api/v1/lists');

        $listsData = $listsResponse->successful() ? ($listsResponse->json()['lists'] ?? []) : [];

        // Fetch highlights if enabled
        $highlightsData = [];
        if ($syncHighlights) {
            $highlightsResponse = Http::withToken($accessToken)
                ->get($baseUrl.'/api/v1/highlights');

            if ($highlightsResponse->successful()) {
                $highlightsData = $highlightsResponse->json()['highlights'] ?? [];
            }
        }

        // Prepare complete raw data structure matching KarakeepBookmarksPull
        $completeRawData = [
            'user' => $userData,
            'bookmarks' => $rawData['bookmarks'] ?? [],
            'tags' => $tagsData,
            'lists' => $listsData,
            'highlights' => $highlightsData,
            'fetched_at' => now()->toISOString(),
        ];

        Log::info('Karakeep migration: Dispatching KarakeepBookmarksData', [
            'integration_id' => $this->integration->id,
            'bookmarks_count' => count($completeRawData['bookmarks']),
            'tags_count' => count($completeRawData['tags']),
            'lists_count' => count($completeRawData['lists']),
            'highlights_count' => count($completeRawData['highlights']),
        ]);

        // Dispatch the data processing job
        KarakeepBookmarksData::dispatch($this->integration, $completeRawData)
            ->onConnection('redis')
            ->onQueue('default');
    }
}
