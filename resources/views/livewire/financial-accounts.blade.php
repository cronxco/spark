<div>
    <div class="flex flex-col gap-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-base-content">Financial Accounts</h1>
                <p class="text-base-content/70">Manage your financial accounts and track balances</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('financial-accounts.create') }}" class="btn btn-primary">
                    <x-icon name="o-plus" class="w-4 h-4" />
                    Add Account
                </a>
                <a href="{{ route('balance-updates.create') }}" class="btn btn-secondary">
                    <x-icon name="o-currency-dollar" class="w-4 h-4" />
                    Add Balance Update
                </a>
            </div>
        </div>

        <!-- Filters -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <div class="flex flex-col lg:flex-row gap-4">
                    <!-- Search -->
                    <div class="form-control flex-1">
                        <label class="label">
                            <span class="label-text">Search</span>
                        </label>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="Search accounts, providers, or account numbers..."
                            class="input input-bordered w-full"
                        />
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

                    <!-- Clear Filters -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">&nbsp;</span>
                        </label>
                        <button wire:click="clearFilters" class="btn btn-outline">
                            Clear Filters
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Accounts List -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                @if ($accounts->count() > 0)
                    <div class="overflow-x-auto">
                        <table class="table table-zebra">
                            <thead>
                                <tr>
                                    <th>Account</th>
                                    <th>Type</th>
                                    <th>Provider</th>
                                    <th>Current Balance</th>
                                    <th>Currency</th>
                                    <th>Interest Rate</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($accounts as $account)
                                    @php
                                        $metadata = $account->metadata;
                                        $accountType = $metadata['account_type'] ?? '';
                                        $provider = $metadata['provider'] ?? '';
                                        $accountNumber = $metadata['account_number'] ?? null;
                                        $sortCode = $metadata['sort_code'] ?? null;
                                        $currency = $metadata['currency'] ?? 'GBP';
                                        $interestRate = $metadata['interest_rate'] ?? null;
                                        $startDate = $metadata['start_date'] ?? null;

                                        // Get account type label
                                        $accountTypeLabels = [
                                            'current_account' => 'Current Account',
                                            'savings_account' => 'Savings Account',
                                            'mortgage' => 'Mortgage',
                                            'investment_account' => 'Investment Account',
                                            'credit_card' => 'Credit Card',
                                            'loan' => 'Loan',
                                            'pension' => 'Pension',
                                            'other' => 'Other',
                                        ];
                                        $accountTypeLabel = $accountTypeLabels[$accountType] ?? $accountType;

                                        // Get currency symbol
                                        $currencySymbols = [
                                            'GBP' => '£',
                                            'USD' => '$',
                                            'EUR' => '€',
                                        ];
                                        $currencySymbol = $currencySymbols[$currency] ?? $currency;

                                        // Get current balance from latest event
                                        $plugin = new \App\Integrations\Financial\FinancialPlugin();
                                        $latestBalance = $plugin->getLatestBalance($account);
                                        $currentBalance = $latestBalance ? $latestBalance->event_metadata['balance'] ?? null : null;
                                    @endphp
                                    <tr>
                                        <td>
                                            <div>
                                                <div class="font-medium">{{ $metadata['name'] ?? 'Unnamed Account' }}</div>
                                                @if ($accountNumber)
                                                    <div class="text-sm text-base-content/70">
                                                        {{ $accountNumber }}
                                                        @if ($sortCode)
                                                            ({{ $sortCode }})
                                                        @endif
                                                    </div>
                                                @endif
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-outline">
                                                {{ $accountTypeLabel }}
                                            </span>
                                        </td>
                                        <td>{{ $provider }}</td>
                                        <td>
                                            @if ($currentBalance !== null)
                                                <span class="font-mono font-medium">
                                                    {{ $currencySymbol }}{{ number_format($currentBalance, 2) }}
                                                </span>
                                            @else
                                                <span class="text-base-content/50">No balance</span>
                                            @endif
                                        </td>
                                        <td>{{ $currency }}</td>
                                        <td>
                                            @if ($interestRate)
                                                <span class="text-success font-medium">
                                                    {{ number_format($interestRate, 2) }}%
                                                </span>
                                            @else
                                                <span class="text-base-content/50">-</span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="flex gap-2">
                                                <a
                                                    href="{{ route('financial-accounts.show', $account) }}"
                                                    class="btn btn-sm btn-outline"
                                                >
                                                    <x-icon name="o-eye" class="w-4 h-4" />
                                                    View
                                                </a>
                                                <button
                                                    wire:click="deleteAccount('{{ $account->id }}')"
                                                    wire:confirm="Are you sure you want to delete this account? This will also delete all balance history."
                                                    class="btn btn-sm btn-error btn-outline"
                                                >
                                                    <x-icon name="o-trash" class="w-4 h-4" />
                                                    Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    @if($pagination['hasPages'])
                        <div class="mt-6">
                            <div class="flex items-center justify-between">
                                <div class="text-sm text-base-content/70">
                                    Showing {{ $pagination['offset'] + 1 }} to {{ min($pagination['offset'] + $pagination['perPage'], $pagination['total']) }} of {{ $pagination['total'] }} accounts
                                </div>
                                <div class="join">
                                    @if($pagination['currentPage'] > 1)
                                        <button wire:click="previousPage" class="btn btn-outline btn-sm">
                                            <x-icon name="o-chevron-left" class="w-4 h-4" />
                                            Previous
                                        </button>
                                    @endif
                                    
                                    @if($pagination['hasMorePages'])
                                        <button wire:click="nextPage" class="btn btn-outline btn-sm">
                                            Next
                                            <x-icon name="o-chevron-right" class="w-4 h-4" />
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif
                @else
                    <div class="text-center py-12">
                        <x-icon name="o-currency-dollar" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
                        <h3 class="text-lg font-medium text-base-content mb-2">No financial accounts found</h3>
                        <p class="text-base-content/70 mb-6">
                            @if ($search || $accountTypeFilter || $providerFilter)
                                Try adjusting your filters or search terms.
                            @else
                                Get started by adding your first financial account.
                            @endif
                        </p>
                        @if (!$search && !$accountTypeFilter && !$providerFilter)
                            <a href="{{ route('financial-accounts.create') }}" class="btn btn-primary">
                                <x-icon name="o-plus" class="w-4 h-4" />
                                Add Your First Account
                            </a>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Toast notifications -->
    <x-toast position="toast-top toast-end" />
</div>