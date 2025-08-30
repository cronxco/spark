<?php

namespace App\Livewire;

use App\Integrations\Financial\FinancialPlugin;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class AddBalanceUpdate extends Component
{
    public string $accountId = '';
    public ?float $balance = null;
    public ?string $date = null;
    public ?string $notes = null;
    public bool $isAccountPreselected = false;

    protected $rules = [
        'accountId' => 'required|string|exists:objects,id',
        'balance' => 'required|numeric',
        'date' => 'required|date',
        'notes' => 'nullable|string|max:1000',
    ];

    public function mount(?string $account = null): void
    {
        $this->date = now()->format('Y-m-d');

        // If an account ID is provided, validate it and pre-select it
        if ($account) {
            $this->validateAccount($account);
            $this->accountId = $account;
            $this->isAccountPreselected = true;
        }
    }

    public function save(): void
    {
        $this->validate();

        // Check if user owns the account and it's a manual account
        $account = EventObject::where('id', $this->accountId)
            ->where('user_id', Auth::id())
            ->where('concept', 'account')
            ->where('type', 'manual_account')
            ->firstOrFail();

        Log::info('Adding balance update', [
            'account_id' => $account->id,
            'account_name' => $account->metadata['name'] ?? 'Unknown',
            'balance' => $this->balance,
            'date' => $this->date,
            'user_id' => Auth::id(),
        ]);

        // Get the integration for this account from metadata
        $integrationId = $account->metadata['integration_id'] ?? null;
        Log::info('Looking up integration for account', [
            'account_id' => $account->id,
            'integration_id_from_metadata' => $integrationId,
            'account_name' => $account->metadata['name'] ?? 'Unknown',
        ]);

        if (! $integrationId) {
            // Try to find the integration by looking up the account name and user
            $integration = Integration::where('user_id', Auth::id())
                ->where('service', 'manual_account')
                ->where('name', $account->metadata['name'] ?? '')
                ->first();

            if (! $integration) {
                Log::error('Integration not found by name lookup', [
                    'account_id' => $account->id,
                    'account_name' => $account->metadata['name'] ?? 'Unknown',
                    'user_id' => Auth::id(),
                ]);
                abort(404, 'Integration not found for this account. Please recreate the account.');
            }

            Log::info('Integration found by name lookup', [
                'integration_id' => $integration->id,
                'integration_name' => $integration->name,
            ]);
        } else {
            $integration = Integration::where('id', $integrationId)
                ->where('user_id', Auth::id())
                ->first();
            if (! $integration) {
                Log::error('Integration not found by ID lookup', [
                    'integration_id' => $integrationId,
                    'account_id' => $account->id,
                    'user_id' => Auth::id(),
                ]);
                abort(404, 'Integration not found or access denied');
            }

            Log::info('Integration found by ID lookup', [
                'integration_id' => $integration->id,
                'integration_name' => $integration->name,
            ]);
        }

        // Create the balance update event using the plugin
        $plugin = new FinancialPlugin;
        $balanceData = [
            'balance' => $this->balance,
            'date' => $this->date,
            'notes' => $this->notes,
        ];

        $event = $plugin->createBalanceEvent($integration, $account, $balanceData);

        Log::info('Balance update created successfully', [
            'event_id' => $event->id,
            'account_id' => $account->id,
            'integration_id' => $integration->id,
            'balance' => $this->balance,
            'date' => $this->date,
        ]);

        $this->dispatch('balance-updated');
        $this->reset(['balance', 'notes']);
        $this->date = now()->format('Y-m-d');
    }

    public function render(): View
    {
        $plugin = new FinancialPlugin;
        $accounts = $plugin->getManualFinancialAccounts(Auth::user());

        return view('livewire.add-balance-update', [
            'accounts' => $accounts,
        ]);
    }

    protected function validateAccount(string $accountId): void
    {
        $account = EventObject::where('id', $accountId)
            ->where('user_id', Auth::id())
            ->where('concept', 'account')
            ->where('type', 'manual_account')
            ->first();

        if (! $account) {
            abort(404, 'Account not found or access denied. Only manual accounts can have balance updates added.');
        }
    }
}
