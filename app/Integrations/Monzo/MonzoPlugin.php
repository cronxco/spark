<?php

namespace App\Integrations\Monzo;

use App\Integrations\Base\OAuthPlugin;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class MonzoPlugin extends OAuthPlugin
{
    protected string $apiBase = 'https://api.monzo.com';
    protected string $authBase = 'https://auth.monzo.com';
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;

    public function __construct()
    {
        $this->clientId = config('services.monzo.client_id') ?? '';
        $this->clientSecret = config('services.monzo.client_secret') ?? '';
        $this->redirectUri = config('services.monzo.redirect') ?? route('integrations.oauth.callback', ['service' => 'monzo']);

        if (app()->environment() !== 'testing' && (empty($this->clientId) || empty($this->clientSecret))) {
            throw new \InvalidArgumentException('Monzo OAuth credentials are not configured');
        }
    }

    public static function getIdentifier(): string
    {
        return 'monzo';
    }

    public static function getDisplayName(): string
    {
        return 'Monzo';
    }

    protected function getRequiredScopes(): string
    {
        // Monzo OAuth scopes needed for read-only ingestion
        return implode(' ', [
            'accounts:read',
            'transactions:read',
            'balance:read',
            'pots:read',
        ]);
    }

    protected function fetchAccountInfoForGroup(IntegrationGroup $group): void
    {
        $account = $this->getPrimaryAccount($group);
        if ($account) {
            $group->update([
                'account_id' => $account['id'],
            ]);
        }
    }

    public static function getDescription(): string
    {
        return 'Connect your Monzo bank account to ingest transactions, pots, and balances.';
    }

    public static function getConfigurationSchema(): array
    {
        return [
            'include_pot_transfers' => [
                'type' => 'array',
                'label' => 'Include Pot Transfers',
                'description' => 'Process pot transfer transactions',
                'options' => [
                    'enabled' => 'Enabled',
                ],
            ],
        ];
    }

    public static function getInstanceTypes(): array
    {
        return [
            'accounts' => [
                'label' => 'Accounts (Master)',
                'schema' => [],
            ],
            'transactions' => [
                'label' => 'Transactions',
                'schema' => self::getConfigurationSchema(),
            ],
            'pots' => [
                'label' => 'Pots',
                'schema' => [],
            ],
            'balances' => [
                'label' => 'Balances',
                'schema' => [],
            ],
        ];
    }

    public function getOAuthUrl(IntegrationGroup $group): string
    {
        $csrfToken = Str::random(32);
        $sessionKey = 'oauth_csrf_' . session_id() . '_' . $group->id;
        Session::put($sessionKey, $csrfToken);

        $state = encrypt([
            'group_id' => $group->id,
            'user_id' => $group->user_id,
            'csrf_token' => $csrfToken,
        ]);

        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'state' => $state,
        ];

        return $this->authBase . '?' . http_build_query($params);
    }

    public function handleOAuthCallback(Request $request, IntegrationGroup $group): void
    {
        $error = $request->get('error');
        if ($error) {
            throw new \Exception('Monzo authorization failed: ' . $error);
        }

        $code = (string) $request->get('code');
        $state = (string) $request->get('state');
        if (!$code || !$state) {
            throw new \Exception('Invalid OAuth callback');
        }

        $stateData = decrypt($state);
        if ((string) ($stateData['group_id'] ?? '') !== (string) $group->id) {
            throw new \Exception('Invalid state parameter');
        }
        $sessionKey = 'oauth_csrf_' . session_id() . '_' . $group->id;
        $expectedCsrf = Session::get($sessionKey);
        if (($stateData['csrf_token'] ?? null) !== $expectedCsrf) {
            throw new \Exception('Invalid CSRF token');
        }

        $response = Http::asForm()->post($this->apiBase . '/oauth2/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'code' => $code,
        ]);

        if (!$response->successful()) {
            Log::error('Monzo token exchange failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to exchange code for tokens');
        }

        $data = $response->json();
        $group->update([
            'access_token' => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'expiry' => isset($data['expires_in']) ? now()->addSeconds((int) $data['expires_in']) : null,
        ]);

        // Fetch account id to store on group for convenience
        $account = $this->getPrimaryAccount($group);
        if ($account) {
            $group->update([
                'account_id' => $account['id'],
            ]);
        }
    }

    protected function authHeaders(Integration $integration): array
    {
        $group = $integration->group;
        $token = $group?->access_token ?? $integration->access_token;
        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }

    protected function getPrimaryAccount(IntegrationGroup $group): ?array
    {
        $resp = Http::withToken((string) $group->access_token)
            ->get($this->apiBase . '/accounts');
        if (!$resp->successful()) {
            return null;
        }
        $accounts = $resp->json('accounts') ?? [];
        foreach ($accounts as $acc) {
            if (($acc['type'] ?? '') === 'uk_retail') {
                return $acc;
            }
        }
        return $accounts[0] ?? null;
    }

    public function fetchData(Integration $integration): void
    {
        // Only do the work relevant to this instance type to avoid duplicate events across instances
        $instanceType = $integration->instance_type ?: 'transactions';
        if ($instanceType === 'accounts') {
            // Master instance: do not create any events; only seeding is done via migration
            return;
        }
        $accounts = $this->listAccounts($integration);
        if (empty($accounts)) {
            return;
        }
        foreach ($accounts as $account) {
            if ($instanceType === 'transactions') {
                $this->processRecentTransactions($integration, $account);
            } elseif ($instanceType === 'balances') {
                $this->processBalanceSnapshot($integration, $account);
            } elseif ($instanceType === 'pots' || $instanceType === 'accounts') {
                // For pots/accounts, only upsert shared objects; do not create transaction or balance events
                $this->processPotsSnapshot($integration, $account);
                // Ensure account objects exist under master
                $this->upsertAccountObject($integration, $account);
            }
        }
    }

    protected function listAccounts(Integration $integration): array
    {
        $resp = Http::withHeaders($this->authHeaders($integration))
            ->get($this->apiBase . '/accounts');
        if (!$resp->successful()) {
            return [];
        }
        return $resp->json('accounts') ?? [];
    }

    private function resolveMasterIntegration(Integration $integration): Integration
    {
        $group = $integration->group;
        $master = Integration::where('integration_group_id', $group->id)
            ->where('service', static::getIdentifier())
            ->where('instance_type', 'accounts')
            ->first();
        if (!$master) {
            $master = $this->createInstance($group, 'accounts');
        }
        return $master;
    }

    protected function processPotsSnapshot(Integration $integration, array $account): void
    {
        $resp = Http::withHeaders($this->authHeaders($integration))
            ->get($this->apiBase . '/pots', [
                'current_account_id' => $account['id'],
            ]);
        if (!$resp->successful()) {
            return;
        }
        $pots = $resp->json('pots') ?? [];
        foreach ($pots as $pot) {
            $this->upsertPotObject($integration, $pot);
        }
    }

    protected function processBalanceSnapshot(Integration $integration, array $account): void
    {
        $resp = Http::withHeaders($this->authHeaders($integration))
            ->get($this->apiBase . '/balance', [
                'account_id' => $account['id'],
            ]);
        if (!$resp->successful()) {
            return;
        }
        $json = $resp->json();
        $balance = (int) ($json['balance'] ?? 0); // cents
        $spendToday = (int) ($json['spend_today'] ?? 0); // cents
        $date = now()->toDateString();

        // Create or update the target "day" object (target_id is NOT NULL in events)
        $dayObject = EventObject::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'concept' => 'day',
                'type' => 'day',
                'title' => $date,
            ],
            [
                'time' => $date . ' 00:00:00',
                'content' => null,
                'metadata' => ['date' => $date],
            ]
        );

        $event = Event::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'source_id' => 'monzo_balance_' . $account['id'] . '_' . $date,
            ],
            [
                'time' => $date . ' 23:59:59',
                'actor_id' => $this->upsertAccountObject($integration, $account)->id,
                'service' => 'monzo',
                'domain' => 'money',
                'action' => 'had_balance',
                'value' => abs($balance), // integer cents
                'value_multiplier' => 100,
                'value_unit' => 'GBP',
                'event_metadata' => [
                    'spend_today' => $spendToday / 100,
                ],
                'target_id' => $dayObject->id,
            ]
        );

        // Add balance blocks (Spend Today + Balance Change)
        try {
            $this->addBalanceBlocks($event, $integration, $account, $date, $balance, $spendToday);
        } catch (\Throwable $e) {
            // Non-fatal if blocks fail
        }
    }

    public function addBalanceBlocks(Event $event, Integration $integration, array $account, string $date, int $balance, int $spendToday): void
    {
        // Spend Today block
        $event->blocks()->create([
            'time' => $event->time,
            'integration_id' => $event->integration_id,
            'title' => 'Spend Today',
            'content' => null,
            'media_url' => null,
            'value' => abs($spendToday),
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
        ]);

        // Balance Change vs previous day
        $actorId = $event->actor_id;
        $prev = Event::where('integration_id', $integration->id)
            ->where('service', 'monzo')
            ->where('action', 'had_balance')
            ->where('actor_id', $actorId)
            ->where('time', '<', $date.' 00:00:00')
            ->orderBy('time', 'desc')
            ->first();
        if ($prev) {
            $prevVal = (int) abs($prev->value ?? 0);
            $currentVal = (int) abs($balance);
            $delta = $currentVal - $prevVal; // cents
            if ($delta !== 0) {
                $event->blocks()->create([
                    'time' => $event->time,
                    'integration_id' => $event->integration_id,
                    'title' => 'Balance Change',
                    'content' => $delta > 0 ? 'Up' : 'Down',
                    'media_url' => null,
                    'value' => abs($delta),
                    'value_multiplier' => 100,
                    'value_unit' => 'GBP',
                ]);
            }
        }
    }

    protected function processRecentTransactions(Integration $integration, array $account): void
    {
        $sinceIso = now()->subDays(7)->toIso8601String();
        $resp = Http::withHeaders($this->authHeaders($integration))
            ->get($this->apiBase . '/transactions', [
                'account_id' => $account['id'],
                'expand[]' => 'merchant',
                'since' => $sinceIso,
                'limit' => 100,
            ]);
        if (!$resp->successful()) {
            return;
        }
        $txs = $resp->json('transactions') ?? [];
        foreach ($txs as $tx) {
            $this->processTransactionItem($integration, $tx, $account['id']);
        }
    }

    // Migration helpers used by ProcessIntegrationPage
    public function processTransactionItem(Integration $integration, array $tx, string $accountId): void
    {
        $actor = $this->upsertAccountObject($integration, ['id' => $accountId, 'type' => 'uk_retail']);
        $master = $this->resolveMasterIntegration($integration);

        // If counterparty matches a known pot id, link to the pot account object
        $counterpartyId = $tx['counterparty']['account_id'] ?? $tx['counterparty']['id'] ?? $tx['counterparty'] ?? null;
        $target = null;
        if ($counterpartyId) {
            $target = EventObject::where('integration_id', $master->id)
                ->where('concept', 'account')
                ->where('type', 'monzo_pot')
                ->whereJsonContains('metadata->pot_id', $counterpartyId)
                ->first();
        }

        if (!$target) {
            // Fallback: create/find generic counterparty
            $targetTitle = $tx['merchant']['name'] ?? ($tx['description'] ?? 'Unknown');
            $target = EventObject::updateOrCreate(
                [
                    'integration_id' => $master->id,
                    'concept' => 'counterparty',
                    'type' => 'monzo_counterparty',
                    'title' => $targetTitle,
                ],
                [
                    'time' => $tx['created'] ?? now(),
                    'content' => $tx['description'] ?? null,
                    'metadata' => [
                        'merchant_id' => $tx['merchant']['id'] ?? null,
                        'category' => $tx['category'] ?? null,
                        'currency' => $tx['currency'] ?? 'GBP',
                    ],
                ]
            );
        }

        $action = $this->deriveAction($tx);

        $event = Event::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'source_id' => (string) ($tx['id'] ?? Str::uuid()),
            ],
            [
                'time' => $tx['created'] ?? now(),
                'actor_id' => $actor->id,
                'service' => 'monzo',
                'domain' => 'money',
                'action' => $action,
                'value' => abs((int) ($tx['amount'] ?? 0)), // integer cents
                'value_multiplier' => 100,
                'value_unit' => $tx['currency'] ?? 'GBP',
                'event_metadata' => [
                    'category' => $tx['category'] ?? null,
                    'scheme' => $tx['scheme'] ?? null,
                    'notes' => $tx['notes'] ?? null,
                    'local_amount' => $tx['local_amount'] ?? null,
                    'local_currency' => $tx['local_currency'] ?? null,
                ],
                'target_id' => $target->id,
            ]
        );

        $this->tagTransactionEvent($event, $tx);
        $this->maybeAddTransactionBlocks($event, $tx);
    }

    private function deriveAction(array $tx): string
    {
        $amount = (int) ($tx['amount'] ?? 0);
        $scheme = $tx['scheme'] ?? null;
        $declined = (int) ($tx['declined'] ?? 0) === 1;

        // Salary detection (BACS, amount > £1500 and merchant name matches configured salary name)
        if ($scheme === 'bacs' && $amount > 150000) {
            $salaryName = (string) (config('services.monzo.salary_name') ?? '');
            if ($salaryName !== '' && isset($tx['merchant']['name']) && $tx['merchant']['name'] === $salaryName) {
                return 'salary_received_from';
            }
        }

        if ($scheme === 'mastercard') {
            if ($declined) {
                return 'declined_payment_to';
            }
            return $amount < 0 ? 'card_payment_to' : 'card_refund_from';
        }
        if ($scheme === 'uk_retail_pot') {
            return $amount < 0 ? 'pot_transfer_to' : 'pot_withdrawal_from';
        }
        if ($scheme === 'account_interest') {
            return $amount < 0 ? 'interest_repaid' : 'interest_earned';
        }
        if ($scheme === 'monzo_flex') {
            return $amount < 0 ? 'monzo_flex_payment' : 'monzo_flex_loan';
        }
        if ($scheme === 'bacs') {
            return $amount < 0 ? 'direct_debit_to' : 'direct_credit_from';
        }
        if ($scheme === 'p2p_payment') {
            return $amount < 0 ? 'monzo_me_to' : 'monzo_me_from';
        }
        if ($scheme === 'payport_faster_payments') {
            return $amount < 0 ? 'bank_transfer_to' : 'bank_transfer_from';
        }
        if ($scheme === 'monzo_paid') {
            return $amount < 0 ? 'fee_paid_for' : 'fee_refunded_for';
        }
        // Default fallback
        return $amount < 0 ? 'other_debit_to' : 'other_credit_from';
    }

    private function tagTransactionEvent(Event $event, array $tx): void
    {
        // Category tag
        if (!empty($tx['category'])) {
            $event->attachTag((string) $tx['category']);
        }
        // Debit/Credit tag
        $amount = (int) ($tx['amount'] ?? 0);
        $event->attachTag($amount < 0 ? 'debit' : 'credit');
        // Scheme tag
        if (!empty($tx['scheme'])) {
            $event->attachTag((string) $tx['scheme']);
        }
        // Currency tag
        if (!empty($tx['local_currency'])) {
            $event->attachTag((string) $tx['local_currency']);
        }
        // Merchant emoji/country/category
        if (!empty($tx['merchant']['emoji'])) {
            $event->attachTag((string) $tx['merchant']['emoji']);
        }
        if (!empty($tx['merchant']['address']['country'])) {
            $event->attachTag((string) $tx['merchant']['address']['country']);
        }
        if (!empty($tx['merchant']['category'])) {
            $event->attachTag((string) $tx['merchant']['category']);
        }
        // Decline / settled
        if ((int) ($tx['declined'] ?? 0) === 1) {
            $event->attachTag('declined');
            if (!empty($tx['decline_reason'])) {
                $event->attachTag((string) $tx['decline_reason']);
            }
        } elseif ((int) ($tx['pending'] ?? 0) !== 1) {
            $event->attachTag('settled');
        }
    }

    private function maybeAddTransactionBlocks(Event $event, array $tx): void
    {
        // Merchant details
        if (!empty($tx['merchant'])) {
            $m = (array) $tx['merchant'];
            $parts = [];
            if (!empty($m['name'])) {
                $parts[] = (string) $m['name'];
            }
            if (!empty($m['category'])) {
                $parts[] = (string) $m['category'];
            }
            if (!empty($m['address'])) {
                $addr = (array) $m['address'];
                $addrLine = implode(', ', array_values(array_filter([
                    $addr['address'] ?? null,
                    $addr['city'] ?? null,
                    $addr['postcode'] ?? null,
                    $addr['country'] ?? null,
                ])));
                if ($addrLine !== '') {
                    $parts[] = $addrLine;
                }
            }
            $event->blocks()->create([
                'time' => $event->time,
                'integration_id' => $event->integration_id,
                'title' => 'Merchant',
                'content' => implode(' • ', $parts),
                'media_url' => $m['logo'] ?? null,
                'value' => null,
                'value_multiplier' => 1,
                'value_unit' => null,
            ]);
        }

        // FX breakdown (local currency vs transaction currency)
        $localAmount = $tx['local_amount'] ?? null;
        $localCurrency = $tx['local_currency'] ?? null;
        $txCurrency = $tx['currency'] ?? 'GBP';
        if ($localAmount !== null && $localCurrency && strtoupper((string) $localCurrency) !== strtoupper((string) $txCurrency)) {
            $gbp = abs(((int) ($tx['amount'] ?? 0)) / 100);
            $loc = abs(((int) $localAmount) / 100);
            $rate = $loc > 0 ? round($gbp / $loc, 6) : null;
            $content = 'Local: '.$loc.' '.strtoupper((string) $localCurrency).' → '.$gbp.' '.strtoupper((string) $txCurrency);
            if ($rate !== null) {
                $content .= ' (rate '.$rate.')';
            }
            $event->blocks()->create([
                'time' => $event->time,
                'integration_id' => $event->integration_id,
                'title' => 'FX',
                'content' => $content,
                'media_url' => null,
                'value' => null,
                'value_multiplier' => 1,
                'value_unit' => null,
            ]);
        }

        // Example block for virtual cards
        if (!empty($tx['virtual_card'])) {
            $vc = (array) $tx['virtual_card'];
            $event->blocks()->create([
                'time' => $event->time,
                'integration_id' => $event->integration_id,
                'title' => 'Virtual Card',
                'content' => 'Virtual card used',
                'media_url' => null,
                'value' => null,
                'value_multiplier' => 1,
                'value_unit' => null,
            ]);
        }

        $scheme = $tx['scheme'] ?? null;
        $amount = (int) ($tx['amount'] ?? 0);

        // Pot Transfers
        if ($scheme === 'uk_retail_pot') {
            $direction = $amount < 0 ? 'To' : 'From';
            $potName = null;
            // Try to resolve pot name from target
            try {
                $target = $event->target()->first();
                if ($target && $target->type === 'monzo_pot') {
                    $potName = $target->title;
                }
            } catch (\Throwable $e) {
                // ignore
            }
            $event->blocks()->create([
                'time' => $event->time,
                'integration_id' => $event->integration_id,
                'title' => 'Pot Transfer',
                'content' => trim(($direction.' '.($potName ?? 'Pot'))),
                'media_url' => null,
                'value' => abs($amount),
                'value_multiplier' => 100,
                'value_unit' => 'GBP',
            ]);
        }

        // Joint Account Transactions (detect by account type)
        $accountId = (string) ($tx['account_id'] ?? $tx['account'] ?? '');
        if ($accountId !== '' && $this->isJointAccount($event->integration_id, $accountId)) {
            $event->blocks()->create([
                'time' => $event->time,
                'integration_id' => $event->integration_id,
                'title' => 'Joint Account',
                'content' => 'Transaction on a joint account',
                'media_url' => null,
                'value' => null,
                'value_multiplier' => 1,
                'value_unit' => null,
            ]);
        }

        // External Account Transfers (Faster Payments / bank transfers)
        if ($scheme === 'payport_faster_payments') {
            $cp = (array) ($tx['counterparty'] ?? []);
            $details = [];
            if (!empty($cp['name'])) {
                $details[] = (string) $cp['name'];
            }
            if (!empty($cp['sort_code']) && !empty($cp['account_number'])) {
                $details[] = $cp['sort_code'].'-'.$cp['account_number'];
            }
            $content = !empty($details) ? implode(' • ', $details) : 'External transfer';
            $event->blocks()->create([
                'time' => $event->time,
                'integration_id' => $event->integration_id,
                'title' => 'Bank Transfer',
                'content' => $content,
                'media_url' => null,
                'value' => abs($amount),
                'value_multiplier' => 100,
                'value_unit' => 'GBP',
            ]);
        }
    }

    // Cache of account_id => type to avoid repeated HTTP calls per transaction
    private static array $accountTypeCache = [];

    private function isJointAccount(string $integrationId, string $accountId): bool
    {
        $cacheKey = $integrationId.':'.$accountId;
        if (array_key_exists($cacheKey, self::$accountTypeCache)) {
            return self::$accountTypeCache[$cacheKey] === 'uk_retail_joint';
        }
        // Find integration by id and call accounts API once per account id
        $integration = Integration::find($integrationId);
        if (!$integration) {
            self::$accountTypeCache[$cacheKey] = '';
            return false;
        }
        try {
            $resp = Http::withHeaders($this->authHeaders($integration))->get($this->apiBase.'/accounts');
            if ($resp->successful()) {
                $accounts = $resp->json('accounts') ?? [];
                foreach ($accounts as $acc) {
                    $type = (string) ($acc['type'] ?? '');
                    $id = (string) ($acc['id'] ?? '');
                    if ($id !== '') {
                        self::$accountTypeCache[$integrationId.':'.$id] = $type;
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return (self::$accountTypeCache[$cacheKey] ?? '') === 'uk_retail_joint';
    }

    public function upsertPotObject(Integration $integration, array $pot): EventObject
    {
        $master = $this->resolveMasterIntegration($integration);
        return EventObject::updateOrCreate(
            [
                'integration_id' => $master->id,
                'concept' => 'account',
                'type' => 'monzo_pot',
                'title' => $pot['name'] ?? 'Pot',
            ],
            [
                'time' => $pot['created'] ?? now(),
                'content' => (string) ($pot['balance'] ?? 0),
                'metadata' => [
                    'pot_id' => $pot['id'] ?? null,
                    'deleted' => (bool) ($pot['deleted'] ?? false),
                ],
                'url' => null,
                'media_url' => null,
            ]
        );
    }

    public function upsertAccountObject(Integration $integration, array $account): EventObject
    {
        $title = match ($account['type'] ?? null) {
            'uk_retail' => 'Current Account',
            'uk_retail_joint' => 'Joint Account',
            'uk_monzo_flex' => 'Monzo Flex',
            default => 'Monzo Account',
        };
        $master = $this->resolveMasterIntegration($integration);
        return EventObject::updateOrCreate(
            [
                'integration_id' => $master->id,
                'concept' => 'account',
                'type' => 'monzo_account',
                'title' => $title,
            ],
            [
                'time' => now(),
                'content' => null,
                'metadata' => [
                    'account_id' => $account['id'] ?? null,
                    'raw' => $account,
                ],
            ]
        );
    }

    public function convertData(array $externalData, Integration $integration): array
    {
        return [];
    }
}


