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
                    <x-icon name="o-currency-pound" class="w-4 h-4" />
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
                            @foreach($accountTypes as $type => $label)
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
                            @foreach($providers as $provider)
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
                @if($accounts->count() > 0)
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
                                @foreach($accounts as $account)
                                    <tr>
                                        <td>
                                            <div>
                                                <div class="font-medium">{{ $account->name }}</div>
                                                @if($account->account_number)
                                                    <div class="text-sm text-base-content/70">
                                                        {{ $account->account_number }}
                                                        @if($account->sort_code)
                                                            ({{ $account->sort_code }})
                                                        @endif
                                                    </div>
                                                @endif
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-outline">
                                                {{ $account->account_type_label }}
                                            </span>
                                        </td>
                                        <td>{{ $account->provider }}</td>
                                        <td>
                                            @if($account->current_balance !== null)
                                                <span class="font-mono font-medium">
                                                    {{ $account->formatted_current_balance }}
                                                </span>
                                            @else
                                                <span class="text-base-content/50">No balance</span>
                                            @endif
                                        </td>
                                        <td>{{ $account->currency }}</td>
                                        <td>
                                            @if($account->interest_rate)
                                                <span class="text-success font-medium">
                                                    {{ $account->formatted_interest_rate }}
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
                    <div class="mt-6">
                        {{ $accounts->links() }}
                    </div>
                @else
                    <div class="text-center py-12">
                        <x-icon name="o-currency-pound" class="w-16 h-16 mx-auto text-base-content/30 mb-4" />
                        <h3 class="text-lg font-medium text-base-content mb-2">No financial accounts found</h3>
                        <p class="text-base-content/70 mb-6">
                            @if($search || $accountTypeFilter || $providerFilter)
                                Try adjusting your filters or search terms.
                            @else
                                Get started by adding your first financial account.
                            @endif
                        </p>
                        @if(!$search && !$accountTypeFilter && !$providerFilter)
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