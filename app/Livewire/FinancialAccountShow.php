<?php

namespace App\Livewire;

use App\Integrations\Financial\FinancialPlugin;
use App\Models\EventObject;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Account Details')]
class FinancialAccountShow extends Component
{
    public EventObject $account;

    public function mount(EventObject $account): void
    {
        // Ensure user owns this account
        if ($account->user_id !== Auth::id()) {
            abort(403);
        }
    }

    public function render(): View
    {
        $plugin = new FinancialPlugin;
        $metadata = $this->account->metadata;
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
        $latestBalance = $plugin->getLatestBalance($this->account);

        // Handle different balance storage formats
        if ($latestBalance) {
            if (isset($latestBalance->event_metadata['balance'])) {
                // Manual accounts store balance in event_metadata
                $currentBalance = $latestBalance->event_metadata['balance'];
            } else {
                // Monzo/GoCardless store balance in value field (integer cents)
                $currentBalance = $latestBalance->formatted_value;
            }
        } elseif ($this->account->type === 'monzo_pot' && ! empty($this->account->content)) {
            // Monzo pots store balance in content field
            $currentBalance = (float) $this->account->content;
        } else {
            $currentBalance = null;
        }

        // Get balance history
        $balanceEvents = $plugin->getBalanceEvents($this->account);

        return view('livewire.money.show', [
            'metadata' => $metadata,
            'accountTypeLabel' => $accountTypeLabel,
            'provider' => $provider,
            'accountNumber' => $accountNumber,
            'sortCode' => $sortCode,
            'currency' => $currency,
            'currencySymbol' => $currencySymbol,
            'interestRate' => $interestRate,
            'startDate' => $startDate,
            'currentBalance' => $currentBalance,
            'latestBalance' => $latestBalance,
            'balanceEvents' => $balanceEvents,
        ]);
    }
}
