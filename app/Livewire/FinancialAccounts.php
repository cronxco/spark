<?php

namespace App\Livewire;

use App\Models\FinancialAccount;
use App\Models\Integration;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Financial Accounts')]
class FinancialAccounts extends Component
{
    use WithPagination;

    public ?string $search = null;
    public ?string $accountTypeFilter = null;
    public ?string $providerFilter = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'accountTypeFilter' => ['except' => ''],
        'providerFilter' => ['except' => ''],
    ];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedAccountTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedProviderFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'accountTypeFilter', 'providerFilter']);
        $this->resetPage();
    }

    public function deleteAccount(FinancialAccount $account): void
    {
        if ($account->user_id !== Auth::id()) {
            abort(403);
        }

        $account->delete();
        $this->dispatch('account-deleted');
    }

    public function render(): View
    {
        $query = FinancialAccount::where('user_id', Auth::id())
            ->with(['integration', 'latestBalance']);

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'ilike', '%' . $this->search . '%')
                    ->orWhere('provider', 'ilike', '%' . $this->search . '%')
                    ->orWhere('account_number', 'ilike', '%' . $this->search . '%');
            });
        }

        if ($this->accountTypeFilter) {
            $query->where('account_type', $this->accountTypeFilter);
        }

        if ($this->providerFilter) {
            $query->where('provider', $this->providerFilter);
        }

        $accounts = $query->orderBy('name')
            ->paginate(10);

        $accountTypes = FinancialAccount::where('user_id', Auth::id())
            ->distinct()
            ->pluck('account_type')
            ->mapWithKeys(function ($type) {
                return [$type => $this->getAccountTypeLabel($type)];
            })
            ->sort();

        $providers = FinancialAccount::where('user_id', Auth::id())
            ->distinct()
            ->pluck('provider')
            ->sort();

        return view('livewire.financial-accounts', [
            'accounts' => $accounts,
            'accountTypes' => $accountTypes,
            'providers' => $providers,
        ]);
    }

    private function getAccountTypeLabel(string $type): string
    {
        $labels = [
            'current_account' => 'Current Account',
            'savings_account' => 'Savings Account',
            'mortgage' => 'Mortgage',
            'investment_account' => 'Investment Account',
            'credit_card' => 'Credit Card',
            'loan' => 'Loan',
            'pension' => 'Pension',
            'other' => 'Other',
        ];

        return $labels[$type] ?? $type;
    }
}