<?php

namespace App\Jobs\Migrations;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        if (! $pluginClass) {
            return;
        }
        $plugin = new $pluginClass;

        // Ensure we have a valid access token (refresh if needed)
        $token = $this->getValidAccessToken();
        if (empty($token)) {
            return;
        }

        // List accounts with retry on 401 (unauthorized)
        $accountsResp = Http::withToken($token)->get('https://api.monzo.com/accounts');
        if ($accountsResp->status() === 401) {
            $token = $this->getValidAccessToken(true);
            if (empty($token)) {
                return;
            }
            $accountsResp = Http::withToken($token)->get('https://api.monzo.com/accounts');
        }
        if (! $accountsResp->successful()) {
            return;
        }
        $accounts = $accountsResp->json('accounts') ?? [];

        // Seed account objects (no events)
        foreach ($accounts as $account) {
            $plugin->upsertAccountObject($this->integration, $account);
            // Also seed pots for this current account
            $potsResp = Http::withToken($token)
                ->get('https://api.monzo.com/pots', ['current_account_id' => $account['id']]);
            if ($potsResp->status() === 401) {
                $token = $this->getValidAccessToken(true);
                if (empty($token)) {
                    return;
                }
                $potsResp = Http::withToken($token)
                    ->get('https://api.monzo.com/pots', ['current_account_id' => $account['id']]);
            }
            if ($potsResp->successful()) {
                $pots = $potsResp->json('pots') ?? [];
                foreach ($pots as $pot) {
                    $plugin->upsertPotObject($this->integration, $pot);
                }
            }
        }
    }

    private function getValidAccessToken(bool $forceRefresh = false): ?string
    {
        $group = $this->integration->group;
        $token = $group?->access_token ?? $this->integration->access_token;
        $expired = $group?->expiry && $group->expiry->isPast();

        if ($forceRefresh || empty($token) || $expired) {
            if ($group) {
                $token = $this->refreshGroupToken($group);
            }
        }

        return $token ?: null;
    }

    private function refreshGroupToken(IntegrationGroup $group): ?string
    {
        $clientId = config('services.monzo.client_id');
        $clientSecret = config('services.monzo.client_secret');
        $refreshToken = $group->refresh_token;

        if (empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
            Log::warning('Monzo refresh skipped due to missing credentials or refresh_token', [
                'group_id' => $group->id,
            ]);

            return null;
        }

        $resp = Http::asForm()->post('https://api.monzo.com/oauth2/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]);

        if (! $resp->successful()) {
            Log::error('Monzo token refresh failed', [
                'group_id' => $group->id,
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);

            return null;
        }

        $data = $resp->json();
        $group->update([
            'access_token' => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? $group->refresh_token,
            'expiry' => isset($data['expires_in']) ? now()->addSeconds((int) $data['expires_in']) : null,
        ]);

        return $group->access_token;
    }
}
