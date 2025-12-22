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
        return 'manual_account';
    }

    public static function getDisplayName(): string
    {
        return 'Manual Accounts';
    }

    public static function getDescription(): string
    {
        return 'Manually track your accounts and balances.';
    }

    public static function getConfigurationSchema(): array
    {
        return [
            'account_type' => [
                'type' => 'select',
                'label' => 'Account Type',
                'description' => 'The type of account',
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
            'is_negative_balance' => [
                'type' => 'boolean',
                'label' => 'Negative Balance Account',
                'description' => 'Enable for accounts where higher balances are worse (credit cards, loans, mortgages)',
                'required' => false,
                'default' => false,
            ],
        ];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'accounts' => [
                'label' => 'Accounts',
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

    public static function getIcon(): string
    {
        return 'fas.sterling-sign';
    }

    public static function getAccentColor(): string
    {
        return 'success';
    }

    public static function getDomain(): string
    {
        return 'money';
    }

    public static function getActionTypes(): array
    {
        return [
            'had_balance' => [
                'icon' => 'fas.sterling-sign',
                'display_name' => 'Balance Update',
                'description' => 'Account balance was updated',
                'display_with_object' => false,
                'value_unit' => 'GBP',
                'hidden' => true,
                'exclude_from_flint' => true,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [];
    }

    public static function getObjectTypes(): array
    {
        return [
            'manual_account' => [
                'icon' => 'fas.wallet',
                'display_name' => 'Manual Account',
                'description' => 'A manually entered financial account',
                'hidden' => false,
            ],
            'day' => [
                'icon' => 'fas.calendar-day',
                'display_name' => 'Day',
                'description' => 'A calendar day',
                'hidden' => false,
            ],
        ];
    }

    /**
     * Create or update a financial account object
     */
    public function upsertAccountObject(Integration $integration, array $accountData): EventObject
    {
        $title = $accountData['name'] ?? 'Manual Account';

        $metadata = [
            'name' => $accountData['name'],
            'account_type' => $accountData['account_type'],
            'provider' => $accountData['provider'],
            'account_number' => $accountData['account_number'] ?? null,
            'sort_code' => $accountData['sort_code'] ?? null,
            'currency' => $accountData['currency'] ?? 'GBP',
            'interest_rate' => $accountData['interest_rate'] ?? null,
            'start_date' => $accountData['start_date'] ?? null,
            'is_negative_balance' => $accountData['is_negative_balance'] ?? false,
            'integration_id' => $integration->id,
            'raw' => $accountData,
        ];

        // Use firstOrCreate to avoid updating 'time' on every call
        $accountObject = EventObject::firstOrCreate(
            [
                'user_id' => $integration->user_id,
                'concept' => 'account',
                'type' => 'manual_account',
                'title' => $title,
            ],
            [
                'time' => now(),
                'content' => null,
            ]
        );

        // Update metadata (account details like interest rates can change)
        $accountObject->update(['metadata' => $metadata]);

        return $accountObject;
    }

    /**
     * Create a balance update event
     */
    public function createBalanceEvent(Integration $integration, EventObject $accountObject, array $balanceData): Event
    {
        $date = $balanceData['date'] ?? now()->toDateString();
        $balance = (float) ($balanceData['balance'] ?? 0);

        // Create the target "day" object once
        $dayObject = EventObject::firstOrCreate(
            [
                'user_id' => $integration->user_id,
                'concept' => 'day',
                'type' => 'day',
                'title' => $date,
            ],
            [
                'time' => $date . ' 00:00:00',
                'content' => null,
                'metadata' => [],
            ]
        );

        // Convert decimal to whole number using multiplier
        $multiplier = 100; // Use 100 for 2 decimal places (cents/pence)
        $wholeValue = (int) (abs($balance) * $multiplier);

        return Event::create([
            'integration_id' => $integration->id,
            'source_id' => 'manual_balance_' . $accountObject->id . '_' . $date,
            'time' => $date . ' 23:59:59',
            'actor_id' => $accountObject->id,
            'service' => 'manual_account',
            'domain' => self::getDomain(),
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
     * Get all financial accounts for a user (excluding archived)
     */
    public function getFinancialAccounts(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return EventObject::where('user_id', $user->id)
            ->where('concept', 'account')
            ->whereIn('type', [
                'manual_account',      // Manual financial accounts
                'monzo_account',       // Monzo bank accounts
                'monzo_pot',           // Monzo pots
                'bank_account',        // GoCardless bank accounts
            ])
            ->orderBy('title')
            ->get()
            ->filter(function ($account) {
                // Exclude accounts marked as deleted/archived
                return ! ($account->metadata['deleted'] ?? false);
            })
            ->values();
    }

    /**
     * Get all financial accounts for a user (including archived)
     */
    public function getAllFinancialAccounts(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return EventObject::where('user_id', $user->id)
            ->where('concept', 'account')
            ->whereIn('type', [
                'manual_account',      // Manual financial accounts
                'monzo_account',       // Monzo bank accounts
                'monzo_pot',           // Monzo pots
                'monzo_archived_pot',  // Archived Monzo pots (for backwards compatibility)
                'bank_account',        // GoCardless bank accounts
            ])
            ->orderBy('title')
            ->get();
    }

    /**
     * Get only manual financial accounts for a user (for balance updates)
     */
    public function getManualFinancialAccounts(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return EventObject::where('user_id', $user->id)
            ->where('concept', 'account')
            ->where('type', 'manual_account')
            ->orderBy('title')
            ->get();
    }

    /**
     * Get archived Monzo pots for a user
     */
    public function getArchivedMonzoPots(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return EventObject::where('user_id', $user->id)
            ->where('concept', 'account')
            ->where('type', 'monzo_archived_pot')
            ->orderBy('title')
            ->get();
    }

    /**
     * Get balance events for a specific account
     */
    public function getBalanceEvents(EventObject $accountObject): \Illuminate\Database\Eloquent\Collection
    {
        return $this->getBalanceEventsQuery($accountObject)
            ->orderBy('time', 'desc')
            ->get();
    }

    /**
     * Get balance events query for a specific account (for pagination)
     */
    public function getBalanceEventsQuery(EventObject $accountObject): \Illuminate\Database\Eloquent\Builder
    {
        return Event::where('actor_id', $accountObject->id)
            ->whereIn('service', ['manual_account', 'monzo', 'gocardless'])
            ->where('action', 'had_balance');
    }

    /**
     * Get the latest balance for an account
     */
    public function getLatestBalance(EventObject $accountObject): ?Event
    {
        return Event::where('actor_id', $accountObject->id)
            ->whereIn('service', ['manual_account', 'monzo', 'gocardless'])
            ->where('action', 'had_balance')
            ->latest('time')
            ->first();
    }

    /**
     * Batch load latest balances for multiple accounts (N+1 optimization)
     *
     * @param  \Illuminate\Support\Collection  $accounts  Collection of EventObject accounts
     * @return \Illuminate\Support\Collection Keyed by actor_id
     */
    public function getLatestBalancesForAccounts($accounts): \Illuminate\Support\Collection
    {
        $accountIds = $accounts->pluck('id')->toArray();

        if (empty($accountIds)) {
            return collect();
        }

        // Get the actual table name with prefix (respects test prefixes)
        $model = new Event;
        $prefix = $model->getConnection()->getTablePrefix();
        $table = $prefix . $model->getTable();

        // Use PostgreSQL DISTINCT ON for efficient "latest per group" query
        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));

        $results = Event::fromRaw("(
            SELECT DISTINCT ON (actor_id) *
            FROM {$table}
            WHERE actor_id IN ({$placeholders})
            AND service IN ('manual_account', 'monzo', 'gocardless')
            AND action = 'had_balance'
            AND deleted_at IS NULL
            ORDER BY actor_id, time DESC
        ) as {$table}", $accountIds)->get();

        return $results->keyBy('actor_id');
    }
}
