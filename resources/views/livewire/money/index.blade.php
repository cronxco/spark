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
                    <x-menu-item title="Add Account" icon="o-plus" link="{{ route('money.create') }}" />
                    <x-menu-item title="Add Balance Update" icon="o-banknotes" link="{{ route('balance-updates.create') }}" />
                </x-dropdown>
            </div>

            <!-- Desktop buttons -->
            <div class="hidden sm:flex gap-2">
                <x-button
                    link="{{ route('money.create') }}"
                    class="btn-primary">
                    <x-icon name="o-plus" class="w-4 h-4" />
                    Add Account
                </x-button>
                <x-button
                    link="{{ route('balance-updates.create') }}"
                    class="btn-outline">
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

                <!-- Archived Pots Toggle -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Archived?</span>
                    </label>
                    <div class="flex items-center h-12">
                        <input
                            type="checkbox"
                            wire:model.live="showArchivedPots"
                            class="toggle toggle-primary" />
                    </div>
                </div>

                <!-- Clear Filters -->
                @if (!empty($search) || !empty($accountTypeFilter) || !empty($providerFilter) || $showArchivedPots)
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
                    @if (!empty($search) || !empty($accountTypeFilter) || !empty($providerFilter) || $showArchivedPots)
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

                    <!-- Archived Pots Toggle -->
                    <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
                        <div>
                            <div class="font-medium text-sm">Show Archived Pots</div>
                        </div>
                        <input
                            type="checkbox"
                            wire:model.live="showArchivedPots"
                            class="toggle toggle-primary" />
                    </div>

                    <!-- Clear Filters -->
                    @if (!empty($search) || !empty($accountTypeFilter) || !empty($providerFilter) || $showArchivedPots)
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
                @endphp
                @if ($displayBalance !== null)
                <span class="font-mono font-medium {{ $displayBalance < 0 ? 'text-error' : 'text-success' }}">
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
                        <!-- Mobile: 3-dot menu -->

                        <!-- Desktop: Full action buttons -->
                        <div class="hidden lg:flex gap-2">
                            <button
                                wire:click="deleteAccount('{{ $account->id }}')"
                                wire:confirm="Are you sure you want to delete this account? This will also delete all balance history."
                                class="btn btn-sm btn-error btn-outline">
                                <x-icon name="o-trash" class="w-4 h-4" />
                                Delete
                            </button>
                        </div>
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
                                <a href="{{ route('money.create') }}" class="btn btn-primary">
                                    <x-icon name="o-plus" class="w-4 h-4" />
                                    Add Your First Account
                                </a>
                                @endif
                            </div>
                        </x-slot:empty>
            </x-table>
        </div>
    </div>

    <!-- Toast notifications -->
    <x-toast position="toast-top toast-end" />
</div>