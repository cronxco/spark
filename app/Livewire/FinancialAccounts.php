<?php

namespace App\Livewire;

use App\Integrations\Financial\FinancialPlugin;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
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

    public bool $showArchived = false;

    public bool $showEmptyAccounts = true;

    public array $sortBy = ['column' => 'title', 'direction' => 'asc'];

    public int $perPage = 25;

    public bool $showAddBalanceModal = false;

    public bool $showCreateAccountModal = false;

    public string $viewMode = 'cards';

    public array $expandedSections = [
        'current_account' => true,
        'credit_card' => true,
        'savings_account' => true,
        'loan' => true,
        'mortgage' => true,
        'investment_account' => true,
        'pension' => true,
        'other' => true,
    ];

    protected $queryString = [
        'search' => ['except' => ''],
        'accountTypeFilter' => ['except' => ''],
        'providerFilter' => ['except' => ''],
        'showArchived' => ['except' => false],
        'showEmptyAccounts' => ['except' => true],
        'sortBy' => ['except' => ['column' => 'title', 'direction' => 'asc']],
        'perPage' => ['except' => 25],
        'viewMode' => ['except' => 'cards'],
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
        $this->reset(['search', 'accountTypeFilter', 'providerFilter', 'showArchived', 'showEmptyAccounts']);
        $this->resetPage();
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => 'id', 'class' => 'hidden'],
            ['key' => 'type', 'label' => 'Type', 'class' => 'hidden'],
            ['key' => 'title', 'label' => 'Account', 'sortable' => true],
            ['key' => 'type', 'label' => 'Type', 'sortable' => true],
            ['key' => 'service', 'label' => 'Service', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
            ['key' => 'balance', 'label' => 'Balance', 'sortable' => true],
            ['key' => 'currency', 'label' => 'Currency', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
            ['key' => 'interest_rate', 'label' => 'Interest Rate', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
        ];
    }

    public function openAddBalanceModal(): void
    {
        $this->showAddBalanceModal = true;
    }

    public function closeAddBalanceModal(): void
    {
        $this->showAddBalanceModal = false;
    }

    public function openCreateAccountModal(): void
    {
        $this->showCreateAccountModal = true;
    }

    public function closeCreateAccountModal(): void
    {
        $this->showCreateAccountModal = false;
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

        // Get accounts based on archived preference
        if ($this->showArchived) {
            // Include all accounts (including archived) when toggle is enabled
            $accounts = $plugin->getAllFinancialAccounts(Auth::user());
        } else {
            // Default: exclude archived accounts
            $accounts = $plugin->getFinancialAccounts(Auth::user());
        }

        // Eager load tags for all accounts
        $accounts->load('tags');

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

        // Filter out empty accounts if toggle is off
        if (! $this->showEmptyAccounts) {
            $accounts = $accounts->filter(function ($account) {
                $balance = $this->getFormattedBalance($account);

                return $balance !== null && $balance != 0;
            });
        }

        // Apply sorting
        $accounts = $this->applySorting($accounts);

        // Get unique account types and providers for filters
        // Use the same account set that's being displayed for consistent filtering
        $allAccounts = $this->showArchived
            ? $plugin->getAllFinancialAccounts(Auth::user())
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

        // Implement manual pagination using LengthAwarePaginator
        $currentPage = $this->getPage();
        $total = $accounts->count();
        $offset = ($currentPage - 1) * $this->perPage;
        $paginatedItems = $accounts->slice($offset, $this->perPage)->values();

        // Create a LengthAwarePaginator instance
        $paginatedAccounts = new LengthAwarePaginator(
            $paginatedItems,
            $total,
            $this->perPage,
            $currentPage,
            [
                'path' => request()->url(),
                'pageName' => 'page',
            ]
        );

        // Group accounts by type for cards view
        $groupedAccounts = $this->groupAccountsByType($accounts);

        return view('livewire.money.index', [
            'accounts' => $paginatedAccounts,
            'groupedAccounts' => $groupedAccounts,
            'accountTypes' => $accountTypes,
            'providers' => $providers,
        ]);
    }

    protected function getListeners(): array
    {
        return [
            'close-add-balance-modal' => 'closeAddBalanceModal',
            'close-create-account-modal' => 'closeCreateAccountModal',
            'balance-updated' => '$refresh',
            'account-created' => '$refresh',
        ];
    }

    private function applySorting($accounts)
    {
        $sortColumn = $this->sortBy['column'] ?? 'title';
        $sortDirection = $this->sortBy['direction'] ?? 'asc';

        return $accounts->sortBy(function ($account) use ($sortColumn) {
            return match ($sortColumn) {
                'title' => strtolower($account->title),
                'type' => strtolower($account->metadata['account_type'] ?? ''),
                'provider' => strtolower($account->metadata['provider'] ?? ''),
                'service' => strtolower(match ($account->type) {
                    'manual_account' => 'Manual',
                    'monzo_account' => 'Monzo',
                    'monzo_pot' => 'Monzo',
                    'monzo_archived_pot' => 'Monzo',
                    'bank_account' => $account->metadata['provider'] ?? 'Bank',
                    default => $account->type,
                }),
                'balance' => $this->getFormattedBalance($account) ?? 0,
                'currency' => strtolower($account->metadata['currency'] ?? ''),
                default => strtolower($account->title),
            };
        }, SORT_REGULAR, $sortDirection === 'desc')->values();
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

    /**
     * Group accounts by type and sort by balance within groups
     */
    private function groupAccountsByType($accounts): array
    {
        // Define the order of account types
        $typeOrder = [
            'current_account' => 1,
            'credit_card' => 2,
            'savings_account' => 3,
            'loan' => 4,
            'mortgage' => 5,
            'investment_account' => 6,
            'pension' => 7,
            'other' => 8,
        ];

        $grouped = $accounts->groupBy(function ($account) {
            return $account->metadata['account_type'] ?? 'other';
        });

        // Sort groups by defined order and sort accounts within each group by balance
        $sorted = collect($typeOrder)
            ->map(function ($order, $type) use ($grouped) {
                if (! $grouped->has($type)) {
                    return null;
                }

                return [
                    'type' => $type,
                    'label' => $this->getAccountTypeLabel($type) . 's',
                    'accounts' => $grouped->get($type)->sortByDesc(function ($account) {
                        return $this->getFormattedBalance($account) ?? 0;
                    })->values(),
                ];
            })
            ->filter()
            ->values()
            ->toArray();

        return $sorted;
    }
}
