<?php

use App\Integrations\Manual\ManualFinancialPlugin;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public Integration $integration;
    public array $accounts = [];
    public bool $showCreateForm = false;
    public array $formData = [
        'account_type' => '',
        'provider_name' => '',
        'account_number' => '',
        'currency' => 'GBP',
        'interest_rate' => '',
    ];

    public function mount(Integration $integration): void
    {
        $this->integration = $integration;
        $this->loadAccounts();
    }

    public function loadAccounts(): void
    {
        $this->accounts = EventObject::where('user_id', Auth::id())
            ->where('concept', 'financial_account')
            ->where('type', 'account')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($account) {
                return [
                    'id' => $account->id,
                    'title' => $account->title,
                    'account_type' => $account->metadata['account_type'] ?? 'unknown',
                    'provider_name' => $account->metadata['provider_name'] ?? 'Unknown',
                    'currency' => $account->metadata['currency'] ?? 'GBP',
                    'interest_rate' => $account->metadata['interest_rate'] ?? null,
                    'created_at' => $account->created_at->format('d/m/Y'),
                ];
            })
            ->toArray();
    }

    public function createAccount(): void
    {
        $this->validate([
            'formData.account_type' => 'required|string',
            'formData.provider_name' => 'required|string|max:255',
            'formData.account_number' => 'nullable|string|max:255',
            'formData.currency' => 'required|string|in:GBP,EUR,USD',
            'formData.interest_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $plugin = new ManualFinancialPlugin();
        $plugin->createAccount($this->integration, $this->formData);

        $this->showCreateForm = false;
        $this->resetForm();
        $this->loadAccounts();
        $this->success('Account created successfully!');
    }

    public function resetForm(): void
    {
        $this->formData = [
            'account_type' => '',
            'provider_name' => '',
            'account_number' => '',
            'currency' => 'GBP',
            'interest_rate' => '',
        ];
    }

    public function deleteAccount(string $accountId): void
    {
        $account = EventObject::find($accountId);
        if ($account && $account->user_id === Auth::id()) {
            $account->delete();
            $this->loadAccounts();
            $this->success('Account deleted successfully!');
        }
    }

    public function getAccountTypeLabel(string $type): string
    {
        $types = [
            'mortgage' => 'Mortgage',
            'savings' => 'Savings Account',
            'current' => 'Current Account',
            'investment' => 'Investment Account',
            'credit_card' => 'Credit Card',
            'loan' => 'Personal Loan',
            'pension' => 'Pension',
            'other' => 'Other',
        ];

        return $types[$type] ?? ucfirst($type);
    }


}; ?>

<div>
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold">Financial Accounts</h2>
        <x-button 
            label="Add Account" 
            icon="o-plus" 
            class="btn-primary"
            wire:click="$set('showCreateForm', true)"
        />
    </div>

    @if($showCreateForm)
        <x-card class="mb-6">
            <h3 class="text-lg font-semibold mb-4">Create New Account</h3>
            <form wire:submit="createAccount">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-select 
                        label="Account Type" 
                        wire:model="formData.account_type"
                        :options="[
                            'mortgage' => 'Mortgage',
                            'savings' => 'Savings Account',
                            'current' => 'Current Account',
                            'investment' => 'Investment Account',
                            'credit_card' => 'Credit Card',
                            'loan' => 'Personal Loan',
                            'pension' => 'Pension',
                            'other' => 'Other',
                        ]"
                        required
                    />
                    
                    <x-input 
                        label="Provider Name" 
                        wire:model="formData.provider_name"
                        placeholder="e.g. Barclays, Santander"
                        required
                    />
                    
                    <x-input 
                        label="Account Number" 
                        wire:model="formData.account_number"
                        placeholder="Optional account reference"
                    />
                    
                    <x-select 
                        label="Currency" 
                        wire:model="formData.currency"
                        :options="[
                            'GBP' => 'British Pound (£)',
                            'EUR' => 'Euro (€)',
                            'USD' => 'US Dollar ($)',
                        ]"
                        required
                    />
                    
                    <x-input 
                        label="Interest Rate (%)" 
                        wire:model="formData.interest_rate"
                        type="number"
                        step="0.01"
                        min="0"
                        max="100"
                        placeholder="Optional annual rate"
                    />
                </div>
                
                <div class="flex gap-2 mt-6">
                    <x-button 
                        type="submit" 
                        label="Create Account" 
                        class="btn-primary"
                        spinner="createAccount"
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

    @if(empty($accounts))
        <x-card>
            <div class="text-center py-8">
                <x-icon name="o-banknotes" class="w-16 h-16 text-base-300 mx-auto mb-4" />
                <h3 class="text-lg font-semibold mb-2">No accounts yet</h3>
                <p class="text-base-content/70 mb-4">Create your first financial account to start tracking your finances.</p>
                <x-button 
                    label="Create Account" 
                    icon="o-plus" 
                    class="btn-primary"
                    wire:click="$set('showCreateForm', true)"
                />
            </div>
        </x-card>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($accounts as $account)
                <x-card>
                    <div class="flex justify-between items-start mb-3">
                        <div class="flex-1">
                            <h3 class="font-semibold text-lg">{{ $account['title'] }}</h3>
                            <p class="text-sm text-base-content/70">{{ $account['provider_name'] }}</p>
                        </div>
                        <x-dropdown>
                            <x-slot:trigger>
                                <x-button icon="o-ellipsis-vertical" class="btn-ghost btn-sm" />
                            </x-slot:trigger>
                            <x-menu-item 
                                title="Delete" 
                                icon="o-trash" 
                                class="text-error"
                                wire:click="deleteAccount('{{ $account['id'] }}')"
                            />
                        </x-dropdown>
                    </div>
                    
                    <div class="space-y-2">
                        <div class="flex justify-between">
                            <span class="text-sm text-base-content/70">Type:</span>
                            <span class="text-sm font-medium">{{ $this->getAccountTypeLabel($account['account_type']) }}</span>
                        </div>
                        
                        <div class="flex justify-between">
                            <span class="text-sm text-base-content/70">Currency:</span>
                            <span class="text-sm font-medium">{{ $account['currency'] }}</span>
                        </div>
                        
                        @if($account['interest_rate'])
                            <div class="flex justify-between">
                                <span class="text-sm text-base-content/70">Interest Rate:</span>
                                <span class="text-sm font-medium">{{ $account['interest_rate'] }}%</span>
                            </div>
                        @endif
                        
                        <div class="flex justify-between">
                            <span class="text-sm text-base-content/70">Created:</span>
                            <span class="text-sm text-base-content/70">{{ $account['created_at'] }}</span>
                        </div>
                    </div>
                </x-card>
            @endforeach
        </div>
    @endif
</div>