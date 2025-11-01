<div>
    <x-header title="Money" subtitle="Manage your accounts and track balances" separator>
        <x-slot:actions>
            <!-- Mobile actions dropdown -->
            <div class="sm:hidden">
                <x-dropdown position="dropdown-end">
                    <x-slot:trigger>
                        <x-button class="btn-ghost btn-sm" aria-label="Actions" title="Actions">
                            <x-icon name="o-ellipsis-vertical" class="w-5 h-5" />
                        </x-button>
                    </x-slot:trigger>
                    <x-menu-item title="Add Account" icon="o-plus" wire:click="openCreateAccountModal" />
                    <x-menu-item title="Add Balance Update" icon="o-banknotes" wire:click="openAddBalanceModal" />
                </x-dropdown>
            </div>

            <!-- Desktop buttons -->
            <div class="hidden sm:flex gap-2">
                <x-button
                    wire:click="openCreateAccountModal"
                    class="btn-primary btn-sm">
                    <x-icon name="o-plus" class="w-4 h-4" />
                    Add Account
                </x-button>
                <x-button
                    wire:click="openAddBalanceModal"
                    class="btn-outline btn-sm">
                    <x-icon name="o-banknotes" class="w-4 h-4" />
                    Add Balance Update
                </x-button>
            </div>
        </x-slot:actions>
    </x-header>

    <!-- Filters -->
    <div class="hidden lg:block card bg-base-200 shadow mb-6">
        <div class="card-body">
            <div class="flex flex-row gap-4">
                <!-- Search -->
                <div class="form-control flex-1">
                    <label class="label">
                        <span class="label-text">Search</span>
                    </label>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search..."
                        class="input input-bordered w-full" />
                </div>

                <!-- Account Type Filter -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Account Type</span>
                    </label>
                    <select wire:model.live="accountTypeFilter" class="select select-bordered">
                        <option value="">All Types</option>
                        @foreach ($accountTypes as $type => $label)
                        <option value="{{ $type }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Provider Filter -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Provider</span>
                    </label>
                    <select wire:model.live="providerFilter" class="select select-bordered">
                        <option value="">All Providers</option>
                        @foreach ($providers as $provider)
                        <option value="{{ $provider }}">{{ $provider }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Show Archived Toggle -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Archived?</span>
                    </label>
                    <div class="flex items-center h-12">
                        <input
                            type="checkbox"
                            wire:model.live="showArchived"
                            class="toggle toggle-primary" />
                    </div>
                </div>

                <!-- Show Empty Accounts Toggle -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Empty?</span>
                    </label>
                    <div class="flex items-center h-12">
                        <input
                            type="checkbox"
                            wire:model.live="showEmptyAccounts"
                            class="toggle toggle-primary" />
                    </div>
                </div>

                <!-- Clear Filters -->
                @if (!empty($search) || !empty($accountTypeFilter) || !empty($providerFilter) || $showArchived || !$showEmptyAccounts)
                <div class="form-control content-end">
                    <label class="label">
                        <span class="label-text">&nbsp;</span>
                    </label>
                    <button wire:click="clearFilters" class="btn btn-outline">
                        <x-icon name="o-x-mark" class="w-4 h-4" />
                        Clear
                    </button>
                </div>
                @endif
            </div>
        </div>
    </div>
    <div class="lg:hidden mb-4">
        <x-collapse separator class="bg-base-200">
            <x-slot:heading>
                <div class="flex items-center gap-2">
                    <x-icon name="o-funnel" class="w-5 h-5" />
                    Filters
                    @if (!empty($search) || !empty($accountTypeFilter) || !empty($providerFilter) || $showArchived || !$showEmptyAccounts)
                    <x-badge value="Active" class="badge-primary badge-xs" />
                    @endif
                </div>
            </x-slot:heading>
            <x-slot:content>
                <div class="flex flex-col gap-4">
                    <!-- Search -->
                    <div class="form-control flex-1">
                        <label class="label">
                            <span class="label-text">Search</span>
                        </label>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="Search..."
                            class="input input-bordered w-full" />
                    </div>

                    <!-- Account Type Filter -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Account Type</span>
                        </label>
                        <select wire:model.live="accountTypeFilter" class="select select-bordered w-full">
                            <option value="">All Types</option>
                            @foreach ($accountTypes as $type => $label)
                            <option value="{{ $type }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Provider Filter -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Provider</span>
                        </label>
                        <select wire:model.live="providerFilter" class="select select-bordered w-full">
                            <option value="">All Providers</option>
                            @foreach ($providers as $provider)
                            <option value="{{ $provider }}">{{ $provider }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Show Archived Toggle -->
                    <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
                        <div>
                            <div class="font-medium text-sm">Show Archived</div>
                        </div>
                        <input
                            type="checkbox"
                            wire:model.live="showArchived"
                            class="toggle toggle-primary" />
                    </div>

                    <!-- Show Empty Accounts Toggle -->
                    <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
                        <div>
                            <div class="font-medium text-sm">Show Empty Accounts</div>
                        </div>
                        <input
                            type="checkbox"
                            wire:model.live="showEmptyAccounts"
                            class="toggle toggle-primary" />
                    </div>

                    <!-- Clear Filters -->
                    @if (!empty($search) || !empty($accountTypeFilter) || !empty($providerFilter) || $showArchived || !$showEmptyAccounts)
                    <button wire:click="clearFilters" class="btn btn-outline">
                        <x-icon name="o-x-mark" class="w-4 h-4" />
                        Clear Filters
                    </button>
                    @endif
                </div>
            </x-slot:content>
        </x-collapse>
    </div>

    <!-- Accounts List -->
    <x-tabs wire:model="viewMode" selected="cards">
        <x-tab name="cards" label="Cards" icon="o-squares-2x2">
            <!-- Cards View -->
            @if (count($groupedAccounts) > 0)
            @foreach ($groupedAccounts as $group)
            <div class="mb-6">
                <x-collapse class="bg-base-200" separator wire:model="expandedSections.{{ $group['type'] }}">
                    <x-slot:heading>
                        <div class="flex items-center justify-between w-full">
                            <div class="text-lg font-semibold">{{ $group['label'] }}</div>
                            @php
                                $total = $group['total'];
                                // Use darker colors for better contrast on bg-base-200
                                $colorClass = $total < 0 ? 'text-red-700 dark:text-red-400' : ($total == 0 ? 'text-base-content' : 'text-green-700 dark:text-green-400');
                            @endphp
                            <div class="text-lg font-bold font-mono {{ $colorClass }}">
                                £{{ number_format(abs($total), 2) }}
                            </div>
                        </div>
                    </x-slot:heading>
                    <x-slot:content>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 p-4">
                            @foreach ($group['accounts'] as $account)
                            @php
                            $metadata = $account->metadata ?? [];
                            $accountType = $metadata['account_type'] ?? '';
                            $provider = $metadata['provider'] ?? '';
                            $accountNumber = $metadata['account_number'] ?? null;
                            $currency = $metadata['currency'] ?? 'GBP';
                            $currencySymbols = [
                            'GBP' => '£',
                            'USD' => '$',
                            'EUR' => '€',
                            ];
                            $currencySymbol = $currencySymbols[$currency] ?? $currency;

                            $plugin = new \App\Integrations\Financial\FinancialPlugin();
                            $latestBalance = $plugin->getLatestBalance($account);

                            if ($latestBalance) {
                            if (isset($latestBalance->event_metadata['balance'])) {
                            $currentBalance = $latestBalance->event_metadata['balance'];
                            } else {
                            $currentBalance = $latestBalance->formatted_value;
                            }
                            } elseif (in_array($account->type, ['monzo_pot', 'monzo_archived_pot']) && !empty(($account->metadata['balance'] ?? null))) {
                            $currentBalance = (float) ($account->metadata['balance'] ?? 0);
                            } else {
                            $currentBalance = null;
                            }

                            $isNegativeBalance = $metadata['is_negative_balance'] ?? false;

                            if ($isNegativeBalance && $currentBalance !== null) {
                            $displayBalance = -$currentBalance;
                            } else {
                            $displayBalance = $currentBalance;
                            }

                            $lastUpdated = $latestBalance?->created_at;
                            @endphp

                            <a href="/money/{{ $account->id }}" wire:navigate class="card bg-base-100 shadow hover:shadow-lg transition-shadow cursor-pointer">
                                <div class="card-body p-4">
                                    <h3 class="card-title text-base">
                                        {{ $provider }}
                                        @if (in_array($account->type, ['monzo_pot', 'monzo_archived_pot', 'monzo_account']) && !empty($account->title))
                                        {{ $account->title }}
                                        @elseif (!empty($metadata['name']))
                                        {{ $metadata['name'] }}
                                        @elseif (!empty($account->title))
                                        {{ $account->title }}
                                        @else
                                        Unnamed Account
                                        @endif
                                    </h3>

                                    @if ($displayBalance !== null)
                                    @php
                                        $balanceColorClass = $displayBalance < 0 ? 'text-error' : ($displayBalance == 0 ? 'text-base-content' : 'text-success');
                                    @endphp
                                    <div class="text-3xl font-bold font-mono my-2 {{ $balanceColorClass }}">
                                        @if ($displayBalance < 0)
                                        {{ $currencySymbol }}{{ number_format(abs($displayBalance), 2) }}
                                        @else
                                        {{ $currencySymbol }}{{ number_format($displayBalance, 2) }}
                                        @endif
                                    </div>
                                    @else
                                    <div class="text-2xl font-medium text-base-content/50 my-2">
                                        No balance
                                    </div>
                                    @endif

                                    @if ($account->tags->isNotEmpty())
                                    <div class="flex flex-wrap gap-1.5 mb-3">
                                        @foreach ($account->tags as $tag)
                                        <x-badge :value="$tag->name" class="badge-sm badge-outline" />
                                        @endforeach
                                    </div>
                                    @endif

                                    @if ($lastUpdated)
                                    <div class="flex items-center gap-1.5 text-xs text-base-content/70">
                                        @php
                                        $ageMinutes = $lastUpdated->diffInMinutes(now());
                                        @endphp

                                        @if ($ageMinutes < 60)
                                        <div class="flex items-center gap-1">
                                            <div aria-label="updated-recently" class="status status-success" />
                                            <span class="text-xs">Last updated {{ $lastUpdated->diffForHumans() }}</span>
                                        </div>
                                        @elseif ($ageMinutes < 60 * 24)
                                        <div class="flex items-center gap-1">
                                            <div aria-label="updated-today" class="status status-info"></div>
                                            <span class="text-xs">Last updated {{ $lastUpdated->diffForHumans() }}</span>
                                        </div>
                                        @elseif ($ageMinutes < 60 * 24 * 30)
                                        <div class="flex items-center gap-1">
                                            <div aria-label="updated-month" class="status status-warning"></div>
                                            <span class="text-xs">Last updated {{ $lastUpdated->diffForHumans() }}</span>
                                        </div>
                                        @else
                                        <div class="flex items-center gap-1">
                                            <div aria-label="updated-old" class="status status-error"></div>
                                            <span class="text-xs">Last updated {{ $lastUpdated->diffForHumans() }}</span>
                                        </div>
                                        @endif
                                    </div>
                                    @endif

                                </div>
                            </a>
                            @endforeach
                        </div>
                    </x-slot:content>
                </x-collapse>
            </div>
            @endforeach
            @else
            <div class="card bg-base-200 shadow">
                <div class="card-body">
                    <div class="text-center py-12">
                        <x-icon name="o-currency-pound" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
                        <h3 class="text-lg font-medium text-base-content mb-2">No accounts found</h3>
                        <p class="text-base-content/70 mb-6">
                            @if ($search || $accountTypeFilter || $providerFilter)
                            Try adjusting your filters or search terms.
                            @else
                            Get started by adding your first account.
                            @endif
                        </p>
                        @if (!$search && !$accountTypeFilter && !$providerFilter)
                        <button wire:click="openCreateAccountModal" class="btn btn-primary">
                            <x-icon name="o-plus" class="w-4 h-4" />
                            Add Your First Account
                        </button>
                        @endif
                    </div>
                </div>
            </div>
            @endif
        </x-tab>

<x-tab name="table" label="Table" icon="o-table-cells">
    <!-- Table View -->
    <div class="card bg-base-200 shadow card-xs sm:card-md">
        <div class="card-body">
            <x-table
                :headers="$this->headers()"
                :rows="$accounts"
                :sort-by="$sortBy"
                per-page="perPage"
                :per-page-values="[10, 25, 50, 100]"
                with-pagination
                link="/money/{id}"
                class="table-xs sm:table-md [&_table]:!static [&_td]:!static">

                @scope('cell_title', $account)
                @php
                $metadata = $account->metadata ?? [];
                $accountNumber = $metadata['account_number'] ?? null;
                $sortCode = $metadata['sort_code'] ?? null;
                @endphp
                <div>
                    <div class="font-medium">
                        @if (in_array($account->type, ['monzo_pot', 'monzo_archived_pot', 'monzo_account']) && !empty($account->title))
                        {{ $account->title }}
                        @elseif (!empty($metadata['name']))
                        {{ $metadata['name'] }}
                        @elseif (!empty($account->title))
                        {{ $account->title }}
                        @else
                        Unnamed Account
                        @endif
                    </div>
                    @if ($accountNumber)
                    <div class="sm:text-sm text-base-content/70">
                        {{ $accountNumber }}
                        @if ($sortCode)
                        <br />
                        ({{ $sortCode }})
                        @endif
                    </div>
                    @endif
                </div>
                @endscope

                @scope('cell_type', $account)
                @php
                $metadata = $account->metadata ?? [];
                $accountType = $metadata['account_type'] ?? '';
                $provider = $metadata['provider'] ?? '';
                $accountTypeLabels = [
                'current_account' => 'Current',
                'savings_account' => 'Savings',
                'mortgage' => 'Mortgage',
                'investment_account' => 'Investment',
                'credit_card' => 'Credit',
                'loan' => 'Loan',
                'pension' => 'Pension',
                'other' => 'Other',
                ];
                $accountTypeLabel = $accountTypeLabels[$accountType] ?? $accountType;
                @endphp
                <div class="flex flex-col gap-0.5">
                    <span class="sm:text-sm">{{ $provider ?: '-' }} {{ $accountTypeLabel ?: '-' }}</span>
                </div>
                @endscope

                @scope('cell_service', $account)
                @php
                $service = match ($account->type) {
                'manual_account' => 'Manual',
                'monzo_account' => 'Monzo',
                'monzo_pot' => 'Monzo Pot',
                'monzo_archived_pot' => 'Monzo Pot (Archived)',
                'bank_account' => 'GoCardless',
                default => 'Unknown'
                };
                @endphp
                <span class="text-sm text-base-content/70">{{ $service }}</span>
                @endscope

                @scope('cell_balance', $account)
                @php
                $metadata = $account->metadata ?? [];
                $currency = $metadata['currency'] ?? 'GBP';
                $currencySymbols = [
                'GBP' => '£',
                'USD' => '$',
                'EUR' => '€',
                ];
                $currencySymbol = $currencySymbols[$currency] ?? $currency;

                // Get current balance from latest event
                $plugin = new \App\Integrations\Financial\FinancialPlugin();
                $latestBalance = $plugin->getLatestBalance($account);

                // Handle different balance storage formats
                if ($latestBalance) {
                if (isset($latestBalance->event_metadata['balance'])) {
                $currentBalance = $latestBalance->event_metadata['balance'];
                } else {
                $currentBalance = $latestBalance->formatted_value;
                }
                } elseif (in_array($account->type, ['monzo_pot', 'monzo_archived_pot']) && !empty(($account->metadata['balance'] ?? null))) {
                $currentBalance = (float) ($account->metadata['balance'] ?? 0);
                } else {
                $currentBalance = null;
                }

                // Check if this is a negative balance account (debt)
                $isNegativeBalance = $metadata['is_negative_balance'] ?? false;

                // For negative balance accounts, invert the sign for display
                if ($isNegativeBalance && $currentBalance !== null) {
                $displayBalance = -$currentBalance;
                } else {
                $displayBalance = $currentBalance;
                }

                $balanceColorClass = $displayBalance !== null
                    ? ($displayBalance < 0 ? 'text-error' : ($displayBalance == 0 ? 'text-base-content' : 'text-success'))
                    : '';
                @endphp
                @if ($displayBalance !== null)
                <span class="font-mono font-medium {{ $balanceColorClass }}">
                    @if ($displayBalance < 0)
                    {{ $currencySymbol }}{{ number_format(abs($displayBalance), 2) }}
                    @else
                    {{ $currencySymbol }}{{ number_format($displayBalance, 2) }}
                    @endif
                </span>
                @else
                <span class="text-base-content/50">No balance</span>
                @endif
                @endscope

                        @scope('cell_currency', $account)
                        {{ $account->metadata['currency'] ?? 'GBP' }}
                        @endscope

                        @scope('cell_interest_rate', $account)
                        @php
                        $interestRate = $account->metadata['interest_rate'] ?? null;
                        @endphp
                        @if ($interestRate)
                        <span class="text-success font-medium">
                            {{ number_format($interestRate, 2) }}%
                        </span>
                        @else
                        <span class="text-base-content/50">-</span>
                        @endif
                        @endscope

                        @scope('actions', $account)
                        @endscope

                        <x-slot:empty>
                            <div class="text-center py-12">
                                <x-icon name="o-currency-pound" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
                                <h3 class="text-lg font-medium text-base-content mb-2">No accounts found</h3>
                                <p class="text-base-content/70 mb-6">
                                    @if ($search || $accountTypeFilter || $providerFilter)
                                    Try adjusting your filters or search terms.
                                    @else
                                    Get started by adding your first account.
                                    @endif
                                </p>
                                @if (!$search && !$accountTypeFilter && !$providerFilter)
                                <button wire:click="openCreateAccountModal" class="btn btn-primary">
                                    <x-icon name="o-plus" class="w-4 h-4" />
                                    Add Your First Account
                                </button>
                                @endif
                            </div>
                        </x-slot:empty>
            </x-table>
        </div>
    </div>
</x-tab>
</x-tabs>

<!-- Add Balance Update Modal -->
<x-modal wire:model="showAddBalanceModal" title="Add Balance Update" subtitle="Record a new balance for one of your accounts" separator>
    <livewire:add-balance-update :key="'add-balance-update-modal'" />
</x-modal>

<!-- Create Account Modal -->
<x-modal wire:model="showCreateAccountModal" title="Add Financial Account" subtitle="Create a new financial account to track manually" separator>
    <livewire:create-financial-account :key="'create-account-modal'" />
</x-modal>

<!-- Toast notifications -->
<x-toast position="toast-top toast-end" />
</div>