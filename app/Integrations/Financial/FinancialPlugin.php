<?php

namespace App\Integrations\Financial;

use App\Integrations\Base\ManualPlugin;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Log;

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
        // Get manual financial accounts
        $manualAccounts = EventObject::where('user_id', $user->id)
            ->where('concept', 'account')
            ->where('type', 'financial_account')
            ->orderBy('title')
            ->get();

        // Get Monzo accounts
        $monzoAccounts = $this->getMonzoAccounts($user);

        // Get GoCardless accounts (if available)
        $gocardlessAccounts = $this->getGoCardlessAccounts($user);

        // Merge all accounts and sort by title
        return $manualAccounts
            ->concat($monzoAccounts)
            ->concat($gocardlessAccounts)
            ->sortBy('title');
    }

    /**
     * Update account metadata for any account type
     */
    public function updateAccountMetadata(EventObject $accountObject, array $metadata): bool
    {
        try {
            $currentMetadata = $accountObject->metadata;
            $updatedMetadata = array_merge($currentMetadata, $metadata);

            $accountObject->update([
                'metadata' => $updatedMetadata,
            ]);

            return true;
        } catch (Exception $e) {
            Log::error('Failed to update account metadata', [
                'account_id' => $accountObject->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get editable metadata fields for an account
     */
    public function getEditableMetadataFields(EventObject $accountObject): array
    {
        $service = $accountObject->metadata['service'] ?? 'financial';

        // Base fields that can be edited for any account
        $editableFields = [
            'sort_code' => [
                'type' => 'text',
                'label' => 'Sort Code',
                'description' => 'Sort code for UK bank accounts',
                'required' => false,
            ],
            'interest_rate' => [
                'type' => 'number',
                'label' => 'Interest Rate (%)',
                'description' => 'Annual interest rate',
                'required' => false,
                'step' => 0.01,
                'min' => 0,
                'max' => 100,
            ],
            'start_date' => [
                'type' => 'date',
                'label' => 'Start Date',
                'description' => 'When you opened this account',
                'required' => false,
            ],
        ];

        // Add service-specific fields
        if ($service === 'financial') {
            $editableFields = array_merge($editableFields, [
                'account_number' => [
                    'type' => 'text',
                    'label' => 'Account Number',
                    'description' => 'Account number or identifier',
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
                    'required' => false,
                ],
            ]);
        }

        return $editableFields;
    }

    /**
     * Get balance events for a specific account
     */
    public function getBalanceEvents(EventObject $accountObject): \Illuminate\Database\Eloquent\Collection
    {
        $service = $accountObject->metadata['service'] ?? 'financial';

        return Event::where('actor_id', $accountObject->id)
            ->where('service', $service)
            ->where('action', 'had_balance')
            ->orderBy('time', 'desc')
            ->get();
    }

    /**
     * Get the latest balance for an account
     */
    public function getLatestBalance(EventObject $accountObject): ?Event
    {
        $service = $accountObject->metadata['service'] ?? 'financial';

        return Event::where('actor_id', $accountObject->id)
            ->where('service', $service)
            ->where('action', 'had_balance')
            ->latest('time')
            ->first();
    }

    /**
     * Get Monzo accounts for a user
     */
    protected function getMonzoAccounts(User $user): \Illuminate\Database\Eloquent\Collection
    {
        $monzoIntegrations = Integration::where('user_id', $user->id)
            ->where('service', 'monzo')
            ->get();

        $accounts = collect();

        foreach ($monzoIntegrations as $integration) {
            try {
                // Get existing Monzo account objects
                $monzoAccountObjects = EventObject::where('integration_id', $integration->id)
                    ->where('concept', 'account')
                    ->where('type', 'monzo_account')
                    ->get();

                foreach ($monzoAccountObjects as $accountObject) {
                    // Add Monzo-specific metadata
                    $accountObject->metadata = array_merge($accountObject->metadata, [
                        'service' => 'monzo',
                        'account_type' => $this->mapMonzoAccountType($accountObject->metadata['monzo_account_type'] ?? ''),
                        'provider' => 'Monzo',
                        'account_number' => $accountObject->metadata['monzo_account_number'] ?? null,
                        'sort_code' => $accountObject->metadata['monzo_sort_code'] ?? null,
                        'currency' => $accountObject->metadata['monzo_currency'] ?? 'GBP',
                        'interest_rate' => null, // Monzo doesn't provide interest rates via API
                        'start_date' => $accountObject->metadata['monzo_created'] ?? null,
                    ]);

                    $accounts->push($accountObject);
                }
            } catch (Exception $e) {
                // Log error but continue with other integrations
                Log::warning('Failed to fetch Monzo accounts for integration ' . $integration->id, [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $accounts;
    }

    /**
     * Get GoCardless accounts for a user
     */
    protected function getGoCardlessAccounts(User $user): \Illuminate\Database\Eloquent\Collection
    {
        $gocardlessIntegrations = Integration::where('user_id', $user->id)
            ->where('service', 'gocardless')
            ->get();

        $accounts = collect();

        foreach ($gocardlessIntegrations as $integration) {
            try {
                // Get existing GoCardless account objects
                $gocardlessAccountObjects = EventObject::where('integration_id', $integration->id)
                    ->where('concept', 'account')
                    ->where('type', 'gocardless_account')
                    ->get();

                foreach ($gocardlessAccountObjects as $accountObject) {
                    // Add GoCardless-specific metadata
                    $accountObject->metadata = array_merge($accountObject->metadata, [
                        'service' => 'gocardless',
                        'account_type' => $this->mapGoCardlessAccountType($accountObject->metadata['gocardless_account_type'] ?? ''),
                        'provider' => $accountObject->metadata['gocardless_institution_name'] ?? 'GoCardless Bank',
                        'account_number' => $accountObject->metadata['gocardless_account_number'] ?? null,
                        'sort_code' => $accountObject->metadata['gocardless_sort_code'] ?? null,
                        'currency' => $accountObject->metadata['gocardless_currency'] ?? 'GBP',
                        'interest_rate' => null, // GoCardless doesn't provide interest rates
                        'start_date' => $accountObject->metadata['gocardless_created'] ?? null,
                    ]);

                    $accounts->push($accountObject);
                }
            } catch (Exception $e) {
                // Log error but continue with other integrations
                Log::warning('Failed to fetch GoCardless accounts for integration ' . $integration->id, [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $accounts;
    }

    /**
     * Map Monzo account types to our standard account types
     */
    protected function mapMonzoAccountType(string $monzoType): string
    {
        return match ($monzoType) {
            'uk_retail' => 'current_account',
            'uk_retail_joint' => 'current_account',
            'uk_monzo_flex' => 'loan',
            default => 'other',
        };
    }

    /**
     * Map GoCardless account types to our standard account types
     */
    protected function mapGoCardlessAccountType(string $gocardlessType): string
    {
        return match ($gocardlessType) {
            'current' => 'current_account',
            'savings' => 'savings_account',
            'credit' => 'credit_card',
            default => 'other',
        };
    }
}
