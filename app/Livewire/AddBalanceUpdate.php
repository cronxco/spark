<?php

namespace App\Livewire;

use App\Integrations\Financial\FinancialPlugin;
use App\Models\EventObject;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AddBalanceUpdate extends Component
{
    public string $accountId = '';
    public ?float $balance = null;
    public ?string $date = null;
    public ?string $notes = null;

    protected $rules = [
        'accountId' => 'required|string|exists:objects,id',
        'balance' => 'required|numeric',
        'date' => 'required|date',
        'notes' => 'nullable|string|max:1000',
    ];

    public function mount(): void
    {
        $this->date = now()->format('Y-m-d');
    }

    public function save(): void
    {
        $this->validate();

        // Check if user owns the account
        $account = EventObject::where('id', $this->accountId)
            ->where('user_id', Auth::id())
            ->where('concept', 'account')
            ->where('type', 'financial_account')
            ->firstOrFail();

        // Get the integration for this account
        $integration = $account->integration;
        if (! $integration) {
            abort(404, 'Integration not found');
        }

        // Create the balance update event using the plugin
        $plugin = new FinancialPlugin;
        $balanceData = [
            'balance' => $this->balance,
            'date' => $this->date,
            'notes' => $this->notes,
        ];

        $plugin->createBalanceEvent($integration, $account, $balanceData);

        $this->dispatch('balance-updated');
        $this->reset(['balance', 'notes']);
        $this->date = now()->format('Y-m-d');
    }

    public function render(): View
    {
        $plugin = new FinancialPlugin;
        $accounts = $plugin->getFinancialAccounts(Auth::user());

        return view('livewire.add-balance-update', [
            'accounts' => $accounts,
        ]);
    }
}
