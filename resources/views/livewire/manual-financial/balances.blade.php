<?php

use App\Integrations\Manual\ManualFinancialPlugin;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public Integration $integration;
    public array $accounts = [];
    public array $balances = [];
    public bool $showCreateForm = false;
    public array $formData = [
        'account_id' => '',
        'balance' => '',
        'date' => '',
        'notes' => '',
    ];

    public function mount(Integration $integration): void
    {
        $this->integration = $integration;
        $this->loadAccounts();
        $this->loadBalances();
        $this->formData['date'] = now()->format('Y-m-d');
    }

    public function loadAccounts(): void
    {
        $this->accounts = EventObject::where('user_id', Auth::id())
            ->where('concept', 'financial_account')
            ->where('type', 'account')
            ->orderBy('title')
            ->get()
            ->map(function ($account) {
                return [
                    'id' => $account->id,
                    'title' => $account->title,
                    'currency' => $account->metadata['currency'] ?? 'GBP',
                ];
            })
            ->toArray();
    }

    public function loadBalances(): void
    {
        $this->balances = Event::where('integration_id', $this->integration->id)
            ->where('domain', 'finance')
            ->where('action', 'balance_update')
            ->with(['actor'])
            ->orderBy('time', 'desc')
            ->get()
            ->map(function ($event) {
                $account = $event->actor;
                return [
                    'id' => $event->id,
                    'account_title' => $account ? $account->title : 'Unknown Account',
                    'balance' => $event->value,
                    'currency' => $event->event_metadata['currency'] ?? 'GBP',
                    'date' => $event->time->format('d/m/Y'),
                    'notes' => $event->event_metadata['notes'] ?? null,
                    'created_at' => $event->created_at->format('d/m/Y H:i'),
                ];
            })
            ->toArray();
    }

    public function createBalanceUpdate(): void
    {
        $this->validate([
            'formData.account_id' => 'required|string|exists:objects,id',
            'formData.balance' => 'required|numeric',
            'formData.date' => 'required|date',
            'formData.notes' => 'nullable|string|max:1000',
        ]);

        $plugin = new ManualFinancialPlugin();
        $plugin->createBalanceUpdate($this->integration, $this->formData);

        $this->showCreateForm = false;
        $this->resetForm();
        $this->loadBalances();
        $this->success('Balance update created successfully!');
    }

    public function resetForm(): void
    {
        $this->formData = [
            'account_id' => '',
            'balance' => '',
            'date' => now()->format('Y-m-d'),
            'notes' => '',
        ];
    }

    public function deleteBalance(string $balanceId): void
    {
        $balance = Event::find($balanceId);
        if ($balance && $balance->integration_id === $this->integration->id) {
            $balance->delete();
            $this->loadBalances();
            $this->success('Balance update deleted successfully!');
        }
    }

    public function getCurrencySymbol(string $currency): string
    {
        return [
            'GBP' => '£',
            'EUR' => '€',
            'USD' => '$',
        ][$currency] ?? $currency;
    }

    public function formatBalance(float $balance, string $currency): string
    {
        $symbol = $this->getCurrencySymbol($currency);
        return $symbol . number_format($balance, 2);
    }
}; ?>

<div>
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold">Balance Updates</h2>
        <x-button
            label="Add Balance Update"
            icon="o-plus"
            class="btn-primary"
            wire:click="$set('showCreateForm', true)"
        />
    </div>

    @if ($showCreateForm)
        <x-card class="mb-6">
            <h3 class="text-lg font-semibold mb-4">Create New Balance Update</h3>
            <form wire:submit="createBalanceUpdate">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-select
                        label="Account"
                        wire:model="formData.account_id"
                        :options="collect($accounts)->pluck('title', 'id')->toArray()"
                        placeholder="Select an account"
                        required
                    />

                    <x-input
                        label="Balance"
                        wire:model="formData.balance"
                        type="number"
                        step="0.01"
                        placeholder="0.00"
                        required
                    />

                    <x-input
                        label="Date"
                        wire:model="formData.date"
                        type="date"
                        required
                    />

                    <x-textarea
                        label="Notes"
                        wire:model="formData.notes"
                        placeholder="Optional notes about this update"
                        rows="3"
                    />
                </div>

                <div class="flex gap-2 mt-6">
                    <x-button
                        type="submit"
                        label="Create Update"
                        class="btn-primary"
                        spinner="createBalanceUpdate"
                    />
                    <x-button
                        label="Cancel"
                        class="btn-ghost"
                        wire:click="$set('showCreateForm', false)"
                    />
                </div>
            </form>
        </x-card>
    @endif

    @if (empty($balances))
        <x-card>
            <div class="text-center py-8">
                <x-icon name="o-chart-line" class="w-16 h-16 text-base-300 mx-auto mb-4" />
                <h3 class="text-lg font-semibold mb-2">No balance updates yet</h3>
                <p class="text-base-content/70 mb-4">Create your first balance update to start tracking your account balances over time.</p>
                <x-button
                    label="Add Balance Update"
                    icon="o-plus"
                    class="btn-primary"
                    wire:click="$set('showCreateForm', true)"
                />
            </div>
        </x-card>
    @else
        <div class="space-y-4">
            @foreach ($balances as $balance)
                <x-card>
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <div class="flex items-center gap-3 mb-2">
                                <h3 class="font-semibold text-lg">{{ $balance['account_title'] }}</h3>
                                <span class="badge badge-primary">{{ $balance['currency'] }}</span>
                            </div>

                            <div class="text-2xl font-bold text-primary mb-2">
                                {{ $this->formatBalance($balance['balance'], $balance['currency']) }}
                            </div>

                            <div class="flex items-center gap-4 text-sm text-base-content/70">
                                <span>Date: {{ $balance['date'] }}</span>
                                <span>Added: {{ $balance['created_at'] }}</span>
                            </div>

                            @if ($balance['notes'])
                                <div class="mt-3 p-3 bg-base-200 rounded-lg">
                                    <p class="text-sm">{{ $balance['notes'] }}</p>
                                </div>
                            @endif
                        </div>

                        <x-dropdown>
                            <x-slot:trigger>
                                <x-button icon="o-ellipsis-vertical" class="btn-ghost btn-sm" />
                            </x-slot:trigger>
                            <x-menu-item
                                title="Delete"
                                icon="o-trash"
                                class="text-error"
                                wire:click="deleteBalance('{{ $balance['id'] }}')"
                            />
                        </x-dropdown>
                    </div>
                </x-card>
            @endforeach
        </div>
    @endif
</div>