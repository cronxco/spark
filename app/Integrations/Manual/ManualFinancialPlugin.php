<?php

namespace App\Integrations\Manual;

use App\Integrations\Base\ManualPlugin;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use InvalidArgumentException;

class ManualFinancialPlugin extends ManualPlugin
{
    public static function getIdentifier(): string
    {
        return 'manual-financial';
    }

    public static function getDisplayName(): string
    {
        return 'Manual Financial';
    }

    public static function getDescription(): string
    {
        return 'Manually track your financial accounts, including mortgages, savings accounts, and investments. Perfect for banks and providers without API support.';
    }

    public static function getServiceType(): string
    {
        return 'manual';
    }

    public static function getConfigurationSchema(): array
    {
        return [
            'account_type' => [
                'type' => 'select',
                'label' => 'Account Type',
                'description' => 'The type of financial account',
                'options' => [
                    'mortgage' => 'Mortgage',
                    'savings' => 'Savings Account',
                    'current' => 'Current Account',
                    'investment' => 'Investment Account',
                    'credit_card' => 'Credit Card',
                    'loan' => 'Personal Loan',
                    'pension' => 'Pension',
                    'other' => 'Other',
                ],
                'required' => true,
            ],
            'provider_name' => [
                'type' => 'text',
                'label' => 'Provider Name',
                'description' => 'The name of your bank or financial provider',
                'required' => true,
            ],
            'account_number' => [
                'type' => 'text',
                'label' => 'Account Number',
                'description' => 'Your account number or reference (optional)',
                'required' => false,
            ],
            'currency' => [
                'type' => 'select',
                'label' => 'Currency',
                'description' => 'The currency for this account',
                'options' => [
                    'GBP' => 'British Pound (£)',
                    'EUR' => 'Euro (€)',
                    'USD' => 'US Dollar ($)',
                ],
                'default' => 'GBP',
                'required' => true,
            ],
            'interest_rate' => [
                'type' => 'number',
                'label' => 'Interest Rate (%)',
                'description' => 'Annual interest rate (optional)',
                'required' => false,
                'step' => '0.01',
                'min' => '0',
                'max' => '100',
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
                    'account_id' => [
                        'type' => 'select',
                        'label' => 'Account',
                        'description' => 'Select the account to update',
                        'options' => [], // Will be populated dynamically
                        'required' => true,
                    ],
                    'balance' => [
                        'type' => 'number',
                        'label' => 'Balance',
                        'description' => 'Current account balance',
                        'required' => true,
                        'step' => '0.01',
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

    public function fetchData(Integration $integration): void
    {
        // Manual integrations don't fetch data automatically
        // Data is added by the user through the UI
    }

    public function convertData(array $externalData, Integration $integration): array
    {
        // Manual integrations don't convert external data
        return [];
    }

    public function createAccount(Integration $integration, array $accountData): EventObject
    {
        $account = EventObject::create([
            'integration_id' => $integration->id,
            'user_id' => $integration->user_id,
            'concept' => 'financial_account',
            'type' => 'account',
            'title' => $accountData['provider_name'] . ' - ' . ucfirst($accountData['account_type']),
            'content' => json_encode([
                'account_type' => $accountData['account_type'],
                'provider_name' => $accountData['provider_name'],
                'account_number' => $accountData['account_number'] ?? null,
                'currency' => $accountData['currency'],
                'interest_rate' => $accountData['interest_rate'] ?? null,
            ]),
            'metadata' => [
                'account_type' => $accountData['account_type'],
                'provider_name' => $accountData['provider_name'],
                'account_number' => $accountData['account_number'] ?? null,
                'currency' => $accountData['currency'],
                'interest_rate' => $accountData['interest_rate'] ?? null,
                'created_at' => now()->toISOString(),
            ],
            'time' => now(),
        ]);

        return $account;
    }

    public function createBalanceUpdate(Integration $integration, array $balanceData): Event
    {
        $account = EventObject::find($balanceData['account_id']);
        if (! $account) {
            throw new InvalidArgumentException('Account not found');
        }

        $event = Event::create([
            'integration_id' => $integration->id,
            'actor_id' => $account->id,
            'service' => static::getIdentifier(),
            'domain' => 'finance',
            'action' => 'balance_update',
            'value' => $balanceData['balance'],
            'value_unit' => 'currency',
            'time' => $balanceData['date'],
            'event_metadata' => [
                'account_id' => $balanceData['account_id'],
                'notes' => $balanceData['notes'] ?? null,
                'currency' => $account->metadata['currency'] ?? 'GBP',
            ],
        ]);

        return $event;
    }

    public function getAccountsForUser(User $user): array
    {
        $accounts = EventObject::where('user_id', $user->id)
            ->where('concept', 'financial_account')
            ->where('type', 'account')
            ->get();

        return $accounts->map(function ($account) {
            return [
                'id' => $account->id,
                'title' => $account->title,
                'account_type' => $account->metadata['account_type'] ?? 'unknown',
                'provider_name' => $account->metadata['provider_name'] ?? 'Unknown',
                'currency' => $account->metadata['currency'] ?? 'GBP',
            ];
        })->toArray();
    }
}
