<?php

namespace App\Jobs\Migrations;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SeedMonzoAccounts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Integration $integration;

    public function __construct(Integration $integration)
    {
        $this->integration = $integration;
        $this->onConnection('redis');
        $this->onQueue('migration');
    }

    public function handle(): void
    {
        $pluginClass = PluginRegistry::getPlugin('monzo');
        if (!$pluginClass) {
            return;
        }
        $plugin = new $pluginClass();

        // List accounts
        $accountsResp = \Illuminate\Support\Facades\Http::withHeaders($this->authHeaders())
            ->get('https://api.monzo.com/accounts');
        if (!$accountsResp->successful()) {
            return;
        }
        $accounts = $accountsResp->json('accounts') ?? [];

        // Seed account objects (no events)
        foreach ($accounts as $account) {
            $plugin->upsertAccountObject($this->integration, $account);
            // Also seed pots for this current account
            $potsResp = \Illuminate\Support\Facades\Http::withHeaders($this->authHeaders())
                ->get('https://api.monzo.com/pots', ['current_account_id' => $account['id']]);
            if ($potsResp->successful()) {
                $pots = $potsResp->json('pots') ?? [];
                foreach ($pots as $pot) {
                    $plugin->upsertPotObject($this->integration, $pot);
                }
            }
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
}


