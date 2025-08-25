<?php

namespace App\Livewire;

use App\Integrations\Financial\FinancialPlugin;
use App\Models\Event;
use App\Models\EventObject;
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

    public function deleteAccount(EventObject $account): void
    {
        if ($account->user_id !== Auth::id()) {
            abort(403);
        }

        // Delete all related balance events first
        Event::where('actor_id', $account->id)
            ->where('service', 'financial')
            ->delete();

        // Delete the account object
        $account->delete();
        
        $this->dispatch('account-deleted');
    }

    public function render(): View
    {
        $plugin = new FinancialPlugin();
        
        $query = $plugin->getFinancialAccounts(Auth::user());

        // Apply filters
        if ($this->search) {
            $query = $query->filter(function ($account) {
                $metadata = $account->metadata;
                return str_contains(strtolower($metadata['name'] ?? ''), strtolower($this->search)) ||
                       str_contains(strtolower($metadata['provider'] ?? ''), strtolower($this->search)) ||
                       str_contains(strtolower($metadata['account_number'] ?? ''), strtolower($this->search));
            });
        }

        if ($this->accountTypeFilter) {
            $query = $query->filter(function ($account) {
                return ($account->metadata['account_type'] ?? '') === $this->accountTypeFilter;
            });
        }

        if ($this->providerFilter) {
            $query = $query->filter(function ($account) {
                return ($account->metadata['provider'] ?? '') === $this->providerFilter;
            });
        }

        // Get unique account types and providers for filters
        $allAccounts = $plugin->getFinancialAccounts(Auth::user());
        
        $accountTypes = $allAccounts->pluck('metadata.account_type')
            ->filter()
            ->unique()
            ->mapWithKeys(function ($type) {
                return [$type => $this->getAccountTypeLabel($type)];
            })
            ->sort();

        $providers = $allAccounts->pluck('metadata.provider')
            ->filter()
            ->unique()
            ->sort();

        // Paginate the filtered results
        $accounts = $query->forPage($this->page, 10);

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