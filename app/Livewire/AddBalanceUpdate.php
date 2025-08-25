<?php

namespace App\Livewire;

use App\Models\FinancialAccount;
use App\Models\FinancialBalance;
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
        'accountId' => 'required|string|exists:financial_accounts,id',
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
        $account = FinancialAccount::where('id', $this->accountId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        // Create the balance update
        FinancialBalance::create([
            'user_id' => Auth::id(),
            'financial_account_id' => $this->accountId,
            'balance' => $this->balance,
            'date' => $this->date,
            'notes' => $this->notes,
        ]);

        $this->dispatch('balance-updated');
        $this->reset(['balance', 'notes']);
        $this->date = now()->format('Y-m-d');
    }

    public function render(): View
    {
        $accounts = FinancialAccount::where('user_id', Auth::id())
            ->orderBy('name')
            ->get();

        return view('livewire.add-balance-update', [
            'accounts' => $accounts,
        ]);
    }
}