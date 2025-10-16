<div>
    <!-- Two-column layout: main content + drawer -->
    <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
        <!-- Main Content Area -->
        <div class="flex-1 space-y-4 lg:space-y-6">
            <!-- Header -->
            <x-header separator>
                <x-slot:title>
                    @if (in_array($account->type, ['monzo_pot', 'monzo_archived_pot', 'monzo_account']) && !empty($account->title))
                        {{ $account->title }}
                    @elseif (!empty($metadata['name']))
                        {{ $metadata['name'] }}
                    @elseif (!empty($account->title))
                        {{ $account->title }}
                    @else
                        Unnamed Account
                    @endif
                </x-slot:title>
                <x-slot:subtitle>{{ $provider }} - {{ $accountTypeLabel }}</x-slot:subtitle>
                <x-slot:actions>
                    <!-- Desktop: Full buttons -->
                    <div class="hidden sm:flex gap-2">
                        @if ($account->type === 'manual_account')
                            <x-button link="{{ route('balance-updates.create.for-account', $account->id) }}" class="btn-primary">
                                <x-icon name="o-banknotes" class="w-4 h-4" />
                                Add Balance Update
                            </x-button>
                        @endif
                        <x-button link="{{ route('money') }}" class="btn-outline">
                            <x-icon name="o-arrow-left" class="w-4 h-4" />
                            Back
                        </x-button>
                        <x-button wire:click="toggleSidebar" class="btn-ghost btn-sm">
                            <x-icon name="{{ $showSidebar ? 'o-x-mark' : 'o-adjustments-horizontal' }}" class="w-5 h-5" />
                        </x-button>
                    </div>

                    <!-- Mobile: Dropdown -->
                    <div class="sm:hidden">
                        <x-dropdown>
                            <x-slot:trigger>
                                <x-button class="btn-ghost btn-sm">
                                    <x-icon name="o-ellipsis-vertical" class="w-5 h-5" />
                                </x-button>
                            </x-slot:trigger>
                            @if ($account->type === 'manual_account')
                                <x-menu-item title="Add Balance Update" icon="o-banknotes" link="{{ route('balance-updates.create.for-account', $account->id) }}" />
                            @endif
                            <x-menu-item title="Back to Accounts" icon="o-arrow-left" link="{{ route('money') }}" />
                            <x-menu-item title="{{ $showSidebar ? 'Hide Details' : 'Show Details' }}" icon="{{ $showSidebar ? 'o-x-mark' : 'o-adjustments-horizontal' }}" wire:click="toggleSidebar" />
                        </x-dropdown>
                    </div>
                </x-slot:actions>
            </x-header>

            <!-- Primary Hero Card with Current Balance -->
            <x-card>
                <div class="flex flex-col sm:flex-row items-start gap-4 lg:gap-6">
                    <!-- Large icon -->
                    <div class="flex-shrink-0 self-center sm:self-start">
                        <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-full bg-accent/10 flex items-center justify-center">
                            <x-icon name="o-currency-pound" class="w-6 h-6 sm:w-8 sm:h-8 text-accent" />
                        </div>
                    </div>

                    <!-- Main content -->
                    <div class="flex-1 w-full">
                        <div class="mb-4 text-center sm:text-left">
                            <h2 class="text-xl sm:text-2xl lg:text-3xl font-bold text-base-content mb-2">
                                @if (in_array($account->type, ['monzo_pot', 'monzo_archived_pot', 'monzo_account']) && !empty($account->title))
                                    {{ $account->title }}
                                @elseif (!empty($metadata['name']))
                                    {{ $metadata['name'] }}
                                @elseif (!empty($account->title))
                                    {{ $account->title }}
                                @else
                                    Unnamed Account
                                @endif
                            </h2>
                            <div class="text-sm text-base-content/70">
                                {{ $provider }} · {{ $accountTypeLabel }}
                            </div>
                        </div>

                        <!-- Current Balance Display -->
                        @if ($currentBalance !== null)
                            <div class="p-4 lg:p-6 rounded-lg bg-base-300/50 border border-base-300 text-center sm:text-left">
                                <div class="text-sm text-base-content/70 mb-2">Current Balance</div>
                                <div class="text-3xl sm:text-4xl lg:text-5xl font-bold mb-2">
                                    {{ $currencySymbol }}{{ number_format($currentBalance, 2) }}
                                </div>
                                @if ($latestBalance)
                                    <div class="text-sm text-base-content/70">
                                        Last updated {{ $latestBalance->time->diffForHumans() }}
                                    </div>
                                @elseif ($account->type === 'monzo_pot')
                                    <div class="text-sm text-base-content/70">Current pot balance</div>
                                @endif
                            </div>
                        @else
                            <div class="p-4 lg:p-6 rounded-lg bg-base-300/50 border border-base-300 text-center">
                                <x-icon name="o-exclamation-triangle" class="w-12 h-12 mx-auto text-base-content/70 mb-2" />
                                <div class="text-lg text-base-content/70 mb-2">No balance recorded</div>
                                <p class="text-sm text-base-content/70">
                                    @if ($account->type === 'manual_account')
                                        Add your first balance update to get started
                                    @else
                                        Balance will be updated automatically from your integration
                                    @endif
                                </p>
                                @if ($account->type === 'manual_account')
                                    <a href="{{ route('balance-updates.create.for-account', $account->id) }}" class="btn btn-primary btn-sm mt-4">
                                        <x-icon name="o-plus" class="w-4 h-4" />
                                        Add Balance Update
                                    </a>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </x-card>

            <!-- Balance History -->
            <div class="card bg-base-200 shadow">
                <div class="card-body">
                    <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                        <x-icon name="o-chart-bar" class="w-5 h-5 text-primary" />
                        Balance History
                    </h3>

                    @if ($balanceEvents->count() > 0)
                        <div class="overflow-x-auto">
                            <table class="table">
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

                                            // For Monzo accounts, show spent today info
                                            if ($event->service === 'monzo' && isset($event->event_metadata['spent_today'])) {
                                                $notes = 'Spent today: £' . number_format($event->event_metadata['spent_today'], 2);
                                            }
                                        @endphp
                                        <tr class="hover">
                                            <td>
                                                <div class="flex flex-col">
                                                    <span class="font-medium">{{ $event->time->format('M j, Y') }}</span>
                                                    <span class="text-xs text-base-content/70">{{ $event->time->format('g:i A') }}</span>
                                                </div>
                                            </td>
                                            <td>
                                                @if ($balance !== null)
                                                    <span class="font-mono font-medium text-lg">
                                                        {{ $currencySymbol }}{{ number_format($balance, 2) }}
                                                    </span>
                                                @else
                                                    <span class="text-base-content/50">-</span>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="text-sm text-base-content/70">{{ $notes ?: '-' }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-center py-8">
                            <x-icon name="o-chart-bar" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
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

        <!-- Drawer for Account Details -->
        <x-drawer wire:model="showSidebar" right title="Account Details" separator with-close-button class="w-11/12 lg:w-1/3">
            <div class="space-y-4 lg:space-y-6">
                <!-- Account Information -->
                <div>
                    <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                        <x-icon name="o-building-library" class="w-5 h-5 text-primary" />
                        Account Information
                    </h3>
                    <div class="space-y-3 text-sm">
                        <div>
                            <span class="text-base-content/70">Account Type:</span>
                            <span class="font-medium">{{ $accountTypeLabel }}</span>
                        </div>

                        <div>
                            <span class="text-base-content/70">Provider:</span>
                            <span class="font-medium">{{ $provider }}</span>
                        </div>

                        @if ($accountNumber)
                        <div>
                            <span class="text-base-content/70">Account Number:</span>
                            <span class="font-mono font-medium">{{ $accountNumber }}</span>
                        </div>
                        @endif

                        @if ($sortCode)
                        <div>
                            <span class="text-base-content/70">Sort Code:</span>
                            <span class="font-mono font-medium">{{ $sortCode }}</span>
                        </div>
                        @endif

                        <div>
                            <span class="text-base-content/70">Currency:</span>
                            <span class="font-medium">{{ $currency }}</span>
                        </div>

                        @if ($interestRate)
                        <div>
                            <span class="text-base-content/70">Interest Rate:</span>
                            <span class="font-medium text-success">{{ number_format($interestRate, 2) }}%</span>
                        </div>
                        @endif

                        @if ($startDate)
                        <div>
                            <span class="text-base-content/70">Start Date:</span>
                            <span class="font-medium">{{ \Carbon\Carbon::parse($startDate)->format('M j, Y') }}</span>
                        </div>
                        @endif

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

                        <div>
                            <span class="text-base-content/70">Service:</span>
                            <span class="font-medium">{{ $service }}</span>
                        </div>
                    </div>
                </div>

                <!-- Raw Metadata (Collapsible) -->
                <x-collapse wire:model="metadataOpen">
                    <x-slot:heading>
                        <div class="text-lg font-semibold text-base-content flex items-center gap-2">
                            <x-icon name="o-code-bracket" class="w-5 h-5 text-primary" />
                            Raw Metadata
                        </div>
                    </x-slot:heading>
                    <x-slot:content>
                        <pre class="text-xs bg-base-300 p-3 rounded overflow-x-auto"><code>{{ json_encode($metadata, JSON_PRETTY_PRINT) }}</code></pre>
                    </x-slot:content>
                </x-collapse>
            </div>
        </x-drawer>
    </div>
</div>
