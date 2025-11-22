<div>
    <!-- Two-column layout: main content + drawer -->
    <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
        <!-- Main Content Area -->
        <div class="flex-1 space-y-4 lg:space-y-6">
            <!-- Header -->
            <x-header separator>
                <x-slot:title>
                    Account Details
                </x-slot:title>
                <x-slot:subtitle>
                    <x-button link="{{ route('money') }}" class="btn-ghost btn-xs">
                        <x-icon name="fas-arrow-left" class="w-4 h-4" />
                        Back to Accounts
                    </x-button>
                </x-slot:subtitle>
                <x-slot:actions>
                    <!-- Desktop: Full buttons -->
                    <div class="hidden sm:flex gap-2">
                        @if ($account->type === 'manual_account')
                        <x-button wire:click="openAddBalanceModal" class="btn-primary btn-sm">
                            <x-icon name="fas-money-bills" class="w-4 h-4" />
                            Add Balance
                        </x-button>
                        @endif
                        @if (in_array($account->type, ['manual_account', 'monzo_account', 'monzo_pot', 'monzo_archived_pot', 'bank_account']))
                        <x-button wire:click="openEditModal" class="btn-outline btn-sm">
                            <x-icon name="fas-pen" class="w-4 h-4" />
                            Edit Account
                        </x-button>
                        @endif
                        @if (in_array($account->type, ['manual_account', 'monzo_account', 'monzo_pot', 'bank_account']) && !($account->metadata['deleted'] ?? false))
                        <x-button wire:click="openArchiveModal" class="btn-outline btn-error btn-sm">
                            <x-icon name="fas-box-archive" class="w-4 h-4" />
                            Archive
                        </x-button>
                        @endif
                        <x-button wire:click="toggleSidebar" class="btn-ghost btn-sm">
                            <x-icon name="{{ 'fas-sliders' }}" class="w-5 h-5" />
                        </x-button>
                    </div>

                    <!-- Mobile: Dropdown -->
                    <div class="sm:hidden">
                        <x-dropdown>
                            <x-slot:trigger>
                                <x-button class="btn-ghost btn-sm">
                                    <x-icon name="fas-ellipsis-vertical" class="w-5 h-5" />
                                </x-button>
                            </x-slot:trigger>
                            @if ($account->type === 'manual_account')
                            <x-menu-item title="Add Balance" icon="fas-money-bills" wire:click="openAddBalanceModal" />
                            @endif
                            @if (in_array($account->type, ['manual_account', 'monzo_account', 'monzo_pot', 'monzo_archived_pot', 'bank_account']))
                            <x-menu-item title="Edit Account" icon="fas-pen" wire:click="openEditModal" />
                            @endif
                            @if (in_array($account->type, ['manual_account', 'monzo_account', 'monzo_pot', 'bank_account']) && !($account->metadata['deleted'] ?? false))
                            <x-menu-item title="Archive Account" icon="fas-box-archive" wire:click="openArchiveModal" />
                            @endif
                            <x-menu-item title="Back to Accounts" icon="fas-arrow-left" link="{{ route('money') }}" />
                            <x-menu-item title="{{ $showSidebar ? 'Hide Details' : 'Show Details' }}" icon="fas-sliders" wire:click="toggleSidebar" />
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
                            <x-icon name="fas-sterling-sign" class="w-6 h-6 sm:w-8 sm:h-8 text-accent" />
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
                            @if ($account->tags->isNotEmpty())
                            <div class="flex flex-wrap justify-center sm:justify-start gap-2 mt-3">
                                @foreach ($account->tags as $tag)
                                <x-spark-tag :tag="$tag" size="sm" />
                                @endforeach
                            </div>
                            @endif
                        </div>

                        <!-- Current Balance Display -->
                        @if ($displayBalance !== null)
                        <div class="p-4 lg:p-6 rounded-lg bg-base-300/50 border border-base-300 text-center sm:text-left">
                            <div class="text-sm text-base-content/70 mb-2">
                                @if ($this->isNegativeBalance)
                                Current Debt
                                @else
                                Current Balance
                                @endif
                            </div>
                            <div class="text-3xl sm:text-4xl lg:text-5xl font-bold mb-2 {{ $displayBalance < 0 ? 'text-error' : '' }}">
                                @if ($displayBalance < 0)
                                    -{{ $currencySymbol }}{{ number_format(abs($displayBalance), 2) }}
                                    @else
                                    {{ $currencySymbol }}{{ number_format($displayBalance, 2) }}
                                    @endif
                                    </div>
                                    @if ($latestBalance)
                                    <div class="text-sm text-base-content/70">
                                        Last updated {{ $latestBalance->time->diffForHumans() }}
                                    </div>
                                    @elseif ($account->type === 'monzo_pot')
                                    <div class="text-sm text-base-content/70">Current pot balance</div>
                                    @endif
                            </div>
                            @endif
                        </div>
                    </div>
            </x-card>

            <!-- Balance History Chart -->
            <div class="card bg-base-200 shadow">
                <div class="card-body">
                    <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                        <x-icon name="fas-chart-simple" class="w-5 h-5 text-primary" />
                        Balance History
                    </h3>

                    <div class="min-h-[300px]">
                        <livewire:charts.balance-history-chart :account="$account" wire:lazy />
                    </div>
                </div>
            </div>

            <!-- Balance History Table -->
            <div class="card bg-base-200 shadow">
                <div class="card-body">
                    <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                        <x-icon name="o-table-cells" class="w-5 h-5 text-primary" />
                        Balance Events
                    </h3>

                    <x-table
                        :headers="$this->headers()"
                        :rows="$balanceEvents"
                        :sort-by="$sortBy"
                        with-pagination
                        per-page="perPage"
                        :per-page-values="[10, 25, 50, 100]"
                        class="[&_table]:!static [&_td]:!static">

                        @scope('cell_time', $event)
                        <x-uk-date :date="$event->time" />
                        @endscope

                        @scope('cell_balance', $event)
                        @php
                        // Handle different balance storage formats
                        if (isset($event->event_metadata['balance'])) {
                        $balance = $event->event_metadata['balance'];
                        } else {
                        $balance = $event->formatted_value;
                        }

                        // For negative balance accounts, invert the sign for display
                        if ($this->isNegativeBalance && $balance !== null) {
                        $eventDisplayBalance = -$balance;
                        } else {
                        $eventDisplayBalance = $balance;
                        }
                        @endphp
                        @if ($eventDisplayBalance !== null)
                        <span class="font-mono font-medium text-lg {{ $eventDisplayBalance < 0 ? 'text-error' : '' }}">
                            @if ($eventDisplayBalance < 0)
                                -{{ $this->currencySymbol }}{{ number_format(abs($eventDisplayBalance), 2) }}
                                @else
                                {{ $this->currencySymbol }}{{ number_format($eventDisplayBalance, 2) }}
                                @endif
                                </span>
                                @else
                                <span class="text-base-content/50">-</span>
                                @endif
                                @endscope

                                @scope('cell_notes', $event)
                                @php
                                $notes = $event->event_metadata['notes'] ?? null;
                                // For Monzo accounts, show spent today info
                                if ($event->service === 'monzo' && isset($event->event_metadata['spent_today'])) {
                                $notes = 'Spent today: £' . number_format($event->event_metadata['spent_today'], 2);
                                }
                                @endphp
                                <span class="text-sm text-base-content/70">{{ $notes ?: '-' }}</span>
                                @endscope

                                <x-slot:empty>
                                    <div class="text-center py-8">
                                        <x-icon name="fas-chart-simple" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
                                        <h3 class="text-lg font-medium text-base-content mb-2">No balance history</h3>
                                        <p class="text-base-content/70 mb-6">
                                            @if ($account->type === 'manual_account')
                                            Add your first balance update to get started
                                            @elseif (in_array($account->type, ['monzo_account', 'monzo_pot', 'bank_account']))
                                            Balance history will appear here once the account has synced
                                            @else
                                            No balance history available
                                            @endif
                                        </p>
                                        @if ($account->type === 'manual_account')
                                        <button wire:click="openAddBalanceModal" class="btn">
                                            <x-icon name="fas-plus" class="w-4 h-4" />
                                            Add Balance
                                        </button>
                                        @endif
                                    </div>
                                </x-slot:empty>
                    </x-table>
                </div>
            </div>
        </div>

        <!-- Edit Account Modal -->
        @if (in_array($account->type, ['manual_account', 'monzo_account', 'monzo_pot', 'monzo_archived_pot', 'bank_account']))
        <x-modal wire:model="showEditModal" title="Edit Account" subtitle="Update your account details and metadata" separator>
            <livewire:edit-financial-account :account="$account" :key="'edit-account-' . $account->id" />
        </x-modal>
        @endif

        <!-- Archive Account Modal -->
        @if (in_array($account->type, ['manual_account', 'monzo_account', 'monzo_pot', 'bank_account']) && !($account->metadata['deleted'] ?? false))
        <x-modal wire:model="showArchiveModal" title="Archive Account" subtitle="Mark this account as archived and create a final balance event" separator>
            <livewire:archive-financial-account :account="$account" :key="'archive-account-' . $account->id" />
        </x-modal>
        @endif

        <!-- Add Balance Update Modal -->
        @if ($account->type === 'manual_account')
        <x-modal wire:model="showAddBalanceModal" title="Add Balance Update" subtitle="Record a new balance for this account" separator>
            <livewire:add-balance-update :account="$account->id" :key="'add-balance-update-' . $account->id" />
        </x-modal>
        @endif

        <!-- Drawer for Account Details -->
        <x-drawer wire:model="showSidebar" right title="Account Details" separator with-close-button class="w-11/12 lg:w-1/3">
            <div class="space-y-4 lg:space-y-6">
                <!-- Tags Manager -->
                <x-card class="bg-base-100 shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-base-content flex items-center gap-2">
                            <x-icon name="fas-tag" class="w-5 h-5" />
                            Tags
                        </h3>
                        <button type="button" wire:click="openCreateTagModal" class="btn btn-xs btn-ghost btn-circle" title="Create new tag">
                            <x-icon name="fas-plus" class="w-3 h-3" />
                        </button>
                    </div>
                    <div class="space-y-2" wire:key="object-tags-{{ $account->id }}" wire:ignore>
                        <input id="tag-input-{{ $account->id }}" data-tagify data-initial="tag-initial-{{ $account->id }}" data-suggestions-id="tag-suggestions-{{ $account->id }}" aria-label="Tags" class="input input-sm w-full" placeholder="Add tags" />
                        <script type="application/json" id="tag-initial-{{ $account->id }}">
                                {!! json_encode($account->tags->map(fn($tag) => ['value' => (string) $tag->name, 'type' => $tag->type ? (string) $tag->type : null])->values()->all()) !!}
                            </script>
                            <script type="application/json" id="tag-suggestions-{{ $account->id }}">
                                {!! json_encode(\Spatie\Tags\Tag::query()->select(['name', 'type'])->get()->map(fn($tag) => ['value' => (string) $tag->name, 'type' => $tag->type ? (string) $tag->type : null])->values()->all()) !!}
                            </script>
                    </div>
                </x-card>
                <!-- Account Information -->
                <div>
                    <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                        <x-icon name="fas-building-columns" class="w-5 h-5 text-primary" />
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

                        @if ($account->type === 'manual_account')
                        <div>
                            <span class="text-base-content/70">Balance Type:</span>
                            <span class="font-medium">
                                @if ($this->isNegativeBalance)
                                <span class="text-error">Debt Account</span>
                                @else
                                Asset Account
                                @endif
                            </span>
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

                <!-- Underlying Object -->
                <div>
                    <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                        <x-icon name="o-cube" class="w-5 h-5 text-primary" />
                        Event Object
                    </h3>
                    <div class="space-y-3 text-sm">
                        <div>
                            <span class="text-base-content/70">Object ID:</span>
                            <span class="font-mono font-medium text-xs">{{ $account->id }}</span>
                        </div>

                        <div>
                            <span class="text-base-content/70">Type:</span>
                            <span class="font-medium">{{ $account->type }}</span>
                        </div>

                        @if ($account->concept)
                        <div>
                            <span class="text-base-content/70">Concept:</span>
                            <span class="font-medium">{{ $account->concept }}</span>
                        </div>
                        @endif

                        <div>
                            <span class="text-base-content/70">Created:</span>
                            <span class="font-medium">{{ $account->created_at->format('M j, Y') }}</span>
                        </div>

                        <div class="pt-2">
                            <a href="{{ route('objects.show', $account->id) }}" class="btn btn-outline w-full">
                                <x-icon name="o-cube" class="w-4 h-4" />
                                View Object
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Raw Metadata (Collapsible) -->
                <x-collapse wire:model="metadataOpen">
                    <x-slot:heading>
                        <div class="text-lg font-semibold text-base-content flex items-center gap-2">
                            <x-icon name="fas-code" class="w-5 h-5 text-primary" />
                            Raw Metadata
                        </div>
                    </x-slot:heading>
                    <x-slot:content>
                        <pre class="text-xs bg-base-300 p-3 rounded overflow-x-auto"><code>{{ json_encode($metadata, JSON_PRETTY_PRINT) }}</code></pre>
                    </x-slot:content>
                </x-collapse>

                <!-- Danger Zone -->
                <div class="pt-6 border-t border-error/20">
                    <button
                        wire:click="deleteAccount"
                        wire:confirm="Are you sure you want to delete this account? This will also delete all balance history."
                        class="btn btn-error btn-outline w-full">
                        <x-icon name="fas-trash" class="w-4 h-4" />
                        Delete Account
                    </button>
                </div>
            </div>
        </x-drawer>
    </div>
    <!-- Create Tag Modal -->
    <x-modal wire:model="showCreateTagModal" title="Create New Tag" subtitle="Define a new tag with a specific type" separator>
        <livewire:create-tag :key="'create-tag-object-' . $account->id" @tag-created="handleTagCreated" />
    </x-modal>
</div>