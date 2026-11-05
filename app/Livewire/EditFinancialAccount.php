<?php

namespace App\Livewire;

use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class EditFinancialAccount extends Component
{
    public EventObject $account;

    public string $name = '';

    public string $accountType = '';

    public string $provider = '';

    public ?string $accountNumber = null;

    public ?string $sortCode = null;

    public string $currency = 'GBP';

    public ?float $interestRate = null;

    public ?string $startDate = null;

    public bool $isNegativeBalance = false;

    public bool $showModal = false;

    protected $rules = [
        'name' => 'required|string|max:255',
        'accountType' => 'required|string|in:current_account,savings_account,mortgage,investment_account,credit_card,loan,pension,other',
        'provider' => 'required|string|max:255',
        'accountNumber' => 'nullable|string|max:255',
        'sortCode' => 'nullable|string|max:8',
        'currency' => 'required|string|in:GBP,USD,EUR',
        'interestRate' => 'nullable|numeric|min:0|max:100',
        'startDate' => 'nullable|date',
        'isNegativeBalance' => 'boolean',
    ];

    public function mount(EventObject $account): void
    {
        // Ensure user owns this account
        if ($account->user_id !== Auth::id()) {
            abort(403);
        }

        $this->account = $account;
        $metadata = $account->metadata;

        // Populate form fields from metadata
        $this->name = $metadata['name'] ?? $account->title;
        $this->accountType = $metadata['account_type'] ?? '';
        $this->provider = $metadata['provider'] ?? '';
        $this->accountNumber = $metadata['account_number'] ?? null;
        $this->sortCode = $metadata['sort_code'] ?? null;
        $this->currency = $metadata['currency'] ?? 'GBP';
        $this->interestRate = $metadata['interest_rate'] ?? null;
        $this->startDate = $metadata['start_date'] ?? null;
        $this->isNegativeBalance = $metadata['is_negative_balance'] ?? false;
    }

    public function updatedAccountType($value): void
    {
        // Automatically set isNegativeBalance based on account type
        $negativeBalanceTypes = ['credit_card', 'loan', 'mortgage'];
        $this->isNegativeBalance = in_array($value, $negativeBalanceTypes);
    }

    public function save(): void
    {
        $this->validate();

        // Update the account object metadata directly
        $currentMetadata = $this->account->metadata ?? [];

        // Preserve fields that shouldn't be changed
        $preservedFields = [
            'account_id',
            'pot_id',
            'integration_id',
            'raw',
        ];

        $updatedMetadata = array_merge($currentMetadata, [
            'name' => $this->name,
            'account_type' => $this->accountType,
            'provider' => $this->provider,
            'account_number' => $this->accountNumber,
            'sort_code' => $this->sortCode,
            'currency' => $this->currency,
            'interest_rate' => $this->interestRate,
            'start_date' => $this->startDate,
            'is_negative_balance' => $this->isNegativeBalance,
        ]);

        // Preserve the original fields
        foreach ($preservedFields as $field) {
            if (isset($currentMetadata[$field])) {
                $updatedMetadata[$field] = $currentMetadata[$field];
            }
        }

        // Update title and metadata
        $this->account->update([
            'title' => $this->name,
            'metadata' => $updatedMetadata,
        ]);

        // For manual accounts, also update the integration configuration
        if ($this->account->type === 'manual_account' && isset($currentMetadata['integration_id'])) {
            $integration = Integration::where('user_id', Auth::id())
                ->where('service', 'manual_account')
                ->where('id', $currentMetadata['integration_id'])
                ->first();

            if ($integration) {
                $integration->update([
                    'name' => $this->name,
                    'configuration' => [
                        'account_type' => $this->accountType,
                        'provider' => $this->provider,
                        'account_number' => $this->accountNumber,
                        'sort_code' => $this->sortCode,
                        'currency' => $this->currency,
                        'interest_rate' => $this->interestRate,
                        'start_date' => $this->startDate,
                        'is_negative_balance' => $this->isNegativeBalance,
                    ],
                ]);
            }
        }

        $this->showModal = false;
        $this->dispatch('account-updated');
        $this->dispatch('close-modal');
    }

    public function render(): View
    {
        return view('livewire.edit-financial-account');
    }
}
