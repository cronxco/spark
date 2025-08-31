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

#[Title('Money')]
class FinancialAccounts extends Component
{
    use WithPagination;

    public ?string $search = null;
    public ?string $accountTypeFilter = null;
    public ?string $providerFilter = null;
    public bool $showArchivedPots = false;

    protected $queryString = [
        'search' => ['except' => ''],
        'accountTypeFilter' => ['except' => ''],
        'providerFilter' => ['except' => ''],
        'showArchivedPots' => ['except' => false],
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
        $this->reset(['search', 'accountTypeFilter', 'providerFilter', 'showArchivedPots']);
        $this->resetPage();
    }

    public function deleteAccount(EventObject $account): void
    {
        if ($account->user_id !== Auth::id()) {
            abort(403);
        }

        // Delete all related balance events first
        Event::where('actor_id', $account->id)
            ->where('service', 'manual_account')
            ->delete();

        // Delete the account object
        $account->delete();

        $this->dispatch('account-deleted');
    }

    public function nextPage(): void
    {
        $this->setPage($this->getPage() + 1);
    }

    public function previousPage(): void
    {
        if ($this->getPage() > 1) {
            $this->setPage($this->getPage() - 1);
        }
    }

    public function render(): View
    {
        $plugin = new FinancialPlugin;

        // Get accounts based on archived pots preference
        if ($this->showArchivedPots) {
            // Include archived pots when toggle is enabled
            $accounts = EventObject::where('user_id', Auth::id())
                ->where('concept', 'account')
                ->whereIn('type', [
                    'manual_account',
                    'monzo_account',
                    'monzo_pot',
                    'monzo_archived_pot',
                    'bank_account',
                ])
                ->orderBy('title')
                ->get();
        } else {
            // Default: exclude archived pots
            $accounts = $plugin->getFinancialAccounts(Auth::user());
        }

        // Apply filters
        if ($this->search) {
            $accounts = $accounts->filter(function ($account) {
                $metadata = $account->metadata;

                return str_contains(strtolower($metadata['name'] ?? ''), strtolower($this->search)) ||
                       str_contains(strtolower($metadata['provider'] ?? ''), strtolower($this->search)) ||
                       str_contains(strtolower($metadata['account_number'] ?? ''), strtolower($this->search));
            });
        }

        if ($this->accountTypeFilter) {
            $accounts = $accounts->filter(function ($account) {
                return ($account->metadata['account_type'] ?? '') === $this->accountTypeFilter;
            });
        }

        if ($this->providerFilter) {
            $accounts = $accounts->filter(function ($account) {
                return ($account->metadata['provider'] ?? '') === $this->providerFilter;
            });
        }

        // Get unique account types and providers for filters
        // Use the same account set that's being displayed for consistent filtering
        $allAccounts = $this->showArchivedPots
            ? EventObject::where('user_id', Auth::id())
                ->where('concept', 'account')
                ->whereIn('type', [
                    'manual_account',
                    'monzo_account',
                    'monzo_pot',
                    'monzo_archived_pot',
                    'bank_account',
                ])
                ->get()
            : $plugin->getFinancialAccounts(Auth::user());

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

        // Implement manual pagination
        $perPage = 10;
        $currentPage = $this->getPage();
        $total = $accounts->count();
        $offset = ($currentPage - 1) * $perPage;
        $paginatedAccounts = $accounts->slice($offset, $perPage);

        // Create pagination data for the view
        $paginationData = [
            'perPage' => $perPage,
            'currentPage' => $currentPage,
            'total' => $total,
            'offset' => $offset,
            'lastPage' => ceil($total / $perPage),
            'hasMorePages' => $currentPage < ceil($total / $perPage),
            'hasPages' => $total > $perPage,
        ];

        return view('livewire.money.index', [
            'accounts' => $paginatedAccounts,
            'accountTypes' => $accountTypes,
            'providers' => $providers,
            'pagination' => $paginationData,
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

    /**
     * Get the properly formatted balance for an account, handling value_multiplier correctly
     */
    private function getFormattedBalance(EventObject $account): ?float
    {
        $plugin = new FinancialPlugin;
        $latestBalance = $plugin->getLatestBalance($account);

        if ($latestBalance) {
            if (isset($latestBalance->event_metadata['balance'])) {
                // Manual accounts store balance in event_metadata
                return $latestBalance->event_metadata['balance'];
            } else {
                // Monzo/GoCardless store balance in value field (integer cents)
                // Use the formatted_value attribute which automatically handles value_multiplier
                return $latestBalance->formatted_value;
            }
        } elseif (in_array($account->type, ['monzo_pot', 'monzo_archived_pot']) && ! empty(($account->metadata['balance'] ?? null))) {
            // Monzo pots store balance in metadata now
            return (float) ($account->metadata['balance'] ?? 0);
        }

        return null;
    }
}
