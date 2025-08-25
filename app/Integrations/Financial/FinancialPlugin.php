<?php

namespace App\Integrations\Financial;

use App\Integrations\Base\ManualPlugin;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;

class FinancialPlugin extends ManualPlugin
{
    public static function getIdentifier(): string
    {
        return 'financial';
    }

    public static function getDisplayName(): string
    {
        return 'Financial Accounts';
    }

    public static function getDescription(): string
    {
        return 'Manually track your financial accounts including mortgages, savings, investments, and current accounts.';
    }

    public static function getConfigurationSchema(): array
    {
        return [
            'account_type' => [
                'type' => 'select',
                'label' => 'Account Type',
                'description' => 'The type of financial account',
                'options' => [
                    'current_account' => 'Current Account',
                    'savings_account' => 'Savings Account',
                    'mortgage' => 'Mortgage',
                    'investment_account' => 'Investment Account',
                    'credit_card' => 'Credit Card',
                    'loan' => 'Loan',
                    'pension' => 'Pension',
                    'other' => 'Other',
                ],
                'required' => true,
            ],
            'provider' => [
                'type' => 'text',
                'label' => 'Provider',
                'description' => 'The bank or financial institution',
                'required' => true,
            ],
            'account_number' => [
                'type' => 'text',
                'label' => 'Account Number',
                'description' => 'Account number or identifier (optional)',
                'required' => false,
            ],
            'sort_code' => [
                'type' => 'text',
                'label' => 'Sort Code',
                'description' => 'Sort code for UK bank accounts (optional)',
                'required' => false,
            ],
            'currency' => [
                'type' => 'select',
                'label' => 'Currency',
                'description' => 'The currency for this account',
                'options' => [
                    'GBP' => 'British Pound (£)',
                    'USD' => 'US Dollar ($)',
                    'EUR' => 'Euro (€)',
                ],
                'required' => true,
                'default' => 'GBP',
            ],
            'interest_rate' => [
                'type' => 'number',
                'label' => 'Interest Rate (%)',
                'description' => 'Annual interest rate (optional)',
                'required' => false,
                'step' => 0.01,
                'min' => 0,
                'max' => 100,
            ],
            'start_date' => [
                'type' => 'date',
                'label' => 'Start Date',
                'description' => 'When you opened this account (optional)',
                'required' => false,
            ],
        ];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'accounts' => [
                'label' => 'Financial Accounts',
                'schema' => self::getConfigurationSchema(),
            ],
            'balances' => [
                'label' => 'Balance Updates',
                'schema' => [
                    'balance' => [
                        'type' => 'number',
                        'label' => 'Balance',
                        'description' => 'Current balance in the account',
                        'required' => true,
                        'step' => 0.01,
                    ],
                    'date' => [
                        'type' => 'date',
                        'label' => 'Date',
                        'description' => 'Date of this balance update',
                        'required' => true,
                    ],
                    'notes' => [
                        'type' => 'textarea',
                        'label' => 'Notes',
                        'description' => 'Optional notes about this update',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * Create or update a financial account object
     */
    public function upsertAccountObject(Integration $integration, array $accountData): EventObject
    {
        $title = $accountData['name'] ?? 'Financial Account';

        return EventObject::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'concept' => 'account',
                'type' => 'financial_account',
                'title' => $title,
            ],
            [
                'user_id' => $integration->user_id,
                'time' => now(),
                'content' => null,
                'metadata' => [
                    'name' => $accountData['name'],
                    'account_type' => $accountData['account_type'],
                    'provider' => $accountData['provider'],
                    'account_number' => $accountData['account_number'] ?? null,
                    'sort_code' => $accountData['sort_code'] ?? null,
                    'currency' => $accountData['currency'] ?? 'GBP',
                    'interest_rate' => $accountData['interest_rate'] ?? null,
                    'start_date' => $accountData['start_date'] ?? null,
                    'raw' => $accountData,
                ],
            ]
        );
    }

    /**
     * Create a balance update event
     */
    public function createBalanceEvent(Integration $integration, EventObject $accountObject, array $balanceData): Event
    {
        $date = $balanceData['date'] ?? now()->toDateString();
        $balance = (float) ($balanceData['balance'] ?? 0);

        // Create or update the target "day" object
        $dayObject = EventObject::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'concept' => 'day',
                'type' => 'day',
                'title' => $date,
            ],
            [
                'user_id' => $integration->user_id,
                'time' => $date . ' 00:00:00',
                'content' => null,
                'metadata' => ['date' => $date],
            ]
        );

        // Convert decimal to whole number using multiplier
        $multiplier = 100; // Use 100 for 2 decimal places (cents/pence)
        $wholeValue = (int) (abs($balance) * $multiplier);

        return Event::create([
            'integration_id' => $integration->id,
            'source_id' => 'financial_balance_' . $accountObject->id . '_' . $date,
            'time' => $date . ' 23:59:59',
            'actor_id' => $accountObject->id,
            'service' => 'financial',
            'domain' => 'money',
            'action' => 'had_balance',
            'value' => $wholeValue,
            'value_multiplier' => $multiplier,
            'value_unit' => $accountObject->metadata['currency'] ?? 'GBP',
            'event_metadata' => [
                'balance' => $balance,
                'notes' => $balanceData['notes'] ?? null,
                'account_name' => $accountObject->metadata['name'],
                'account_type' => $accountObject->metadata['account_type'],
                'provider' => $accountObject->metadata['provider'],
            ],
            'target_id' => $dayObject->id,
        ]);
    }

    /**
     * Get all financial accounts for a user
     */
    public function getFinancialAccounts(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return EventObject::where('user_id', $user->id)
            ->where('concept', 'account')
            ->where('type', 'financial_account')
            ->orderBy('title')
            ->get();
    }

    /**
     * Get balance events for a specific account
     */
    public function getBalanceEvents(EventObject $accountObject): \Illuminate\Database\Eloquent\Collection
    {
        return Event::where('actor_id', $accountObject->id)
            ->where('service', 'financial')
            ->where('action', 'had_balance')
            ->orderBy('time', 'desc')
            ->get();
    }

    /**
     * Get the latest balance for an account
     */
    public function getLatestBalance(EventObject $accountObject): ?Event
    {
        return Event::where('actor_id', $accountObject->id)
            ->where('service', 'financial')
            ->where('action', 'had_balance')
            ->latest('time')
            ->first();
    }
}
