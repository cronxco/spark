<?php

namespace App\Integrations\GoCardless;

use App\Integrations\Base\OAuthPlugin;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class GoCardlessBankPlugin extends OAuthPlugin
{
    // Cache configuration constants
    private const ACCOUNT_DETAILS_CACHE_TTL = 86400; // 24 hours
    private const REQUISITION_CACHE_TTL = 3600; // 1 hour

    // API monitoring constants
    private const API_CALLS_CACHE_KEY = 'gocardless_api_calls';
    private const API_EFFICIENCY_REPORT_TTL = 3600;

    // 1 hour
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

        if (! app()->environment('testing') && ! app()->runningUnitTests() && (empty($this->secretId) || empty($this->secretKey))) {
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
                'min' => 360,  // 6 hours minimum due to GoCardless rate limits (4 requests/day)
                'default' => 1440, // 24 hours default
                'description' => 'GoCardless has strict rate limits (4 requests/day). Minimum: 24 hours.',
            ],
        ];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'transactions' => [
                'label' => 'Transactions',
                'schema' => self::getConfigurationSchema(),
                'mandatory' => false,
            ],
            'balances' => [
                'label' => 'Balances',
                'schema' => self::getConfigurationSchema(),
                'mandatory' => false,
            ],
            'accounts' => [
                'label' => 'Accounts (master)',
                'schema' => [],
                'mandatory' => true,
            ],
        ];
    }

    public static function getIcon(): string
    {
        return 'o-building-library';
    }

    public static function getAccentColor(): string
    {
        return 'info';
    }

    public static function getDomain(): string
    {
        return 'media';
    }

    public static function getActionTypes(): array
    {
        return [
            'made_transaction' => [
                'icon' => 'o-arrow-right',
                'display_name' => 'Transaction',
                'description' => 'A bank transaction occurred',
                'display_with_object' => true,
                'value_unit' => 'GBP',
                'hidden' => false,
            ],
            'payment_to' => [
                'icon' => 'o-arrow-up-right',
                'display_name' => 'Payment Out',
                'description' => 'Money was sent from the account',
                'display_with_object' => true,
                'value_unit' => 'GBP',
                'hidden' => false,
            ],
            'payment_from' => [
                'icon' => 'o-arrow-down-left',
                'display_name' => 'Payment In',
                'description' => 'Money was received into the account',
                'display_with_object' => true,
                'value_unit' => 'GBP',
                'hidden' => false,
            ],
            'had_balance' => [
                'icon' => 'o-currency-pound',
                'display_name' => 'Balance Update',
                'description' => 'Account balance was updated',
                'display_with_object' => true,
                'value_unit' => 'GBP',
                'hidden' => false,
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
            'day' => [
                'icon' => 'o-calendar',
                'display_name' => 'Day',
                'description' => 'A calendar day',
                'hidden' => false,
            ],
            'balance_snapshot' => [
                'icon' => 'o-currency-pound',
                'display_name' => 'Balance Snapshot',
                'description' => 'A snapshot of account balance',
                'hidden' => false,
            ],
            'bank_account' => [
                'icon' => 'o-credit-card',
                'display_name' => 'Bank Account',
                'description' => 'A bank account',
                'hidden' => false,
            ],
            'transaction_counterparty' => [
                'icon' => 'o-user',
                'display_name' => 'Transaction Counterparty',
                'description' => 'A transaction counterparty',
                'hidden' => false,
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

            // Cache the account IDs for faster future access
            $accountIds = $requisition['accounts'] ?? [];
            if (! empty($accountIds)) {
                $this->cacheAccountList($group->id, $accountIds);
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
                'account_count' => count($accountIds),
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

        try {
            // Try to get cached account list first
            $cachedAccountIds = $this->getCachedAccountList($group->id);

            if ($cachedAccountIds !== null) {
                Log::info('GoCardless listAccounts: using cached account IDs', [
                    'integration_id' => $integration->id,
                    'group_id' => $group->id,
                    'cached_account_count' => count($cachedAccountIds),
                ]);
                $accountIds = $cachedAccountIds;
            } else {
                // Fall back to API call
                Log::info('GoCardless listAccounts: getting requisition from API', [
                    'integration_id' => $integration->id,
                    'group_id' => $group->id,
                    'account_id' => $group->account_id,
                ]);

                $requisition = $this->getRequisition($group->account_id);
                $accountIds = $requisition['accounts'] ?? [];

                // Cache the account list for future use
                if (! empty($accountIds)) {
                    $this->cacheAccountList($group->id, $accountIds);
                }

                Log::info('GoCardless listAccounts: retrieved and cached account IDs', [
                    'integration_id' => $integration->id,
                    'requisition_id' => $group->account_id,
                    'account_ids' => $accountIds,
                    'account_count' => count($accountIds),
                ]);
            }

            if (empty($accountIds)) {
                Log::warning('GoCardless listAccounts: no accounts found', [
                    'integration_id' => $integration->id,
                    'group_id' => $group->id,
                ]);

                return [];
            }

            $accounts = [];
            foreach ($accountIds as $accountId) {
                $accountDetails = $this->getAccount($accountId);
                if ($accountDetails) {
                    Log::info('GoCardless listAccounts: account details retrieved', [
                        'integration_id' => $integration->id,
                        'account_id' => $accountId,
                        'account_name' => $accountDetails['details'] ?? $accountDetails['ownerName'] ?? 'Unknown',
                        'from_cache' => ! isset($accountDetails['cached']) || $accountDetails['cached'] === false,
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
                'cached_used' => $cachedAccountIds !== null,
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
            // Try to get cached account list first
            $cachedAccountIds = $this->getCachedAccountList($group->id);

            if ($cachedAccountIds !== null) {
                Log::info('GoCardless updateIntegrationNames: using cached account IDs', [
                    'group_id' => $group->id,
                    'cached_account_count' => count($cachedAccountIds),
                ]);
                $accountIds = $cachedAccountIds;
            } else {
                // Fall back to API call
                $requisition = $this->getRequisition($group->account_id);
                $accountIds = $requisition['accounts'] ?? [];

                // Cache the account list for future use
                if (! empty($accountIds)) {
                    $this->cacheAccountList($group->id, $accountIds);
                }
            }

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
                'cached_used' => $cachedAccountIds !== null,
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
            // Try to get cached account list first
            $cachedAccountIds = $this->getCachedAccountList($group->id);

            if ($cachedAccountIds !== null) {
                Log::info('GoCardless onboarding: using cached account IDs', [
                    'group_id' => $group->id,
                    'cached_account_count' => count($cachedAccountIds),
                ]);
                $accountIds = $cachedAccountIds;
            } else {
                // Fall back to API call
                $requisition = $this->getRequisition($group->account_id);
                $accountIds = $requisition['accounts'] ?? [];

                // Cache the account list for future use
                if (! empty($accountIds)) {
                    $this->cacheAccountList($group->id, $accountIds);
                }

                Log::info('GoCardless onboarding: retrieved and cached account IDs', [
                    'group_id' => $group->id,
                    'account_ids' => $accountIds,
                ]);
            }

            if (empty($accountIds)) {
                Log::warning('GoCardless onboarding: no accounts found', [
                    'group_id' => $group->id,
                ]);

                return [];
            }

            $accounts = [];
            foreach ($accountIds as $accountId) {
                $accountDetails = $this->getAccount($accountId);
                if ($accountDetails) {
                    Log::info('GoCardless onboarding: account details retrieved', [
                        'account_id' => $accountId,
                        'account_name' => $accountDetails['details'] ?? $accountDetails['ownerName'] ?? 'Unknown',
                    ]);

                    // Create account object immediately for availability
                    $this->createAccountObjectForOnboarding($group, $accountDetails);

                    $accountDetails['id'] = $accountId;
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
                'cached_used' => $cachedAccountIds !== null,
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

            // Log the API request
            $this->logApiRequest('GET', '/api/v2/institutions/', [
                'Authorization' => '[REDACTED]',
            ], [
                'country' => $country,
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
            ])->get($this->apiBase . '/institutions/', [
                'country' => $country,
            ]);

            // Log the API response
            $this->logApiResponse('GET', '/api/v2/institutions/', $response->status(), $response->body(), $response->headers());

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

    /**
     * Force refresh of all caches for a group
     */
    public function refreshCaches(IntegrationGroup $group): array
    {
        Log::info('GoCardless manual cache refresh requested', [
            'group_id' => $group->id,
        ]);

        try {
            // Clear all caches for the group
            $this->clearGroupCaches($group->id);

            // Force refresh by making fresh API calls
            $requisition = $this->getRequisition($group->account_id);
            $accountIds = $requisition['accounts'] ?? [];

            $accounts = [];
            foreach ($accountIds as $accountId) {
                $accountDetails = $this->getAccount($accountId);
                if ($accountDetails) {
                    $accounts[] = $accountDetails;
                }
            }

            Log::info('GoCardless cache refresh completed', [
                'group_id' => $group->id,
                'account_count' => count($accounts),
            ]);

            return [
                'success' => true,
                'message' => 'Caches refreshed successfully',
                'account_count' => count($accounts),
            ];
        } catch (Throwable $e) {
            Log::error('GoCardless cache refresh failed', [
                'group_id' => $group->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Cache refresh failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get API efficiency report
     */
    public function getApiEfficiencyReport(): array
    {
        $calls = Cache::get(self::API_CALLS_CACHE_KEY, []);
        $totalCalls = count($calls);

        if ($totalCalls === 0) {
            return [
                'total_calls' => 0,
                'cached_calls' => 0,
                'api_calls' => 0,
                'cache_hit_rate' => 0,
                'calls_by_endpoint' => [],
                'recent_calls' => [],
            ];
        }

        $cachedCalls = count(array_filter($calls, fn ($call) => $call['from_cache']));
        $apiCalls = $totalCalls - $cachedCalls;
        $cacheHitRate = $totalCalls > 0 ? round(($cachedCalls / $totalCalls) * 100, 2) : 0;

        // Group by endpoint
        $callsByEndpoint = [];
        foreach ($calls as $call) {
            $endpoint = $call['endpoint'];
            if (! isset($callsByEndpoint[$endpoint])) {
                $callsByEndpoint[$endpoint] = [
                    'total' => 0,
                    'cached' => 0,
                    'api' => 0,
                ];
            }
            $callsByEndpoint[$endpoint]['total']++;
            if ($call['from_cache']) {
                $callsByEndpoint[$endpoint]['cached']++;
            } else {
                $callsByEndpoint[$endpoint]['api']++;
            }
        }

        // Get recent calls (last 10)
        $recentCalls = array_slice(array_reverse($calls), 0, 10);

        return [
            'total_calls' => $totalCalls,
            'cached_calls' => $cachedCalls,
            'api_calls' => $apiCalls,
            'cache_hit_rate' => $cacheHitRate,
            'calls_by_endpoint' => $callsByEndpoint,
            'recent_calls' => $recentCalls,
        ];
    }

    /**
     * Clear API monitoring data
     */
    public function clearApiMonitoringData(): void
    {
        Cache::forget(self::API_CALLS_CACHE_KEY);
        Log::info('GoCardless API monitoring data cleared');
    }

    /**
     * Clean up orphaned onboarding account objects
     * Call this periodically to remove account objects that were created during onboarding
     * but never associated with real integrations
     */
    public function cleanupOrphanedOnboardingObjects(): int
    {
        // Find onboarding account objects that are older than 24 hours
        $cutoffDate = now()->subHours(24);

        $orphanedObjects = EventObject::where('integration_id', 'like', 'onboarding_%')
            ->where('concept', 'account')
            ->where('type', 'bank_account')
            ->where('created_at', '<', $cutoffDate)
            ->get();

        $count = 0;
        foreach ($orphanedObjects as $object) {
            // Check if there's a real integration that should own this account
            $integrationId = str_replace('onboarding_', '', $object->integration_id);
            if (strpos($integrationId, '_') !== false) {
                [$groupId, $accountId] = explode('_', $integrationId, 2);

                // Look for real integrations that might claim this account
                $realIntegrations = Integration::where('integration_group_id', $groupId)
                    ->where('service', 'gocardless')
                    ->get();

                $found = false;
                foreach ($realIntegrations as $integration) {
                    // Check if this integration should own this account
                    try {
                        $accounts = $this->listAccounts($integration);
                        foreach ($accounts as $account) {
                            if (($account['id'] ?? null) === $accountId) {
                                $found = true;
                                break 2;
                            }
                        }
                    } catch (Throwable $e) {
                        // Skip if there's an error accessing the integration
                        continue;
                    }
                }

                if (! $found) {
                    // No real integration claims this account, safe to delete
                    $object->delete();
                    $count++;
                }
            }
        }

        if ($count > 0) {
            Log::info('GoCardless: Cleaned up orphaned onboarding account objects', [
                'count' => $count,
            ]);
        }

        return $count;
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

        // Use batch processing to share account data fetching
        $accounts = $this->getAccountsWithSharedData($integration);

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
     * Batch process accounts with shared data fetching
     */
    protected function getAccountsWithSharedData(Integration $integration): array
    {
        static $cachedAccounts = [];

        $cacheKey = "batch_accounts_{$integration->group_id}";

        if (! isset($cachedAccounts[$cacheKey])) {
            Log::info('GoCardless batch processing: fetching accounts for group', [
                'integration_id' => $integration->id,
                'group_id' => $integration->group_id,
            ]);

            $cachedAccounts[$cacheKey] = $this->listAccounts($integration);

            Log::info('GoCardless batch processing: cached accounts for group', [
                'integration_id' => $integration->id,
                'group_id' => $integration->group_id,
                'account_count' => count($cachedAccounts[$cacheKey]),
            ]);
        } else {
            Log::info('GoCardless batch processing: using cached accounts for group', [
                'integration_id' => $integration->id,
                'group_id' => $integration->group_id,
                'account_count' => count($cachedAccounts[$cacheKey]),
            ]);
        }

        return $cachedAccounts[$cacheKey];
    }

    /**
     * Process recent transactions for an integration
     */
    protected function processRecentTransactions(Integration $integration): void
    {
        Log::info('GoCardless processRecentTransactions called', [
            'integration_id' => $integration->id,
        ]);

        // Use batch processing to share account data fetching
        $accounts = $this->getAccountsWithSharedData($integration);

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

            $transactionData = $this->getAccountTransactions($account['id']);
            $bookedTransactions = $transactionData['booked'] ?? [];
            $pendingTransactions = $transactionData['pending'] ?? [];

            Log::info('GoCardless processRecentTransactions: got transactions', [
                'integration_id' => $integration->id,
                'account_id' => $account['id'],
                'booked_count' => count($bookedTransactions),
                'pending_count' => count($pendingTransactions),
                'total_count' => count($bookedTransactions) + count($pendingTransactions),
            ]);

            // Process pending transactions first
            foreach ($pendingTransactions as $transaction) {
                Log::info('GoCardless processRecentTransactions: processing pending transaction', [
                    'integration_id' => $integration->id,
                    'account_id' => $account['id'],
                    'transaction_id' => $transaction['transactionId'] ?? $transaction['internalTransactionId'] ?? 'unknown',
                    'amount' => $transaction['transactionAmount']['amount'] ?? 'unknown',
                    'description' => $transaction['remittanceInformationUnstructured'] ?? 'unknown',
                ]);

                $this->processTransactionItem($integration, $account, $transaction, 'pending');
            }

            // Process booked transactions (these may update existing pending transactions)
            foreach ($bookedTransactions as $transaction) {
                Log::info('GoCardless processRecentTransactions: processing booked transaction', [
                    'integration_id' => $integration->id,
                    'account_id' => $account['id'],
                    'transaction_id' => $transaction['transactionId'] ?? $transaction['internalTransactionId'] ?? 'unknown',
                    'amount' => $transaction['transactionAmount']['amount'] ?? 'unknown',
                    'description' => $transaction['remittanceInformationUnstructured'] ?? 'unknown',
                ]);

                $this->processTransactionItem($integration, $account, $transaction, 'booked');
            }
        }

        Log::info('GoCardless processRecentTransactions: completed', [
            'integration_id' => $integration->id,
        ]);
    }

    /**
     * Process a single transaction item
     */
    protected function processTransactionItem(Integration $integration, array $account, array $tx, string $status = 'booked'): void
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

        // Create consistent source ID based on transaction content
        // This ensures pending and booked versions of the same transaction have the same ID
        $sourceId = $this->generateConsistentTransactionId($tx);

        // Check if this transaction already exists (for status transitions)
        $existingEvent = Event::where('integration_id', $integration->id)
            ->where('source_id', $sourceId)
            ->first();

        // Determine if this is a status change
        $isStatusChange = $existingEvent && $existingEvent->event_metadata['transaction_status'] !== $status;

        // Determine action based on transaction amount and direction
        $amount = (float) ($tx['transactionAmount']['amount'] ?? 0);
        $action = $this->determineTransactionAction($amount, $status);

        // Preserve the best available timestamp
        $timestamp = $this->determineBestTimestamp($tx, $existingEvent, $status, $isStatusChange);

        $eventData = [
            'user_id' => $integration->user_id,
            'action' => $action,
            'domain' => self::getDomain(),
            'service' => 'gocardless',
            'time' => $timestamp,
            'value' => abs($amount),
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
                'transaction_status' => $status, // Track pending vs booked
                'status_changed' => $isStatusChange, // Track if this is a status transition
                'previous_status' => $existingEvent?->event_metadata['transaction_status'] ?? null,
                'timestamp_preserved' => $isStatusChange && $existingEvent && $existingEvent->time === $timestamp,
                'timestamp_reason' => $this->getTimestampReason($tx, $existingEvent, $status, $isStatusChange, $timestamp),
            ],
        ];

        Log::info('GoCardless processTransactionItem: processing event', [
            'integration_id' => $integration->id,
            'source_id' => $sourceId,
            'status' => $status,
            'existing_event' => $existingEvent?->id,
            'is_status_change' => $isStatusChange,
        ]);

        $event = Event::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'source_id' => $sourceId,
            ],
            $eventData
        );

        Log::info('GoCardless processTransactionItem: event processed', [
            'integration_id' => $integration->id,
            'event_id' => $event->id,
            'source_id' => $sourceId,
            'status' => $status,
            'created' => $event->wasRecentlyCreated,
            'updated' => $isStatusChange,
        ]);

        // Create or update actor (bank account)
        $actorObject = $this->upsertAccountObject($integration, $account);

        // Create or update target (counterparty)
        $targetObject = $this->upsertCounterpartyObject($integration, $tx);

        // Create the event relationship
        $event->objects()->syncWithoutDetaching([
            $actorObject->id => ['role' => 'actor'],
            $targetObject->id => ['role' => 'target'],
        ]);

        // Add relevant tags based on transaction status
        $tags = [
            'money',
            'transaction',
            'bank',
            'gocardless',
            $category,
        ];

        // Add status-specific tags
        if ($status === 'pending') {
            $tags[] = 'pending';
        } elseif ($status === 'booked') {
            $tags[] = 'settled';
            // Remove pending tag if it exists (status transition)
            if ($isStatusChange && $existingEvent) {
                $event->detachTag('pending');
                Log::info('GoCardless: Transitioned transaction from pending to settled', [
                    'event_id' => $event->id,
                    'source_id' => $sourceId,
                    'previous_status' => $existingEvent->event_metadata['transaction_status'],
                    'new_status' => $status,
                ]);
            }
        }

        // Add direction-based tags for booked transactions
        if ($status === 'booked') {
            $txAmount = (float) ($tx['transactionAmount']['amount'] ?? 0);
            if ($txAmount < 0) {
                $tags[] = 'debit';
            } else {
                $tags[] = 'credit';
            }
        }

        $event->syncTags($tags);

    }

    /**
     * Create balance event
     */
    protected function createBalanceEvent(Integration $integration, array $account, array $balance): void
    {
        $balanceReferenceDate = $balance['referenceDate'] ?? now()->toDateString();
        $sourceId = 'balance_' . $account['id'] . '_' . $balanceReferenceDate;
        $eventData = [
            'user_id' => $integration->user_id,
            'action' => 'had_balance',
            'domain' => self::getDomain(),
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
                'title' => $balanceReferenceDate,
            ],
            [
                'integration_id' => $integration->id,
                'time' => $balanceReferenceDate . ' 00:00:00',
                'content' => null,
                'url' => null,
                'image_url' => null,
            ]
        );

        // Create the event relationship
        $event->objects()->syncWithoutDetaching([
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
        $this->createBalanceBlock($integration, $account, $balance, $balanceReferenceDate);
    }

    /**
     * Create balance block
     */
    protected function createBalanceBlock(Integration $integration, array $account, array $data, string $balanceReferenceDate): void
    {
        $block = Block::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'source_id' => 'balance_' . $account['id'] . '_' . $balanceReferenceDate,
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
     * Generate consistent transaction ID based on transaction content
     * This ensures pending and booked versions of the same transaction have identical IDs
     */
    protected function generateConsistentTransactionId(array $transaction): string
    {
        // Get transaction date
        $date = $transaction['bookingDate'] ?? $transaction['valueDate'] ?? now()->toDateString();

        // Get counterparty name
        $counterparty = $transaction['creditorName'] ??
                       $transaction['debtorName'] ??
                       $transaction['remittanceInformationUnstructured'] ??
                       'unknown';

        // Get transaction amount (absolute value for consistency)
        $amount = abs((float) ($transaction['transactionAmount']['amount'] ?? 0));

        // Create content-based hash that's consistent between pending and booked states
        $contentString = $date . '_' .
                        Str::headline(Str::lower($counterparty)) . '_' .
                        $amount . '_' .
                        ($transaction['transactionAmount']['currency'] ?? 'EUR');

        return 'gc_' . md5($contentString);
    }

    /**
     * Determine the appropriate transaction action based on amount direction
     */
    protected function determineTransactionAction(float $amount, string $status): string
    {
        // Use directional actions for both pending and booked transactions
        if ($amount < 0) {
            return 'payment_to'; // Money going out
        } else {
            return 'payment_from'; // Money coming in
        }
    }

    /**
     * Determine the best timestamp to use, preserving precision from pending transactions
     */
    protected function determineBestTimestamp(array $currentTx, ?Event $existingEvent, string $status, bool $isStatusChange): string
    {
        $currentTimestamp = $currentTx['bookingDate'] ?? $currentTx['valueDate'] ?? now();

        // If no existing event, use current timestamp
        if (! $existingEvent) {
            return $currentTimestamp;
        }

        $existingTimestamp = $existingEvent->time;

        // If this is NOT a status change, keep existing timestamp to avoid unnecessary updates
        if (! $isStatusChange) {
            return $existingTimestamp;
        }

        // This is a status change (pending → booked), choose the most precise timestamp
        return $this->chooseBetterTimestamp($existingTimestamp, $currentTimestamp, $existingEvent, $status);
    }

    /**
     * Choose the better timestamp based on precision and context
     */
    protected function chooseBetterTimestamp(string $existingTime, string $newTime, Event $existingEvent, string $newStatus): string
    {
        $existingDateTime = Carbon::parse($existingTime);
        $newDateTime = Carbon::parse($newTime);

        // If existing transaction was pending and new is booked
        if ($existingEvent->event_metadata['transaction_status'] === 'pending' && $newStatus === 'booked') {

            // Check if the new timestamp looks like a generic batch processing time
            // Common patterns: 3:00 AM, 4:00 AM, midnight, etc.
            $newHour = $newDateTime->hour;
            $newMinute = $newDateTime->minute;

            $isGenericTime = (
                ($newHour >= 2 && $newHour <= 5 && $newMinute === 0) || // 2-5 AM with :00 minutes
                ($newHour === 0 && $newMinute === 0) || // Midnight
                ($newHour === 23 && $newMinute >= 55)   // Near midnight (end of day processing)
            );

            // If new time looks generic and existing time is more specific, keep existing
            if ($isGenericTime && ! $this->isGenericTime($existingDateTime)) {
                Log::info('GoCardless: Preserving precise pending timestamp over generic booked timestamp', [
                    'existing_time' => $existingTime,
                    'new_time' => $newTime,
                    'existing_hour' => $existingDateTime->hour,
                    'new_hour' => $newHour,
                    'reason' => 'new_time_looks_generic',
                ]);

                return $existingTime;
            }

            // If new time is more precise (has seconds/minutes that look real), use it
            if ($newMinute !== 0 || $newDateTime->second !== 0) {
                Log::info('GoCardless: Using more precise booked timestamp', [
                    'existing_time' => $existingTime,
                    'new_time' => $newTime,
                    'reason' => 'new_time_more_precise',
                ]);

                return $newTime;
            }

            // Default: keep the existing (pending) timestamp as it's likely more accurate
            Log::info('GoCardless: Keeping existing pending timestamp as default', [
                'existing_time' => $existingTime,
                'new_time' => $newTime,
                'reason' => 'keeping_pending_as_default',
            ]);

            return $existingTime;
        }

        // For other cases, use the newer timestamp
        return $newTime;
    }

    /**
     * Check if a timestamp looks like a generic/batch processing time
     */
    protected function isGenericTime(Carbon $dateTime): bool
    {
        $hour = $dateTime->hour;
        $minute = $dateTime->minute;

        return
            ($hour >= 2 && $hour <= 5 && $minute === 0) || // 2-5 AM with :00 minutes
            ($hour === 0 && $minute === 0) || // Midnight
            ($hour === 23 && $minute >= 55);   // Near midnight
    }

    /**
     * Get a human-readable explanation for timestamp choice
     */
    protected function getTimestampReason(array $currentTx, ?Event $existingEvent, string $status, bool $isStatusChange, string $chosenTimestamp): string
    {
        if (! $existingEvent) {
            return 'new_transaction';
        }

        if (! $isStatusChange) {
            return 'same_status_update';
        }

        $existingTime = $existingEvent->time;
        $currentTime = $currentTx['bookingDate'] ?? $currentTx['valueDate'] ?? now();

        if ($existingTime === $chosenTimestamp) {
            $newDateTime = Carbon::parse($currentTime);
            if ($this->isGenericTime($newDateTime)) {
                return 'preserved_pending_over_generic_booked';
            }

            return 'preserved_existing_timestamp';
        } else {
            $existingDateTime = Carbon::parse($existingTime);
            if ($this->isGenericTime($existingDateTime)) {
                return 'used_more_precise_booked_timestamp';
            }

            return 'used_new_timestamp';
        }
    }

    /**
     * Create account object during onboarding for immediate availability
     */
    protected function createAccountObjectForOnboarding(IntegrationGroup $group, array $account): EventObject
    {
        // Determine account type based on GoCardless data
        $accountType = match ($account['cashAccountType'] ?? null) {
            'CurrentAccount' => 'current_account',
            'SavingsAccount' => 'savings_account',
            'CreditCard' => 'credit_card',
            'InvestmentAccount' => 'investment_account',
            'LoanAccount' => 'loan',
            default => 'other',
        };

        // Generate a proper account name
        $accountName = $this->generateAccountName($account);

        Log::info('GoCardless onboarding: creating account object', [
            'group_id' => $group->id,
            'account_id' => $account['id'] ?? 'unknown',
            'account_name' => $accountName,
        ]);

        // Use onboarding-specific integration ID to avoid conflicts
        $onboardingIntegrationId = 'onboarding_' . $group->id . '_' . ($account['id'] ?? 'unknown');

        return EventObject::updateOrCreate(
            [
                'integration_id' => $onboardingIntegrationId,
                'concept' => 'account',
                'type' => 'bank_account',
                'title' => $accountName,
            ],
            [
                'user_id' => $group->user_id,
                'content' => json_encode($account),
                'url' => null,
                'image_url' => null,
                'time' => null,
                'metadata' => [
                    'name' => $accountName,
                    'provider' => $account['institution_id'] ?? 'GoCardless',
                    'account_type' => $accountType,
                    'currency' => $account['currency'] ?? 'GBP',
                    'account_number' => $account['resourceId'] ?? null,
                    'raw' => $account,
                    'onboarding_created' => true, // Flag to indicate this was created during onboarding
                ],
            ]
        );
    }

    /**
     * Upsert account object - handles both onboarding-created and transaction-created objects
     */
    protected function upsertAccountObject(Integration $integration, array $account): EventObject
    {
        // Determine account type based on GoCardless data
        $accountType = match ($account['cashAccountType'] ?? null) {
            'CurrentAccount' => 'current_account',
            'SavingsAccount' => 'savings_account',
            'CreditCard' => 'credit_card',
            'InvestmentAccount' => 'investment_account',
            'LoanAccount' => 'loan',
            default => 'other',
        };

        // Generate a proper account name
        $accountName = $this->generateAccountName($account);

        // First, try to find an existing onboarding-created account object
        $accountId = $account['id'] ?? 'unknown';
        $onboardingIntegrationId = 'onboarding_' . $integration->group_id . '_' . $accountId;

        $existingObject = EventObject::where('integration_id', $onboardingIntegrationId)
            ->where('concept', 'account')
            ->where('type', 'bank_account')
            ->where('title', $accountName)
            ->first();

        if ($existingObject) {
            Log::info('GoCardless: Found existing onboarding account object, updating with integration ID', [
                'account_id' => $accountId,
                'existing_integration_id' => $existingObject->integration_id,
                'new_integration_id' => $integration->id,
            ]);

            // Update the integration ID to point to the real integration
            $existingObject->update([
                'integration_id' => $integration->id,
                'metadata->onboarding_created' => false, // Remove onboarding flag
            ]);

            return $existingObject;
        }

        // No existing onboarding object found, create new one
        return EventObject::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'concept' => 'account',
                'type' => 'bank_account',
                'title' => $accountName,
            ],
            [
                'user_id' => $integration->user_id,
                'content' => json_encode($account),
                'url' => null,
                'image_url' => null,
                'time' => null,
                'metadata' => [
                    'name' => $accountName,
                    'provider' => $account['institution_id'] ?? 'GoCardless',
                    'account_type' => $accountType,
                    'currency' => $account['currency'] ?? 'GBP',
                    'account_number' => $account['resourceId'] ?? null,
                    'raw' => $account,
                ],
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

        // Log the API request
        $this->logApiRequest('GET', '/api/v2/requisitions/', [
            'Authorization' => '[REDACTED]',
            'Content-Type' => 'application/json',
        ]);

        // First, check if there are existing requisitions we can reuse
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ])->get($this->apiBase . '/requisitions/');

        // Log the API response
        $this->logApiResponse('GET', '/api/v2/requisitions/', $response->status(), $response->body(), $response->headers());

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

        // Log the API request
        $this->logApiRequest('POST', '/api/v2/requisitions/', $requestHeaders, $requestData);

        $response = Http::withHeaders($requestHeaders)->post($requestUrl, $requestData);

        // Log the API response
        $this->logApiResponse('POST', '/api/v2/requisitions/', $response->status(), $response->body(), $response->headers());

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

        // Log the API request
        $this->logApiRequest('POST', '/api/v2/agreements/enduser/', [
            'Authorization' => '[REDACTED]',
            'Content-Type' => 'application/json',
        ], $requestData);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ])->post($this->apiBase . '/agreements/enduser/', $requestData);

        // Log the API response
        $this->logApiResponse('POST', '/api/v2/agreements/enduser/', $response->status(), $response->body(), $response->headers());

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

        // Log the API request
        $this->logApiRequest('GET', '/api/v2/requisitions/', [
            'Authorization' => '[REDACTED]',
            'Content-Type' => 'application/json',
        ]);

        // First, try to get existing requisitions to see if we can reuse one
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ])->get($this->apiBase . '/requisitions/');

        // Log the API response
        $this->logApiResponse('GET', '/api/v2/requisitions/', $response->status(), $response->body(), $response->headers());

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

        // Log the API request
        $this->logApiRequest('POST', '/api/v2/requisitions/', [
            'Authorization' => '[REDACTED]',
            'Content-Type' => 'application/json',
        ], $requestData);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ])->post($this->apiBase . '/requisitions/', $requestData);

        // Log the API response
        $this->logApiResponse('POST', '/api/v2/requisitions/', $response->status(), $response->body(), $response->headers());

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
     * Get requisition details with caching
     */
    protected function getRequisition(string $requisitionId): array
    {
        $cacheKey = "gocardless_requisition_{$requisitionId}";

        // Check if data is in cache first
        if (Cache::has($cacheKey)) {
            // Track cache hit
            $this->trackApiCall('/api/v2/requisitions/{id}/', 'GET', true);
            Log::info('GoCardless getRequisition: using cached data', [
                'requisition_id' => $requisitionId,
                'cache_key' => $cacheKey,
            ]);
        }

        return Cache::remember($cacheKey, self::REQUISITION_CACHE_TTL, function () use ($requisitionId) {
            Log::info('GoCardless getRequisition called (API call)', [
                'requisition_id' => $requisitionId,
                'api_endpoint' => $this->apiBase . '/requisitions/' . $requisitionId . '/',
            ]);

            // Log the API request
            $this->logApiRequest('GET', '/api/v2/requisitions/' . $requisitionId . '/', [
                'Authorization' => '[REDACTED]',
            ]);

            $startTime = microtime(true);
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
            ])->get($this->apiBase . '/requisitions/' . $requisitionId . '/');
            $responseTime = (int) ((microtime(true) - $startTime) * 1000); // Convert to milliseconds

            // Log the API response
            $this->logApiResponse('GET', '/api/v2/requisitions/' . $requisitionId . '/', $response->status(), $response->body(), $response->headers());

            // Track API call for monitoring
            $this->trackApiCall('/api/v2/requisitions/{id}/', 'GET', false, $responseTime);

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
        });
    }

    /**
     * Clear requisition cache
     */
    protected function clearRequisitionCache(string $requisitionId): void
    {
        $cacheKey = "gocardless_requisition_{$requisitionId}";
        Cache::forget($cacheKey);

        Log::info('GoCardless requisition cache cleared', [
            'requisition_id' => $requisitionId,
            'cache_key' => $cacheKey,
        ]);
    }

    /**
     * Get account details with caching
     */
    protected function getAccount(string $accountId): ?array
    {
        $cacheKey = "gocardless_account_details_{$accountId}";

        // Check if data is in cache first
        if (Cache::has($cacheKey)) {
            // Track cache hit
            $this->trackApiCall('/api/v2/accounts/{id}/details/', 'GET', true);
            Log::info('GoCardless getAccount: using cached data', [
                'account_id' => $accountId,
                'cache_key' => $cacheKey,
            ]);
        }

        return Cache::remember($cacheKey, self::ACCOUNT_DETAILS_CACHE_TTL, function () use ($accountId) {
            Log::info('GoCardless getAccount: fetching from API (not cached)', [
                'account_id' => $accountId,
            ]);

            // Log the API request
            $this->logApiRequest('GET', '/api/v2/accounts/' . $accountId . '/details/', [
                'Authorization' => '[REDACTED]',
            ]);

            $startTime = microtime(true);
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
            ])->get($this->apiBase . '/accounts/' . $accountId . '/details/');
            $responseTime = (int) ((microtime(true) - $startTime) * 1000); // Convert to milliseconds

            // Log the API response
            $this->logApiResponse('GET', '/api/v2/accounts/' . $accountId . '/details/', $response->status(), $response->body(), $response->headers());

            // Track API call for monitoring
            $this->trackApiCall('/api/v2/accounts/{id}/details/', 'GET', false, $responseTime);

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
        });
    }

    /**
     * Clear account details cache for a specific account
     */
    protected function clearAccountCache(string $accountId): void
    {
        $cacheKey = "gocardless_account_details_{$accountId}";
        Cache::forget($cacheKey);

        Log::info('GoCardless account cache cleared', [
            'account_id' => $accountId,
            'cache_key' => $cacheKey,
        ]);
    }

    /**
     * Cache account list for a group to avoid redundant requisition calls
     */
    protected function cacheAccountList(string $groupId, array $accountIds): void
    {
        $cacheKey = "gocardless_group_accounts_{$groupId}";
        Cache::put($cacheKey, $accountIds, self::REQUISITION_CACHE_TTL);

        Log::info('GoCardless account list cached', [
            'group_id' => $groupId,
            'account_count' => count($accountIds),
            'cache_key' => $cacheKey,
        ]);
    }

    /**
     * Get cached account list for a group
     */
    protected function getCachedAccountList(string $groupId): ?array
    {
        $cacheKey = "gocardless_group_accounts_{$groupId}";

        return Cache::get($cacheKey);
    }

    /**
     * Clear all caches for a group (useful when data changes)
     */
    protected function clearGroupCaches(string $groupId): void
    {
        // Clear group account list cache
        $accountListKey = "gocardless_group_accounts_{$groupId}";
        Cache::forget($accountListKey);

        // Clear requisition cache
        $requisitionKey = 'gocardless_requisition_*';
        // Note: Since we can't use wildcards with Cache::forget(), we'll handle this differently
        // The requisition cache will naturally expire based on TTL

        // Clear batch processing cache
        $batchKey = "batch_accounts_{$groupId}";
        // This is handled by the static cache in getAccountsWithSharedData

        Log::info('GoCardless group caches cleared', [
            'group_id' => $groupId,
            'cleared_keys' => [$accountListKey, 'requisition_*', $batchKey],
        ]);
    }

    /**
     * Clear caches when account data might have changed
     */
    protected function invalidateAccountCaches(string $accountId): void
    {
        $this->clearAccountCache($accountId);

        Log::info('GoCardless account caches invalidated', [
            'account_id' => $accountId,
        ]);
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

        // Log the API request
        $this->logApiRequest('GET', '/api/v2/accounts/' . $accountId . '/balances/', [
            'Authorization' => '[REDACTED]',
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ])->get($this->apiBase . '/accounts/' . $accountId . '/balances/');

        // Log the API response
        $this->logApiResponse('GET', '/api/v2/accounts/' . $accountId . '/balances/', $response->status(), $response->body(), $response->headers());

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

        // Log the API request
        $this->logApiRequest('GET', '/api/v2/accounts/' . $accountId . '/transactions/', [
            'Authorization' => '[REDACTED]',
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ])->get($this->apiBase . '/accounts/' . $accountId . '/transactions/');

        // Log the API response
        $this->logApiResponse('GET', '/api/v2/accounts/' . $accountId . '/transactions/', $response->status(), $response->body(), $response->headers());

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

        Log::info('GoCardless getAccountTransactions success', [
            'account_id' => $accountId,
            'booked_count' => count($bookedTransactions),
            'pending_count' => count($pendingTransactions),
            'total_count' => count($bookedTransactions) + count($pendingTransactions),
            'response_structure' => array_keys($data),
        ]);

        // Return structured data to enable separate processing
        return [
            'booked' => $bookedTransactions,
            'pending' => $pendingTransactions,
        ];
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

        // Log the API request
        $this->logApiRequest('POST', '/api/v2/token/new/', [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], [
            'secret_id' => '[REDACTED]',
            'secret_key' => '[REDACTED]',
        ]);

        // Send credentials in POST body as JSON
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($this->tokenEndpoint, [
            'secret_id' => $this->secretId,
            'secret_key' => $this->secretKey,
        ]);

        // Log the API response
        $this->logApiResponse('POST', '/api/v2/token/new/', $response->status(), $response->body(), $response->headers());

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

    /**
     * Get the appropriate log channel for this plugin
     */
    protected function getLogChannel(): string
    {
        $pluginChannel = 'api_debug_' . str_replace([' ', '-', '_'], '_', static::getIdentifier());

        return config('logging.channels.' . $pluginChannel) ? $pluginChannel : 'api_debug';
    }

    /**
     * Log API request details for debugging
     */
    protected function logApiRequest(string $method, string $endpoint, array $headers = [], array $data = [], ?string $integrationId = null): void
    {
        log_integration_api_request(
            static::getIdentifier(),
            $method,
            $endpoint,
            $this->sanitizeHeaders($headers),
            $this->sanitizeData($data),
            $integrationId ?: '',
            true // Use per-instance logging
        );
    }

    /**
     * Log API response details for debugging
     */
    protected function logApiResponse(string $method, string $endpoint, int $statusCode, string $body, array $headers = [], ?string $integrationId = null): void
    {
        log_integration_api_response(
            static::getIdentifier(),
            $method,
            $endpoint,
            $statusCode,
            $this->sanitizeResponseBody($body),
            $this->sanitizeHeaders($headers),
            $integrationId ?: '',
            true // Use per-instance logging
        );
    }

    /**
     * Log webhook payload for debugging
     */
    protected function logWebhookPayload(string $service, string $integrationId, array $payload, array $headers = []): void
    {
        log_integration_webhook(
            $service,
            $integrationId,
            $this->sanitizeData($payload),
            $this->sanitizeHeaders($headers),
            true // Use per-instance logging
        );
    }

    /**
     * Sanitize headers for logging (remove sensitive data)
     */
    protected function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'x-api-key', 'x-auth-token'];
        $sanitized = [];

        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitiveHeaders)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize data for logging (remove sensitive data)
     */
    protected function sanitizeData(array $data): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth', 'secret_id', 'secret_key'];
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitiveKeys)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize response body for logging (limit size and remove sensitive data)
     */
    protected function sanitizeResponseBody(string $body): string
    {
        // Limit response body size to prevent huge logs
        $maxLength = 10000;
        if (strlen($body) > $maxLength) {
            return substr($body, 0, $maxLength) . '... [TRUNCATED]';
        }

        // Try to parse as JSON and sanitize sensitive fields
        $parsed = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            $sanitized = $this->sanitizeData($parsed);

            return json_encode($sanitized, JSON_PRETTY_PRINT);
        }

        return $body;
    }

    /**
     * Track API call for monitoring purposes
     */
    protected function trackApiCall(string $endpoint, string $method, bool $fromCache = false, ?int $responseTime = null): void
    {
        $callData = [
            'endpoint' => $endpoint,
            'method' => $method,
            'from_cache' => $fromCache,
            'timestamp' => now()->toISOString(),
            'response_time' => $responseTime,
        ];

        // Store in cache for monitoring
        $existingCalls = Cache::get(self::API_CALLS_CACHE_KEY, []);
        $existingCalls[] = $callData;

        // Keep only last 1000 calls to prevent memory issues
        if (count($existingCalls) > 1000) {
            $existingCalls = array_slice($existingCalls, -1000);
        }

        Cache::put(self::API_CALLS_CACHE_KEY, $existingCalls, self::API_EFFICIENCY_REPORT_TTL);
    }

    /**
     * Log API efficiency metrics
     */
    protected function logApiEfficiency(string $context = 'general'): void
    {
        $report = $this->getApiEfficiencyReport();

        Log::info('GoCardless API efficiency report', [
            'context' => $context,
            'total_calls' => $report['total_calls'],
            'cached_calls' => $report['cached_calls'],
            'api_calls' => $report['api_calls'],
            'cache_hit_rate' => $report['cache_hit_rate'] . '%',
            'top_endpoints' => array_slice($report['calls_by_endpoint'], 0, 5),
        ]);
    }
}
