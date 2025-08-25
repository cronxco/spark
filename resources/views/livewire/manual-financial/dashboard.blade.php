<?php

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public Integration $integration;
    public array $summary = [];
    public array $recentBalances = [];

    public function mount(Integration $integration): void
    {
        $this->integration = $integration;
        $this->loadSummary();
        $this->loadRecentBalances();
    }

    public function loadSummary(): void
    {
        $userId = Auth::id();
        
        // Get total accounts
        $totalAccounts = EventObject::where('user_id', $userId)
            ->where('concept', 'financial_account')
            ->where('type', 'account')
            ->count();
        
        // Get total balance updates
        $totalUpdates = Event::where('integration_id', $this->integration->id)
            ->where('domain', 'finance')
            ->where('action', 'balance_update')
            ->count();
        
        // Get latest balance for each account
        $latestBalances = Event::where('integration_id', $this->integration->id)
            ->where('domain', 'finance')
            ->where('action', 'balance_update')
            ->with(['actor'])
            ->get()
            ->groupBy('actor_id')
            ->map(function ($events) {
                return $events->sortByDesc('time')->first();
            })
            ->filter()
            ->values();
        
        $totalValue = $latestBalances->sum('value');
        
        $this->summary = [
            'total_accounts' => $totalAccounts,
            'total_updates' => $totalUpdates,
            'total_value' => $totalValue,
            'currency' => 'GBP', // Default, could be made dynamic
        ];
    }

    public function loadRecentBalances(): void
    {
        $this->recentBalances = Event::where('integration_id', $this->integration->id)
            ->where('domain', 'finance')
            ->where('action', 'balance_update')
            ->with(['actor'])
            ->orderBy('time', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($event) {
                $account = $event->actor;
                return [
                    'id' => $event->id,
                    'account_title' => $account ? $account->title : 'Unknown Account',
                    'balance' => $event->value,
                    'currency' => $event->event_metadata['currency'] ?? 'GBP',
                    'date' => $event->time->format('d/m/Y'),
                    'account_type' => $account ? ($account->metadata['account_type'] ?? 'unknown') : 'unknown',
                ];
            })
            ->toArray();
    }

    public function getAccountTypeLabel(string $type): string
    {
        $types = [
            'mortgage' => 'Mortgage',
            'savings' => 'Savings',
            'current' => 'Current',
            'investment' => 'Investment',
            'credit_card' => 'Credit Card',
            'loan' => 'Loan',
            'pension' => 'Pension',
            'other' => 'Other',
        ];

        return $types[$type] ?? ucfirst($type);
    }

    public function formatBalance(float $balance, string $currency): string
    {
        $symbols = [
            'GBP' => '£',
            'EUR' => '€',
            'USD' => '$',
        ];
        $symbol = $symbols[$currency] ?? $currency;
        return $symbol . number_format($balance, 2);
    }
}; ?>

<div>
    <div class="mb-8">
        <h1 class="text-3xl font-bold mb-2">Manual Financial Dashboard</h1>
        <p class="text-base-content/70">Track your financial accounts and balances manually for banks without API support.</p>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <x-card>
            <div class="flex items-center gap-4">
                <div class="p-3 bg-primary/10 rounded-lg">
                    <x-icon name="o-banknotes" class="w-8 h-8 text-primary" />
                </div>
                <div>
                    <p class="text-sm text-base-content/70">Total Accounts</p>
                    <p class="text-2xl font-bold">{{ $summary['total_accounts'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center gap-4">
                <div class="p-3 bg-secondary/10 rounded-lg">
                    <x-icon name="o-chart-line" class="w-8 h-8 text-secondary" />
                </div>
                <div>
                    <p class="text-sm text-base-content/70">Total Updates</p>
                    <p class="text-2xl font-bold">{{ $summary['total_updates'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center gap-4">
                <div class="p-3 bg-accent/10 rounded-lg">
                    <x-icon name="o-currency-pound" class="w-8 h-8 text-accent" />
                </div>
                <div>
                    <p class="text-sm text-base-content/70">Total Value</p>
                    <p class="text-2xl font-bold">{{ $this->formatBalance($summary['total_value'], $summary['currency']) }}</p>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Quick Actions -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
        <x-card>
            <div class="text-center py-6">
                <x-icon name="o-plus-circle" class="w-16 h-16 text-primary mx-auto mb-4" />
                <h3 class="text-lg font-semibold mb-2">Add New Account</h3>
                <p class="text-base-content/70 mb-4">Create a new financial account to start tracking.</p>
                <x-button 
                    label="Create Account" 
                    icon="o-plus" 
                    class="btn-primary"
                    link="/integrations/{{ $integration->id }}/accounts"
                />
            </div>
        </x-card>

        <x-card>
            <div class="text-center py-6">
                <x-icon name="o-chart-line" class="w-16 h-16 text-secondary mx-auto mb-4" />
                <h3 class="text-lg font-semibold mb-2">Update Balance</h3>
                <p class="text-base-content/70 mb-4">Add a new balance update for any of your accounts.</p>
                <x-button 
                    label="Add Update" 
                    icon="o-plus" 
                    class="btn-secondary"
                    link="/integrations/{{ $integration->id }}/balances"
                />
            </div>
        </x-card>
    </div>

    <!-- Recent Balance Updates -->
    @if(!empty($recentBalances))
        <x-card>
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-semibold">Recent Balance Updates</h2>
                <x-button 
                    label="View All" 
                    icon="o-arrow-right" 
                    class="btn-ghost"
                    link="/integrations/{{ $integration->id }}/balances"
                />
            </div>
            
            <div class="space-y-4">
                @foreach($recentBalances as $balance)
                    <div class="flex items-center justify-between p-4 bg-base-100 rounded-lg border">
                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 bg-primary/10 rounded-full flex items-center justify-center">
                                <x-icon name="o-banknotes" class="w-5 h-5 text-primary" />
                            </div>
                            <div>
                                <h4 class="font-medium">{{ $balance['account_title'] }}</h4>
                                <p class="text-sm text-base-content/70">
                                    {{ $this->getAccountTypeLabel($balance['account_type']) }} • {{ $balance['date'] }}
                                </p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-lg font-bold text-primary">
                                {{ $this->formatBalance($balance['balance'], $balance['currency']) }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif

    <!-- Navigation -->
    <div class="mt-8">
        <h2 class="text-xl font-semibold mb-4">Manage Your Finances</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <x-button 
                label="Manage Accounts" 
                icon="o-banknotes" 
                class="btn-outline btn-lg justify-start"
                link="/integrations/{{ $integration->id }}/accounts"
            />
            <x-button 
                label="Manage Balances" 
                icon="o-chart-line" 
                class="btn-outline btn-lg justify-start"
                link="/integrations/{{ $integration->id }}/balances"
            />
        </div>
    </div>
</div>