<?php

namespace App\Jobs\Migrations;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessIntegrationPage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public int $timeout = 300;
    public int $tries = 3;
    public array $backoff = [60, 300, 600];

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

        try {
            $service = $this->context['service'] ?? $this->integration->service;

            if ($service === 'oura') {
                $pluginClass = PluginRegistry::getPlugin('oura');
                (new $pluginClass())->processOuraMigrationItems(
                    $this->integration,
                    $this->context['instance_type'] ?? ($this->integration->instance_type ?: 'activity'),
                    $this->items
                );
                return;
            }

            if ($service === 'spotify') {
                $pluginClass = PluginRegistry::getPlugin('spotify');
                $plugin = new $pluginClass();
                foreach ($this->items as $item) {
                    $plugin->processRecentlyPlayedMigrationItem($this->integration, $item);
                }
                return;
            }

            if ($service === 'github') {
                $pluginClass = PluginRegistry::getPlugin('github');
                $plugin = new $pluginClass();
                foreach ($this->items as $event) {
                    $plugin->processEventPayload($this->integration, $event);
                }
                return;
            }

            if ($service === 'monzo') {
                $pluginClass = PluginRegistry::getPlugin('monzo');
                if (!$pluginClass) {
                    Log::error('ProcessIntegrationPage: Monzo plugin not registered; aborting processing', [
                        'integration_id' => $this->integration->id,
                        'context' => $this->context,
                    ]);
                    return;
                }
                $plugin = new $pluginClass();
                $type = $this->context['instance_type'] ?? 'transactions';
                $processingPhase = (bool) ($this->context['processing_phase'] ?? false);
                if ($type === 'pots') {
                    // If explicit item kind provided, process a snapshot now (test/back-compat)
                    $explicit = $this->items[0]['kind'] ?? null;
                    if ($explicit === 'pots_snapshot') {
                        $accounts = $this->listMonzoAccounts();
                        foreach ($accounts as $account) {
                            $plugin->upsertAccountObject($this->integration, $account);
                            $resp = \Illuminate\Support\Facades\Http::withHeaders($this->authHeaders())
                                ->get('https://api.monzo.com/pots', ['current_account_id' => $account['id']]);
                            $pots = $resp->successful() ? ($resp->json('pots') ?? []) : [];
                            foreach ($pots as $pot) {
                                $plugin->upsertPotObject($this->integration, $pot);
                            }
                        }
                        return;
                    }

                    // processing phase for pots: upsert from live snapshot once
                    $accounts = $this->listMonzoAccounts();
                    foreach ($accounts as $account) {
                        $plugin->upsertAccountObject($this->integration, $account);
                        $resp = \Illuminate\Support\Facades\Http::withHeaders($this->authHeaders())
                            ->get('https://api.monzo.com/pots', ['current_account_id' => $account['id']]);
                        $pots = $resp->successful() ? ($resp->json('pots') ?? []) : [];
                        foreach ($pots as $pot) {
                            $plugin->upsertPotObject($this->integration, $pot);
                        }
                    }
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
                            $resp = \Illuminate\Support\Facades\Http::withHeaders($this->authHeaders())
                                ->get('https://api.monzo.com/balance', ['account_id' => $account['id']]);
                            if ($resp->successful()) {
                                $json = $resp->json();
                                $balance = (int) ($json['balance'] ?? 0);
                                $spendToday = (int) ($json['spend_today'] ?? 0);
                                // Ensure day target exists
                                $dayObject = \App\Models\EventObject::updateOrCreate([
                                    'integration_id' => $this->integration->id,
                                    'concept' => 'day',
                                    'type' => 'day',
                                    'title' => $date,
                                ], [
                                    'time' => $date . ' 00:00:00',
                                    'content' => null,
                                    'metadata' => ['date' => $date],
                                ]);
                                $event = \App\Models\Event::updateOrCreate(
                                    [
                                        'integration_id' => $this->integration->id,
                                        'source_id' => 'monzo_balance_' . $account['id'] . '_' . $date,
                                    ],
                                    [
                                        'time' => $date . ' 23:59:59',
                                        'actor_id' => $plugin->upsertAccountObject($this->integration, $account)->id,
                                        'service' => 'monzo',
                                        'domain' => 'money',
                                        'action' => 'had_balance',
                                        'value' => abs($balance),
                                        'value_multiplier' => 100,
                                        'value_unit' => 'GBP',
                                        'event_metadata' => [
                                            'spend_today' => $spendToday / 100,
                                            'snapshot_date' => $date,
                                        ],
                                        'target_id' => $dayObject->id,
                                    ]
                                );
                                // Add balance blocks
                                $plugin->addBalanceBlocks($event, $this->integration, $account, $date, $balance, $spendToday);
                            }
                        }
                        return;
                    }

                    // Processing phase for balances: use cache range and generate snapshots
                    $lastDate = \Illuminate\Support\Facades\Cache::get($this->cacheKey('balances_last_date'));
                    if ($lastDate) {
                        $date = $lastDate;
                        $accounts = $this->listMonzoAccounts();
                        foreach ($accounts as $account) {
                            $resp = \Illuminate\Support\Facades\Http::withHeaders($this->authHeaders())
                                ->get('https://api.monzo.com/balance', ['account_id' => $account['id']]);
                            if ($resp->successful()) {
                                $json = $resp->json();
                                $balance = (int) ($json['balance'] ?? 0);
                                $spendToday = (int) ($json['spend_today'] ?? 0);
                                // Ensure day target exists
                                $dayObject = \App\Models\EventObject::updateOrCreate([
                                    'integration_id' => $this->integration->id,
                                    'concept' => 'day',
                                    'type' => 'day',
                                    'title' => $date,
                                ], [
                                    'time' => $date . ' 00:00:00',
                                    'content' => null,
                                    'metadata' => ['date' => $date],
                                ]);
                                $event = \App\Models\Event::updateOrCreate(
                                    [
                                        'integration_id' => $this->integration->id,
                                        'source_id' => 'monzo_balance_' . $account['id'] . '_' . $date,
                                    ],
                                    [
                                        'time' => $date . ' 23:59:59',
                                        'actor_id' => $plugin->upsertAccountObject($this->integration, $account)->id,
                                        'service' => 'monzo',
                                        'domain' => 'money',
                                        'action' => 'had_balance',
                                        'value' => abs($balance),
                                        'value_multiplier' => 100,
                                        'value_unit' => 'GBP',
                                        'event_metadata' => [
                                            'spend_today' => $spendToday / 100,
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
                    return;
                }
                // transactions window - only act if this integration is a transactions instance
                $window = $this->items[0] ?? [];
                $since = $window['since'] ?? null;
                $before = $window['before'] ?? null;
                $instType = $this->integration->instance_type ?: 'transactions';
                if ($instType === 'transactions') {
                    // If explicit window provided, process it now (test/back-compat)
                    if ($since && $before) {
                        $accounts = $this->listMonzoAccounts();
                        foreach ($accounts as $account) {
                            $currentBefore = $before;
                            do {
                                $resp = \Illuminate\Support\Facades\Http::withHeaders($this->authHeaders())
                                    ->get('https://api.monzo.com/transactions', [
                                        'account_id' => $account['id'],
                                        'expand[]' => 'merchant',
                                        'since' => $since,
                                        'before' => $currentBefore,
                                        'limit' => 100,
                                    ]);
                                if (!$resp->successful()) {
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
                        return;
                    }

                    // In processing phase: replay cached windows
                    $windows = (array) (\Illuminate\Support\Facades\Cache::get($this->cacheKey('tx_windows')) ?? []);
                    foreach ($windows as $win) {
                        $accounts = $this->listMonzoAccounts();
                        foreach ($accounts as $account) {
                            $currentBefore = $win['before'] ?? null;
                            $sinceWin = $win['since'] ?? null;
                            if ($sinceWin === null || $currentBefore === null) {
                                continue;
                            }
                            do {
                                $resp = \Illuminate\Support\Facades\Http::withHeaders($this->authHeaders())
                                    ->get('https://api.monzo.com/transactions', [
                                        'account_id' => $account['id'],
                                        'expand[]' => 'merchant',
                                        'since' => $sinceWin,
                                        'before' => $currentBefore,
                                        'limit' => 100,
                                    ]);
                                if (!$resp->successful()) {
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
                return;
            }

            Log::info('ProcessIntegrationPage: unsupported service, skipping', [
                'service' => $service,
            ]);
        } catch (\Throwable $e) {
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

    private function authHeaders(): array
    {
        $group = $this->integration->group;
        $token = $group?->access_token ?? $this->integration->access_token;
        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }

    private function listMonzoAccounts(): array
    {
        $resp = \Illuminate\Support\Facades\Http::withHeaders($this->authHeaders())
            ->get('https://api.monzo.com/accounts');
        if (!$resp->successful()) {
            return [];
        }
        return $resp->json('accounts') ?? [];
    }

    private function cacheKey(string $suffix): string
    {
        return 'monzo:migration:' . $this->integration->id . ':' . $suffix;
    }
}




