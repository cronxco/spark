<?php

namespace App\Integrations\Financial;

use App\Integrations\Base\ManualPlugin;

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
}