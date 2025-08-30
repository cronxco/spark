<div>
    <div class="flex flex-col gap-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-base-content">{{ $metadata['name'] ?? 'Unnamed Account' }}</h1>
                <p class="text-base-content/70">{{ $provider }} - {{ $accountTypeLabel }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('money') }}" class="btn btn-outline">
                    <x-icon name="o-arrow-left" class="w-4 h-4" />
                    Back to Accounts
                </a>
                @if ($account->type === 'manual_account')
                    <a href="{{ route('balance-updates.create.for-account', $account->id) }}" class="btn btn-primary">
                        <x-icon name="o-currency-dollar" class="w-4 h-4" />
                        Add Balance Update
                    </a>
                @endif
            </div>
        </div>

        <!-- Account Details -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Account Information -->
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body">
                    <h2 class="card-title">Account Information</h2>
                    <div class="space-y-4">
                        <div>
                            <label class="label">
                                <span class="label-text font-medium">Account Type</span>
                            </label>
                            <span class="badge badge-outline">{{ $accountTypeLabel }}</span>
                        </div>

                        <div>
                            <label class="label">
                                <span class="label-text font-medium">Provider</span>
                            </label>
                            <p>{{ $provider }}</p>
                        </div>

                        @if ($accountNumber)
                        <div>
                            <label class="label">
                                <span class="label-text font-medium">Account Number</span>
                            </label>
                            <p class="font-mono">{{ $accountNumber }}</p>
                        </div>
                        @endif

                        @if ($sortCode)
                        <div>
                            <label class="label">
                                <span class="label-text font-medium">Sort Code</span>
                            </label>
                            <p class="font-mono">{{ $sortCode }}</p>
                        </div>
                        @endif

                        <div>
                            <label class="label">
                                <span class="label-text font-medium">Currency</span>
                            </label>
                            <p>{{ $currency }}</p>
                        </div>

                        @if ($interestRate)
                        <div>
                            <label class="label">
                                <span class="label-text font-medium">Interest Rate</span>
                            </label>
                            <p class="text-success font-medium">{{ number_format($interestRate, 2) }}%</p>
                        </div>
                        @endif

                        @if ($startDate)
                        <div>
                            <label class="label">
                                <span class="label-text font-medium">Start Date</span>
                            </label>
                            <p>{{ \Carbon\Carbon::parse($startDate)->format('F j, Y') }}</p>
                        </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Current Balance -->
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body">
                    <h2 class="card-title">Current Balance</h2>
                    @if ($currentBalance !== null)
                        <div class="text-center py-8">
                            <div class="text-4xl font-bold text-primary mb-2">
                                {{ $currencySymbol }}{{ number_format($currentBalance, 2) }}
                            </div>
                            @if ($latestBalance)
                                <p class="text-base-content/70">Last updated: {{ $latestBalance->time->format('F j, Y') }}</p>
                            @elseif ($account->type === 'monzo_pot')
                                <p class="text-base-content/70">Current pot balance</p>
                            @endif
                        </div>
                    @else
                        <div class="text-center py-8">
                            <div class="text-2xl text-base-content/50 mb-2">No balance recorded</div>
                            <p class="text-base-content/70">
                                @if ($account->type === 'manual_account')
                                    Add your first balance update to get started
                                @else
                                    Balance will be updated automatically from your integration
                                @endif
                            </p>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Balance History -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <h2 class="card-title">Balance History</h2>
                @if ($balanceEvents->count() > 0)
                    <div class="overflow-x-auto">
                        <table class="table table-zebra">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Balance</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($balanceEvents as $event)
                                    @php
                                        // Handle different balance storage formats
                                        if (isset($event->event_metadata['balance'])) {
                                            // Manual accounts store balance in event_metadata
                                            $balance = $event->event_metadata['balance'];
                                        } else {
                                            // Monzo/GoCardless store balance in value field (integer cents)
                                            $balance = $event->formatted_value;
                                        }

                                        $notes = $event->event_metadata['notes'] ?? null;

                                        // For Monzo accounts, show spend today info
                                        if ($event->service === 'monzo' && isset($event->event_metadata['spend_today'])) {
                                            $notes = 'Spend today: £' . number_format($event->event_metadata['spend_today'], 2);
                                        }
                                    @endphp
                                    <tr>
                                        <td>{{ $event->time->format('F j, Y') }}</td>
                                        <td>
                                            @if ($balance !== null)
                                                <span class="font-mono font-medium">
                                                    {{ $currencySymbol }}{{ number_format($balance, 2) }}
                                                </span>
                                            @else
                                                <span class="text-base-content/50">-</span>
                                            @endif
                                        </td>
                                        <td>{{ $notes ?: '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-center py-8">
                        <x-icon name="o-currency-dollar" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
                        <h3 class="text-lg font-medium text-base-content mb-2">No balance history</h3>
                        <p class="text-base-content/70 mb-6">
                            @if ($account->type === 'manual_account')
                                Add your first balance update to get started
                            @elseif (in_array($account->type, ['monzo_account', 'monzo_pot', 'bank_account']))
                                Balance history will appear here once your integration fetches data
                            @else
                                No balance history available
                            @endif
                        </p>
                        @if ($account->type === 'manual_account')
                            <a href="{{ route('balance-updates.create.for-account', $account->id) }}" class="btn btn-primary">
                                <x-icon name="o-plus" class="w-4 h-4" />
                                Add Balance Update
                            </a>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
