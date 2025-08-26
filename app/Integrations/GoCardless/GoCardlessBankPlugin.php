<?php

namespace App\Integrations\GoCardless;

use App\Integrations\Base\OAuthPlugin;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class GoCardlessBankPlugin extends OAuthPlugin
{
    /**
     * GoCardless Bank Account Data API integration
     * Uses direct HTTP calls instead of the unmaintained Nordigen package
     */
    protected string $apiBase = 'https://bankaccountdata.gocardless.com/api/v2';

    protected string $tokenEndpoint = 'https://bankaccountdata.gocardless.com/api/v2/token/new/';

    protected string $secretId;

    protected string $secretKey;

    protected string $redirectUri;

    protected string $countryCode;

    protected ?string $accessToken = null;

    public function __construct()
    {
        $this->secretId = (string) (config('services.gocardless.secret_id') ?? '');
        $this->secretKey = (string) (config('services.gocardless.secret_key') ?? '');
        $this->redirectUri = (string) (config('services.gocardless.redirect')
            ?? route('integrations.oauth.callback', ['service' => self::getIdentifier()]));
        $this->countryCode = (string) (config('services.gocardless.country', 'GB'));

        if (! app()->environment('testing') && (empty($this->secretId) || empty($this->secretKey))) {
            throw new InvalidArgumentException('GoCardless credentials are not configured');
        }
    }

    public static function getIdentifier(): string
    {
        return 'gocardless';
    }

    public static function getDisplayName(): string
    {
        return 'GoCardless Bank';
    }

    public static function getDescription(): string
    {
        return 'Connect bank accounts via GoCardless Bank Account Data API to ingest balances and transactions.';
    }

    public static function getConfigurationSchema(): array
    {
        return [
            'update_frequency_minutes' => [
                'type' => 'integer',
                'label' => 'Update Frequency (minutes)',
                'required' => true,
                'min' => 1440, // 24 hours minimum due to GoCardless rate limits
                'default' => 1440, // 24 hours default
                'description' => 'GoCardless has strict rate limits (4 requests/day). Recommended: 24+ hours.',
            ],
        ];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'transactions' => [
                'label' => 'Transactions',
                'schema' => self::getConfigurationSchema(),
            ],
            'balances' => [
                'label' => 'Balances',
                'schema' => self::getConfigurationSchema(),
            ],
            'accounts' => [
                'label' => 'Accounts (master)',
                'schema' => [],
            ],
        ];
    }

    /**
     * Get OAuth URL for GoCardless Bank Account Data API
     */
    public function getOAuthUrl(IntegrationGroup $group): string
    {
        Log::info('GoCardless getOAuthUrl called', [
            'group_id' => $group->id,
            'user_id' => $group->user_id,
        ]);

        // Get the selected institution from session
        $institutionId = (string) (Session::get('gocardless_institution_id_' . $group->id)
            ?? config('services.gocardless.institution_id', ''));

        Log::info('GoCardless institution ID from session', [
            'group_id' => $group->id,
            'institution_id' => $institutionId,
            'session_key' => 'gocardless_institution_id_' . $group->id,
        ]);

        if (empty($institutionId)) {
            throw new RuntimeException('No institution selected for GoCardless integration');
        }

        try {
            // According to GoCardless Quickstart Guide:
            // Step 3: Create End User Agreement (required for proper flow)
            // Step 4: Create Requisition (required - this gives us the authorization link)

            Log::info('Creating GoCardless end-user agreement (Step 3)', [
                'group_id' => $group->id,
                'institution_id' => $institutionId,
            ]);

            // Create agreement first (Step 3), then requisition (Step 4)
            $agreement = $this->createEndUserAgreement($group, $institutionId);
            $requisition = $this->createRequisition($institutionId, $agreement['id']);

            Log::info('GoCardless requisition created (Step 4)', [
                'group_id' => $group->id,
                'requisition_id' => $requisition['id'] ?? null,
                'requisition_link' => $requisition['link'] ?? null,
                'full_response' => $requisition,
            ]);

            // Store the reference in auth_metadata so we can look it up in the callback
            $reference = $requisition['reference'] ?? null;
            $requisitionId = $requisition['id'] ?? null;

            if ($reference && $requisitionId) {
                // Store both the reference (for callback lookup) and requisition ID
                $group->update([
                    'account_id' => $requisitionId,
                    'auth_metadata' => array_merge($group->auth_metadata ?? [], [
                        'gocardless_reference' => $reference,
                        'gocardless_requisition_id' => $requisitionId,
                    ]),
                ]);

                Log::info('Stored GoCardless reference and requisition ID in group', [
                    'group_id' => $group->id,
                    'reference' => $reference,
                    'requisition_id' => $requisitionId,
                ]);
            }

            // Return the authorization link from the requisition response
            $link = (string) ($requisition['link'] ?? '');
            if ($link === '') {
                Log::error('GoCardless requisition missing link field', [
                    'group_id' => $group->id,
                    'requisition_response' => $requisition,
                    'response_keys' => array_keys($requisition),
                ]);
                throw new RuntimeException('Failed to get authorization link from GoCardless requisition response');
            }

            Log::info('GoCardless OAuth URL generated successfully', [
                'group_id' => $group->id,
                'oauth_url' => $link,
            ]);

            return $link;

        } catch (Throwable $e) {
            Log::error('Failed to create GoCardless OAuth URL', [
                'group_id' => $group->id,
                'institution_id' => $institutionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle OAuth callback from GoCardless
     */
    public function handleOAuthCallback(\Illuminate\Http\Request $request, IntegrationGroup $group): void
    {
        // GoCardless redirects back with ?ref={reference}, but we need the actual requisition ID
        $reference = $request->get('ref');

        if (! $reference) {
            throw new RuntimeException('Missing GoCardless reference parameter');
        }

        // Get the actual requisition ID from the group's auth_metadata
        $requisitionId = $group->auth_metadata['gocardless_requisition_id'] ?? null;

        if (! $requisitionId) {
            throw new RuntimeException('No requisition ID found in group metadata');
        }

        try {
            // Verify the requisition status using the actual requisition ID
            $requisition = $this->getRequisition($requisitionId);

            if (($requisition['status'] ?? '') !== 'LN') {
                throw new RuntimeException('Requisition not linked: ' . ($requisition['status'] ?? 'unknown'));
            }

            // Update group with the confirmed requisition id
            $group->update([
                'account_id' => $requisitionId,
                // Store a non-null token surrogate so scheduler includes this group
                'access_token' => 'requisition:' . $requisitionId,
            ]);

            Log::info('GoCardless requisition successfully linked', [
                'group_id' => $group->id,
                'requisition_id' => $requisitionId,
                'reference' => $reference,
                'status' => $requisition['status'],
            ]);
        } catch (Throwable $e) {
            Log::error('GoCardless OAuth callback failed', [
                'group_id' => $group->id,
                'requisition_id' => $requisitionId,
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function fetchData(Integration $integration): void
    {
        $instanceType = $integration->instance_type ?: 'transactions';

        Log::info('GoCardless fetchData called', [
            'integration_id' => $integration->id,
            'instance_type' => $instanceType,
            'group_id' => $integration->group_id,
        ]);

        if ($instanceType === 'accounts') {
            Log::info('GoCardless fetchData: skipping accounts instance type', [
                'integration_id' => $integration->id,
            ]);

            return;
        }

        $group = $integration->group;
        if (! $group || empty($group->account_id)) {
            Log::warning('GoCardless fetchData: missing group or account_id', [
                'integration_id' => $integration->id,
                'group_id' => $group?->id,
                'account_id' => $group?->account_id,
            ]);

            return;
        }

        Log::info('GoCardless fetchData: processing data', [
            'integration_id' => $integration->id,
            'instance_type' => $instanceType,
            'group_id' => $group->id,
            'account_id' => $group->account_id,
        ]);

        try {
            // First, try to update integration names if account details are now available
            $this->updateIntegrationNames($group);

            if ($instanceType === 'balances') {
                Log::info('GoCardless fetchData: processing balance snapshot', [
                    'integration_id' => $integration->id,
                ]);
                $this->processBalanceSnapshot($integration);
            } elseif ($instanceType === 'transactions') {
                Log::info('GoCardless fetchData: processing recent transactions', [
                    'integration_id' => $integration->id,
                ]);
                $this->processRecentTransactions($integration);
            }

            Log::info('GoCardless fetchData: completed successfully', [
                'integration_id' => $integration->id,
                'instance_type' => $instanceType,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to fetch GoCardless data', [
                'integration_id' => $integration->id,
                'instance_type' => $instanceType,
                'group_id' => $group->id,
                'account_id' => $group->account_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function listAccounts(Integration $integration): array
    {
        Log::info('GoCardless listAccounts called', [
            'integration_id' => $integration->id,
            'group_id' => $integration->group_id,
        ]);

        $group = $integration->group;
        if (! $group || empty($group->account_id)) {
            Log::warning('GoCardless listAccounts: missing group or account_id', [
                'integration_id' => $integration->id,
                'group_id' => $group?->id,
                'account_id' => $group?->account_id,
            ]);

            return [];
        }

        Log::info('GoCardless listAccounts: getting requisition', [
            'integration_id' => $integration->id,
            'group_id' => $group->id,
            'account_id' => $group->account_id,
        ]);

        try {
            $requisition = $this->getRequisition($group->account_id);
            $accountIds = $requisition['accounts'] ?? [];

            Log::info('GoCardless listAccounts: found account IDs', [
                'integration_id' => $integration->id,
                'requisition_id' => $group->account_id,
                'account_ids' => $accountIds,
                'account_count' => count($accountIds),
            ]);

            $accounts = [];
            foreach ($accountIds as $accountId) {
                Log::info('GoCardless listAccounts: getting account details', [
                    'integration_id' => $integration->id,
                    'account_id' => $accountId,
                ]);

                $accountDetails = $this->getAccount($accountId);
                if ($accountDetails) {
                    Log::info('GoCardless listAccounts: account details retrieved', [
                        'integration_id' => $integration->id,
                        'account_id' => $accountId,
                        'account_name' => $accountDetails['details'] ?? $accountDetails['ownerName'] ?? 'Unknown',
                    ]);
                    $accounts[] = $accountDetails;
                } else {
                    Log::warning('GoCardless listAccounts: failed to get account details', [
                        'integration_id' => $integration->id,
                        'account_id' => $accountId,
                    ]);
                }
            }

            Log::info('GoCardless listAccounts: returning accounts', [
                'integration_id' => $integration->id,
                'account_count' => count($accounts),
            ]);

            return $accounts;
        } catch (Throwable $e) {
            Log::error('Failed to list GoCardless accounts', [
                'integration_id' => $integration->id,
                'group_id' => $group->id,
                'account_id' => $group->account_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Update integration names when account details become available
     */
    public function updateIntegrationNames(IntegrationGroup $group): void
    {
        Log::info('GoCardless updateIntegrationNames: starting', [
            'group_id' => $group->id,
        ]);

        try {
            $requisition = $this->getRequisition($group->account_id);
            $accountIds = $requisition['accounts'] ?? [];

            if (empty($accountIds)) {
                Log::warning('GoCardless updateIntegrationNames: no accounts found', [
                    'group_id' => $group->id,
                ]);

                return;
            }

            $accounts = [];
            foreach ($accountIds as $accountId) {
                $accountDetails = $this->getAccount($accountId);
                if ($accountDetails) {
                    $accounts[] = $accountDetails;
                }
            }

            // Update integration names for each account
            foreach ($accounts as $account) {
                $this->updateIntegrationNamesForAccount($group, $account);
            }

            Log::info('GoCardless updateIntegrationNames: completed', [
                'group_id' => $group->id,
                'account_count' => count($accounts),
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to update GoCardless integration names', [
                'group_id' => $group->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Public method to manually trigger integration name updates
     * Useful for testing or manual updates after rate limits reset
     */
    public function refreshIntegrationNames(IntegrationGroup $group): array
    {
        Log::info('GoCardless refreshIntegrationNames: manually triggered', [
            'group_id' => $group->id,
        ]);

        try {
            $this->updateIntegrationNames($group);

            // Return summary of what was updated
            $integrations = $group->integrations()->get();
            $updatedCount = 0;

            foreach ($integrations as $integration) {
                if (isset($integration->config['account_id'])) {
                    $updatedCount++;
                }
            }

            return [
                'success' => true,
                'message' => "Updated names for {$updatedCount} integrations",
                'updated_count' => $updatedCount,
            ];
        } catch (Throwable $e) {
            Log::error('Failed to refresh GoCardless integration names', [
                'group_id' => $group->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to update names: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get available accounts for onboarding (used before instances are created)
     */
    public function getAvailableAccountsForOnboarding(IntegrationGroup $group): array
    {
        if (empty($group->account_id)) {
            return [];
        }

        try {
            $requisition = $this->getRequisition($group->account_id);
            $accountIds = $requisition['accounts'] ?? [];

            Log::info('GoCardless onboarding: found account IDs', [
                'group_id' => $group->id,
                'account_ids' => $accountIds,
            ]);

            $accounts = [];
            foreach ($accountIds as $accountId) {
                $accountDetails = $this->getAccount($accountId);
                if ($accountDetails) {
                    Log::info('GoCardless onboarding: account details', [
                        'account_id' => $accountId,
                        'account_data' => $accountDetails,
                    ]);
                    $accounts[] = $accountDetails;
                } else {
                    Log::warning('GoCardless onboarding: failed to get account details', [
                        'account_id' => $accountId,
                        'group_id' => $group->id,
                    ]);
                }
            }

            Log::info('GoCardless onboarding: returning accounts', [
                'group_id' => $group->id,
                'account_count' => count($accounts),
                'accounts' => $accounts,
            ]);

            return $accounts;
        } catch (Throwable $e) {
            Log::error('Failed to get available accounts for onboarding', [
                'group_id' => $group->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get available institutions for a country
     */
    public function getInstitutions(?string $country = null): array
    {
        $country = $country ?: $this->countryCode;

        try {
            Log::info('Attempting to get GoCardless institutions', [
                'country' => $country,
                'api_base' => $this->apiBase,
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
            ])->get($this->apiBase . '/institutions/', [
                'country' => $country,
            ]);

            Log::info('GoCardless institutions API response', [
                'status' => $response->status(),
                'headers' => $response->headers(),
                'body' => $response->body(),
                'country' => $country,
            ]);

            if (! $response->successful()) {
                Log::warning('Failed to get institutions', [
                    'country' => $country,
                    'response' => $response->body(),
                    'status' => $response->status(),
                ]);

                return [];
            }

            $data = $response->json();
            Log::info('GoCardless institutions response data', [
                'data_keys' => array_keys($data),
                'data_structure' => $data,
            ]);

            // The API returns institutions directly as an array, not wrapped in 'institutions' key
            $institutions = is_array($data) ? $data : [];
            Log::info('Extracted institutions', [
                'count' => count($institutions),
                'first_few' => array_slice($institutions, 0, 3),
            ]);

            return $institutions;
        } catch (Throwable $e) {
            Log::error('Failed to get institutions', [
                'country' => $country,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Required method from IntegrationPlugin interface
     */
    public function convertData(array $externalData, Integration $integration): array
    {
        // This plugin processes data directly, no conversion needed
        return $externalData;
    }

    /**
     * Migration support methods
     */
    public function fetchWindowWithMeta(string $instanceType, ?string $lastDate = null): array
    {
        // Implementation for migration jobs
        return [];
    }

    public function processGoCardlessMigrationItems(Integration $integration, array $items): void
    {
        // Implementation for migration processing
    }

    protected function getRequiredScopes(): string
    {
        // Not applicable for GoCardless Bank Account Data API
        return '';
    }

    protected function fetchAccountInfoForGroup(IntegrationGroup $group): void
    {
        // Nothing to pre-fetch here; accounts are tied to requisitions
    }

    /**
     * Update integration names for a specific account
     */
    protected function updateIntegrationNamesForAccount(IntegrationGroup $group, array $account): void
    {
        $accountId = $account['id'];

        // Find integrations for this account
        $integrations = $group->integrations()
            ->where('config->account_id', $accountId)
            ->get();

        foreach ($integrations as $integration) {
            $currentName = $integration->name;
            $newName = $this->generateAccountName($account);

            if ($currentName !== $newName) {
                Log::info('GoCardless updateIntegrationNamesForAccount: updating name', [
                    'integration_id' => $integration->id,
                    'account_id' => $accountId,
                    'old_name' => $currentName,
                    'new_name' => $newName,
                ]);

                $integration->update(['name' => $newName]);
            }
        }
    }

    /**
     * Generate a descriptive name for an account
     */
    protected function generateAccountName(array $account): string
    {
        if (isset($account['details']) && ! empty($account['details'])) {
            return $account['details'];
        } elseif (isset($account['ownerName'])) {
            return $account['ownerName'] . "'s Account";
        } else {
            return 'Account ' . substr($account['resourceId'] ?? $account['id'] ?? 'Unknown', 0, 8);
        }
    }

    /**
     * Process balance snapshot for an integration
     */
    protected function processBalanceSnapshot(Integration $integration): void
    {
        Log::info('GoCardless processBalanceSnapshot called', [
            'integration_id' => $integration->id,
        ]);

        $accounts = $this->listAccounts($integration);

        Log::info('GoCardless processBalanceSnapshot: got accounts', [
            'integration_id' => $integration->id,
            'account_count' => count($accounts),
        ]);

        foreach ($accounts as $account) {
            Log::info('GoCardless processBalanceSnapshot: processing account', [
                'integration_id' => $integration->id,
                'account_id' => $account['id'],
                'account_name' => $account['details'] ?? $account['ownerName'] ?? 'Unknown',
            ]);

            $balances = $this->getAccountBalances($account['id']);

            Log::info('GoCardless processBalanceSnapshot: got balances', [
                'integration_id' => $integration->id,
                'account_id' => $account['id'],
                'balance_count' => count($balances),
                'balances' => $balances,
            ]);

            foreach ($balances as $balance) {
                Log::info('GoCardless processBalanceSnapshot: processing balance', [
                    'integration_id' => $integration->id,
                    'account_id' => $account['id'],
                    'balance_type' => $balance['balanceType'] ?? 'unknown',
                    'balance_amount' => $balance['balanceAmount']['amount'] ?? 'unknown',
                    'balance_currency' => $balance['balanceAmount']['currency'] ?? 'unknown',
                    'reference_date' => $balance['referenceDate'] ?? 'unknown',
                ]);

                // According to GoCardless docs, prefer these balance types in order:
                // 1. interimBooked - most current booked balance
                // 2. closingBooked - end of day balance
                // 3. expected - projected end of day balance
                $preferredTypes = ['interimBooked', 'closingBooked', 'expected'];

                if (in_array(($balance['balanceType'] ?? ''), $preferredTypes)) {
                    Log::info('GoCardless processBalanceSnapshot: creating balance event', [
                        'integration_id' => $integration->id,
                        'account_id' => $account['id'],
                        'balance_type' => $balance['balanceType'],
                        'balance_amount' => $balance['balanceAmount']['amount'] ?? 'unknown',
                        'balance_currency' => $balance['balanceAmount']['currency'] ?? 'unknown',
                    ]);

                    $this->createBalanceEvent($integration, $account, $balance);
                    break; // Use the first preferred balance type
                }
            }
        }

        Log::info('GoCardless processBalanceSnapshot: completed', [
            'integration_id' => $integration->id,
        ]);
    }

    /**
     * Process recent transactions for an integration
     */
    protected function processRecentTransactions(Integration $integration): void
    {
        Log::info('GoCardless processRecentTransactions called', [
            'integration_id' => $integration->id,
        ]);

        $accounts = $this->listAccounts($integration);

        Log::info('GoCardless processRecentTransactions: got accounts', [
            'integration_id' => $integration->id,
            'account_count' => count($accounts),
        ]);

        foreach ($accounts as $account) {
            Log::info('GoCardless processRecentTransactions: processing account', [
                'integration_id' => $integration->id,
                'account_id' => $account['id'],
                'account_name' => $account['details'] ?? $account['ownerName'] ?? 'Unknown',
            ]);

            $transactions = $this->getAccountTransactions($account['id']);

            Log::info('GoCardless processRecentTransactions: got transactions', [
                'integration_id' => $integration->id,
                'account_id' => $account['id'],
                'transaction_count' => count($transactions),
            ]);

            foreach ($transactions as $transaction) {
                Log::info('GoCardless processRecentTransactions: processing transaction', [
                    'integration_id' => $integration->id,
                    'account_id' => $account['id'],
                    'transaction_id' => $transaction['transactionId'] ?? $transaction['internalTransactionId'] ?? 'unknown',
                    'amount' => $transaction['transactionAmount']['amount'] ?? 'unknown',
                    'currency' => $transaction['transactionAmount']['currency'] ?? 'unknown',
                    'description' => $transaction['remittanceInformationUnstructured'] ?? 'unknown',
                    'booking_date' => $transaction['bookingDate'] ?? 'unknown',
                    'debtor_name' => $transaction['debtorName'] ?? 'unknown',
                    'creditor_name' => $transaction['creditorName'] ?? 'unknown',
                ]);

                $this->processTransactionItem($integration, $account, $transaction);
            }
        }

        Log::info('GoCardless processRecentTransactions: completed', [
            'integration_id' => $integration->id,
        ]);
    }

    /**
     * Process a single transaction item
     */
    protected function processTransactionItem(Integration $integration, array $account, array $tx): void
    {
        Log::info('GoCardless processTransactionItem: processing transaction', [
            'integration_id' => $integration->id,
            'account_id' => $account['id'],
            'transaction_id' => $tx['transactionId'] ?? $tx['internalTransactionId'] ?? 'unknown',
            'amount' => $tx['transactionAmount']['amount'] ?? 'unknown',
            'currency' => $tx['transactionAmount']['currency'] ?? 'unknown',
            'description' => $tx['remittanceInformationUnstructured'] ?? 'unknown',
        ]);

        // Derive category from transaction code
        $category = 'other';
        if (isset($tx['bankTransactionCode'])) {
            $category = Str::slug($tx['bankTransactionCode']);
        } elseif (isset($tx['proprietaryBankTransactionCode'])) {
            $category = Str::slug($tx['proprietaryBankTransactionCode']);
        }

        Log::info('GoCardless processTransactionItem: derived category', [
            'integration_id' => $integration->id,
            'transaction_id' => $tx['transactionId'] ?? $tx['internalTransactionId'] ?? 'unknown',
            'category' => $category,
            'bank_transaction_code' => $tx['bankTransactionCode'] ?? null,
            'proprietary_bank_transaction_code' => $tx['proprietaryBankTransactionCode'] ?? null,
        ]);

        // Create or update the event
        $sourceId = (string) ($tx['transactionId'] ?? $tx['internalTransactionId'] ?? Str::uuid());
        $eventData = [
            'user_id' => $integration->user_id,
            'action' => 'made_transaction',
            'domain' => 'money',
            'service' => 'gocardless',
            'time' => $tx['bookingDate'] ?? now(),
            'value' => abs((float) ($tx['transactionAmount']['amount'] ?? 0)),
            'value_unit' => $tx['transactionAmount']['currency'] ?? 'EUR',
            'event_metadata' => [
                'category' => $category,
                'description' => $tx['remittanceInformationUnstructured'] ?? '',
                'bank_transaction_code' => $tx['bankTransactionCode'] ?? null,
                'proprietary_bank_transaction_code' => $tx['proprietaryBankTransactionCode'] ?? null,
                'booking_date' => $tx['bookingDate'] ?? null,
                'value_date' => $tx['valueDate'] ?? null,
                'check_id' => $tx['checkId'] ?? null,
                'creditor_id' => $tx['creditorId'] ?? null,
                'mandate_id' => $tx['mandateId'] ?? null,
                'creditor_account' => $tx['creditorAccount'] ?? null,
                'debtor_account' => $tx['debtorAccount'] ?? null,
            ],
        ];

        Log::info('GoCardless processTransactionItem: creating event', [
            'integration_id' => $integration->id,
            'source_id' => $sourceId,
            'event_data' => $eventData,
        ]);

        $event = Event::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'source_id' => $sourceId,
            ],
            $eventData
        );

        Log::info('GoCardless processTransactionItem: event created/updated', [
            'integration_id' => $integration->id,
            'event_id' => $event->id,
            'source_id' => $sourceId,
        ]);

        // Create or update actor (bank account)
        $actorObject = $this->upsertAccountObject($integration, $account);

        // Create or update target (counterparty)
        $targetObject = $this->upsertCounterpartyObject($integration, $tx);

        // Create the event relationship
        $event->objects()->sync([
            $actorObject->id => ['role' => 'actor'],
            $targetObject->id => ['role' => 'target'],
        ]);

        // Add relevant tags
        $event->syncTags([
            'money',
            'transaction',
            'bank',
            'gocardless',
            $category,
        ]);

        // Create balance block if this affects account balance
        if (isset($tx['transactionAmount']['amount'])) {
            $this->createBalanceBlock($integration, $account, $tx);
        }
    }

    /**
     * Create balance event
     */
    protected function createBalanceEvent(Integration $integration, array $account, array $balance): void
    {
        $sourceId = 'balance_' . $account['id'] . '_' . ($balance['referenceDate'] ?? now()->toDateString());
        $eventData = [
            'user_id' => $integration->user_id,
            'action' => 'had_balance',
            'domain' => 'money',
            'service' => 'gocardless',
            'time' => $balance['referenceDate'] ?? now(),
            'value' => abs((float) ($balance['balanceAmount']['amount'] ?? 0)),
            'value_unit' => $balance['balanceAmount']['currency'] ?? 'EUR',
            'event_metadata' => [
                'balance_type' => $balance['balanceType'] ?? null,
                'reference_date' => $balance['referenceDate'] ?? null,
                'account_id' => $account['id'],
            ],
        ];

        Log::info('GoCardless createBalanceEvent: creating balance event', [
            'integration_id' => $integration->id,
            'account_id' => $account['id'],
            'source_id' => $sourceId,
            'balance_type' => $balance['balanceType'] ?? 'unknown',
            'balance_amount' => $balance['balanceAmount']['amount'] ?? 'unknown',
            'balance_currency' => $balance['balanceAmount']['currency'] ?? 'unknown',
            'event_data' => $eventData,
        ]);

        $event = Event::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'source_id' => $sourceId,
            ],
            $eventData
        );

        Log::info('GoCardless createBalanceEvent: balance event created/updated', [
            'integration_id' => $integration->id,
            'event_id' => $event->id,
            'source_id' => $sourceId,
        ]);

        // Create or update actor (bank account)
        $actorObject = $this->upsertAccountObject($integration, $account);

        // Create day target
        $dayObject = EventObject::updateOrCreate(
            [
                'user_id' => $integration->user_id,
                'concept' => 'day',
                'type' => 'day',
                'title' => now()->toDateString(),
            ],
            [
                'integration_id' => $integration->id,
                'time' => now()->toDateString() . ' 00:00:00',
                'content' => null,
                'url' => null,
                'image_url' => null,
            ]
        );

        // Create the event relationship
        $event->objects()->sync([
            $actorObject->id => ['role' => 'actor'],
            $dayObject->id => ['role' => 'target'],
        ]);

        // Add relevant tags
        $event->syncTags([
            'money',
            'balance',
            'bank',
            'gocardless',
        ]);

        // Create balance block
        $this->createBalanceBlock($integration, $account, $balance);
    }

    /**
     * Create balance block
     */
    protected function createBalanceBlock(Integration $integration, array $account, array $data): void
    {
        $block = Block::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'source_id' => 'balance_' . $account['id'] . '_' . now()->toDateString(),
            ],
            [
                'user_id' => $integration->user_id,
                'concept' => 'money_bank_balance',
                'type' => 'balance_snapshot',
                'title' => 'Account Balance: ' . ($data['balanceAmount']['amount'] ?? 0) . ' ' . ($data['balanceAmount']['currency'] ?? 'EUR'),
                'content' => json_encode($data),
                'url' => null,
                'image_url' => null,
                'time' => $data['referenceDate'] ?? now(),
            ]
        );

        // Add relevant tags
        $block->syncTags([
            'money',
            'balance',
            'bank',
            'gocardless',
        ]);
    }

    /**
     * Upsert account object
     */
    protected function upsertAccountObject(Integration $integration, array $account): EventObject
    {
        return EventObject::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'concept' => 'money_bank_account',
                'type' => 'bank_account',
                'title' => $account['name'] ?? $account['id'],
            ],
            [
                'user_id' => $integration->user_id,
                'content' => json_encode($account),
                'url' => null,
                'image_url' => null,
                'time' => null,
            ]
        );
    }

    /**
     * Upsert counterparty object
     */
    protected function upsertCounterpartyObject(Integration $integration, array $tx): EventObject
    {
        $counterpartyName = $tx['creditorName'] ?? $tx['debtorName'] ?? 'Unknown';

        return EventObject::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'concept' => 'money_counterparty',
                'type' => 'transaction_counterparty',
                'title' => $counterpartyName,
            ],
            [
                'user_id' => $integration->user_id,
                'content' => json_encode([
                    'creditor_name' => $tx['creditorName'] ?? null,
                    'debtor_name' => $tx['debtorName'] ?? null,
                    'creditor_account' => $tx['creditorAccount'] ?? null,
                    'debtor_account' => $tx['debtorAccount'] ?? null,
                ]),
                'url' => null,
                'image_url' => null,
                'time' => null,
            ]
        );
    }

    /**
     * Create a requisition without an agreement (alternative approach)
     */
    protected function createRequisitionWithoutAgreement(string $institutionId): array
    {
        Log::info('Creating GoCardless requisition without agreement (Step 4)', [
            'institution_id' => $institutionId,
            'api_endpoint' => $this->apiBase . '/requisitions/',
        ]);

        // First, check if there are existing requisitions we can reuse
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ])->get($this->apiBase . '/requisitions/');

        Log::info('GoCardless existing requisitions API response (Step 4)', [
            'status' => $response->status(),
            'body' => $response->body(),
            'headers' => $response->headers(),
        ]);

        if ($response->successful()) {
            $data = $response->json();

            // Look for an existing requisition for this institution
            if (isset($data['results']) && is_array($data['results'])) {
                foreach ($data['results'] as $requisition) {
                    if (isset($requisition['institution_id']) && $requisition['institution_id'] === $institutionId) {
                        Log::info('Found existing GoCardless requisition for institution', [
                            'requisition_id' => $requisition['id'],
                            'institution_id' => $requisition['institution_id'],
                            'status' => $requisition['status'] ?? 'unknown',
                            'link' => $requisition['link'] ?? 'missing',
                        ]);

                        // Return the existing requisition
                        return $requisition;
                    }
                }
            }
        }

        // If no existing requisition found, create a new one
        Log::info('No existing requisition found, creating new GoCardless requisition (Step 4)', [
            'institution_id' => $institutionId,
            'api_endpoint' => $this->apiBase . '/requisitions/',
        ]);

        $requestData = [
            'institution_id' => $institutionId,
            'reference' => 'spark_integration_' . time(),
            'user_language' => 'EN',
            'redirect' => config('services.gocardless.redirect'), // URL where user will be redirected after authentication
            // Note: Not including agreement_id - using default terms
        ];

        Log::info('GoCardless requisition request data (without agreement)', [
            'request_data' => $requestData,
        ]);

        // Debug: Log the exact request details
        $requestUrl = $this->apiBase . '/requisitions/';
        $requestHeaders = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ];

        Log::info('GoCardless requisition POST request details', [
            'url' => $requestUrl,
            'method' => 'POST',
            'headers' => $requestHeaders,
            'body' => $requestData,
            'access_token_length' => strlen($this->getAccessToken()),
        ]);

        $response = Http::withHeaders($requestHeaders)->post($requestUrl, $requestData);

        Log::info('GoCardless new requisition API response (Step 4)', [
            'status' => $response->status(),
            'body' => $response->body(),
            'headers' => $response->headers(),
            'request_data' => $requestData,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to create requisition (Step 4): ' . $response->body());
        }

        $data = $response->json();

        // Check if the response has the expected structure
        if (! isset($data['id'])) {
            Log::error('GoCardless new requisition response missing ID', [
                'response_data' => $data,
                'response_keys' => array_keys($data),
                'response_type' => is_array($data) ? 'array' : gettype($data),
                'response_length' => is_array($data) ? count($data) : 'N/A',
            ]);
            throw new RuntimeException('Invalid response from GoCardless API: missing requisition ID (without agreement)');
        }

        Log::info('Successfully created GoCardless requisition (without agreement)', [
            'requisition_id' => $data['id'],
            'institution_id' => $data['institution_id'] ?? 'unknown',
            'status' => $data['status'] ?? 'unknown',
            'link' => $data['link'] ?? 'missing',
        ]);

        return $data;
    }

    /**
     * Create end-user agreement (Step 3 from GoCardless Quickstart Guide)
     */
    protected function createEndUserAgreement(IntegrationGroup $group, string $institutionId): array
    {
        Log::info('Creating new GoCardless end-user agreement (Step 3)', [
            'group_id' => $group->id,
            'institution_id' => $institutionId,
        ]);

        // Always create a new agreement instead of checking for existing ones
        Log::info('Creating new GoCardless agreement (Step 3)', [
            'group_id' => $group->id,
            'institution_id' => $institutionId,
            'api_endpoint' => $this->apiBase . '/agreements/enduser/',
        ]);

        $requestData = [
            'institution_id' => $institutionId,
            'max_historical_days' => 90,
            'access_valid_for_days' => 90,
            'access_scope' => ['balances', 'details', 'transactions'],
            'reconfirmation' => false,
        ];

        Log::info('GoCardless agreement request data', [
            'request_data' => $requestData,
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ])->post($this->apiBase . '/agreements/enduser/', $requestData);

        Log::info('GoCardless new agreement API response (Step 3)', [
            'status' => $response->status(),
            'body' => $response->body(),
            'headers' => $response->headers(),
            'request_data' => $requestData,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to create agreement (Step 3): ' . $response->body());
        }

        $data = $response->json();

        // Check if the response has the expected structure
        if (! isset($data['id'])) {
            Log::error('GoCardless new agreement response missing ID', [
                'response_data' => $data,
                'response_keys' => array_keys($data),
                'response_type' => is_array($data) ? 'array' : gettype($data),
                'response_length' => is_array($data) ? count($data) : 'N/A',
            ]);
            throw new RuntimeException('Invalid response from GoCardless API: missing agreement ID');
        }

        Log::info('Successfully created GoCardless agreement (Step 3)', [
            'agreement_id' => $data['id'],
            'institution_id' => $data['institution_id'] ?? 'unknown',
            'status' => $data['status'] ?? 'unknown',
        ]);

        return $data;
    }

    /**
     * Create requisition (Step 4 from GoCardless Quickstart Guide)
     */
    protected function createRequisition(string $institutionId, string $agreementId): array
    {
        Log::info('Creating GoCardless requisition (Step 4)', [
            'institution_id' => $institutionId,
            'agreement_id' => $agreementId,
            'api_endpoint' => $this->apiBase . '/requisitions/',
            'redirect_uri' => $this->redirectUri,
        ]);

        // First, try to get existing requisitions to see if we can reuse one
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ])->get($this->apiBase . '/requisitions/');

        Log::info('GoCardless existing requisitions API response (Step 4)', [
            'status' => $response->status(),
            'body' => $response->body(),
            'headers' => $response->headers(),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to check existing requisitions: ' . $response->body());
        }

        $data = $response->json();

        // Look for an existing requisition for this institution and agreement
        if (isset($data['results']) && is_array($data['results'])) {
            foreach ($data['results'] as $requisition) {
                if (isset($requisition['institution_id']) &&
                    $requisition['institution_id'] === $institutionId &&
                    isset($requisition['agreement']) &&
                    $requisition['agreement'] === $agreementId) {

                    Log::info('Found existing GoCardless requisition for institution and agreement', [
                        'requisition_id' => $requisition['id'],
                        'institution_id' => $requisition['institution_id'],
                        'agreement_id' => $requisition['agreement'],
                        'status' => $requisition['status'] ?? 'unknown',
                        'link' => $requisition['link'] ?? 'unknown',
                    ]);

                    // Return the existing requisition
                    return $requisition;
                }
            }
        }

        // If no existing requisition found, create a new one
        Log::info('No existing requisition found, creating new GoCardless requisition (Step 4)', [
            'institution_id' => $institutionId,
            'api_endpoint' => $this->apiBase . '/requisitions/',
        ]);

        $requestData = [
            'institution_id' => $institutionId,
            'reference' => 'integration_' . time(), // Unique reference as required
            'redirect' => $this->redirectUri, // URL where user will be redirected after authentication
            'agreement' => $agreementId, // End user agreement ID from Step 3
        ];

        Log::info('GoCardless requisition request data', [
            'request_data' => $requestData,
            'access_token_length' => strlen($this->getAccessToken()),
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ])->post($this->apiBase . '/requisitions/', $requestData);

        Log::info('GoCardless requisition API response (Step 4)', [
            'status' => $response->status(),
            'body' => $response->body(),
            'headers' => $response->headers(),
            'content_type' => $response->header('Content-Type'),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to create requisition (Step 4): ' . $response->body());
        }

        $data = $response->json();

        Log::info('GoCardless requisition response parsed JSON', [
            'parsed_data' => $data,
            'data_type' => is_array($data) ? 'array' : gettype($data),
            'data_keys' => is_array($data) ? array_keys($data) : 'N/A',
            'raw_body' => $response->body(),
        ]);

        // Check if the response has the expected structure
        if (! isset($data['id'])) {
            Log::error('GoCardless requisition response missing ID', [
                'response_data' => $data,
                'response_keys' => array_keys($data),
                'response_type' => is_array($data) ? 'array' : gettype($data),
                'response_length' => is_array($data) ? count($data) : 'N/A',
            ]);
            throw new RuntimeException('Invalid response from GoCardless API: missing requisition ID');
        }

        if (! isset($data['link'])) {
            Log::error('GoCardless requisition response missing link', [
                'response_data' => $data,
                'response_keys' => array_keys($data),
                'response_type' => is_array($data) ? 'array' : gettype($data),
                'response_length' => is_array($data) ? count($data) : 'N/A',
            ]);
            throw new RuntimeException('Invalid response from GoCardless API: missing requisition link');
        }

        Log::info('Successfully created GoCardless requisition (Step 4)', [
            'requisition_id' => $data['id'],
            'link' => $data['link'],
            'status' => $data['status'] ?? 'unknown',
        ]);

        return $data;
    }

    /**
     * Get requisition details
     */
    protected function getRequisition(string $requisitionId): array
    {
        Log::info('GoCardless getRequisition called', [
            'requisition_id' => $requisitionId,
            'api_endpoint' => $this->apiBase . '/requisitions/' . $requisitionId . '/',
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ])->get($this->apiBase . '/requisitions/' . $requisitionId . '/');

        Log::info('GoCardless getRequisition response', [
            'requisition_id' => $requisitionId,
            'status' => $response->status(),
            'response_body' => $response->body(),
            'response_headers' => $response->headers(),
        ]);

        if (! $response->successful()) {
            Log::error('Failed to get requisition', [
                'requisition_id' => $requisitionId,
                'status' => $response->status(),
                'response' => $response->body(),
                'api_endpoint' => $this->apiBase . '/requisitions/' . $requisitionId . '/',
            ]);
            throw new RuntimeException('Failed to get requisition: ' . $response->body());
        }

        $data = $response->json();

        Log::info('GoCardless getRequisition success', [
            'requisition_id' => $requisitionId,
            'requisition_data' => $data,
            'accounts' => $data['accounts'] ?? [],
            'account_count' => count($data['accounts'] ?? []),
        ]);

        return $data;
    }

    /**
     * Get account details
     */
    protected function getAccount(string $accountId): ?array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ])->get($this->apiBase . '/accounts/' . $accountId . '/details/');

        if (! $response->successful()) {
            $errorData = $response->json();
            $isRateLimited = $response->status() === 429;

            if ($isRateLimited) {
                Log::warning('GoCardless API rate limit exceeded for account details', [
                    'account_id' => $accountId,
                    'status' => $response->status(),
                    'rate_limit_detail' => $errorData['detail'] ?? 'unknown',
                    'api_endpoint' => $this->apiBase . '/accounts/' . $accountId . '/details/',
                ]);

                // Return a fallback account structure when rate limited
                return [
                    'id' => $accountId,
                    'details' => 'Account ' . substr($accountId, 0, 8),
                    'currency' => 'Unknown',
                    'cashAccountType' => 'Unknown',
                    'ownerName' => 'Unknown',
                    'status' => 'rate_limited',
                    'rate_limit_error' => $errorData['detail'] ?? 'Rate limit exceeded',
                ];
            } else {
                Log::error('Failed to get account details', [
                    'account_id' => $accountId,
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'api_endpoint' => $this->apiBase . '/accounts/' . $accountId . '/details/',
                ]);

                return null;
            }
        }

        $responseData = $response->json();
        // The API returns {"account": {...}}, so extract the account data
        $accountData = $responseData['account'] ?? $responseData;

        Log::info('GoCardless getAccount response', [
            'account_id' => $accountId,
            'account_data' => $accountData,
        ]);

        return $accountData;
    }

    /**
     * Get account balances
     */
    protected function getAccountBalances(string $accountId): array
    {
        Log::info('GoCardless getAccountBalances called', [
            'account_id' => $accountId,
            'api_endpoint' => $this->apiBase . '/accounts/' . $accountId . '/balances/',
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ])->get($this->apiBase . '/accounts/' . $accountId . '/balances/');

        Log::info('GoCardless getAccountBalances response', [
            'account_id' => $accountId,
            'status' => $response->status(),
            'response_body' => $response->body(),
            'response_headers' => $response->headers(),
        ]);

        if (! $response->successful()) {
            Log::error('Failed to get account balances', [
                'account_id' => $accountId,
                'status' => $response->status(),
                'response' => $response->body(),
                'api_endpoint' => $this->apiBase . '/accounts/' . $accountId . '/balances/',
            ]);

            return [];
        }

        $data = $response->json();

        // The API returns balances directly as an array
        $balances = $data;

        // Ensure it's an array
        if (! is_array($balances)) {
            Log::warning('GoCardless getAccountBalances: unexpected response format', [
                'account_id' => $accountId,
                'response_type' => gettype($balances),
                'response_data' => $balances,
            ]);
            $balances = [];
        }

        Log::info('GoCardless getAccountBalances success', [
            'account_id' => $accountId,
            'balance_count' => count($balances),
            'balances' => $balances,
        ]);

        return $balances;
    }

    /**
     * Get account transactions
     */
    protected function getAccountTransactions(string $accountId): array
    {
        Log::info('GoCardless getAccountTransactions called', [
            'account_id' => $accountId,
            'api_endpoint' => $this->apiBase . '/accounts/' . $accountId . '/transactions/',
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ])->get($this->apiBase . '/accounts/' . $accountId . '/transactions/');

        Log::info('GoCardless getAccountTransactions response', [
            'account_id' => $accountId,
            'status' => $response->status(),
            'response_body' => $response->body(),
            'response_headers' => $response->headers(),
        ]);

        if (! $response->successful()) {
            Log::error('Failed to get account transactions', [
                'account_id' => $accountId,
                'status' => $response->status(),
                'response' => $response->body(),
                'api_endpoint' => $this->apiBase . '/accounts/' . $accountId . '/transactions/',
            ]);

            return [];
        }

        $data = $response->json();

        // The API returns a nested structure with 'booked' and 'pending' arrays
        $bookedTransactions = $data['transactions']['booked'] ?? [];
        $pendingTransactions = $data['transactions']['pending'] ?? [];

        // Combine both types of transactions
        $transactions = array_merge($bookedTransactions, $pendingTransactions);

        Log::info('GoCardless getAccountTransactions success', [
            'account_id' => $accountId,
            'booked_count' => count($bookedTransactions),
            'pending_count' => count($pendingTransactions),
            'total_count' => count($transactions),
            'response_structure' => array_keys($data),
        ]);

        return $transactions;
    }

    /**
     * Get access token for API calls
     */
    protected function getAccessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        Log::info('Getting GoCardless access token', [
            'endpoint' => $this->tokenEndpoint,
            'secret_id_length' => strlen($this->secretId),
            'secret_key_length' => strlen($this->secretKey),
        ]);

        // Send credentials in POST body as JSON
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($this->tokenEndpoint, [
            'secret_id' => $this->secretId,
            'secret_key' => $this->secretKey,
        ]);

        Log::info('GoCardless token response', [
            'status' => $response->status(),
            'body' => $response->body(),
            'headers' => $response->headers(),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to get access token: ' . $response->body());
        }

        $tokenData = $response->json();
        $this->accessToken = $tokenData['access'] ?? '';

        if (empty($this->accessToken)) {
            throw new RuntimeException('No access token in response');
        }

        Log::info('GoCardless access token obtained', [
            'token_length' => strlen($this->accessToken),
        ]);

        return $this->accessToken;
    }
}
