<?php

namespace App\Jobs\Data\Monzo;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MonzoTransactionData extends BaseProcessingJob
{
    protected string $accountId;

    public function __construct($integration, array $rawData, string $accountId)
    {
        parent::__construct($integration, $rawData);
        $this->accountId = $accountId;
    }

    protected function getServiceName(): string
    {
        return 'monzo';
    }

    protected function getJobType(): string
    {
        return 'transactions';
    }

    protected function process(): void
    {
        $transactions = $this->rawData;

        if (empty($transactions)) {
            return;
        }

        foreach ($transactions as $tx) {
            try {
                $this->processTransactionItem($tx);
            } catch (Exception $e) {
                Log::error('Failed to process Monzo transaction', [
                    'transaction_id' => $tx['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                    'integration_id' => $this->integration->id,
                ]);
                // Continue processing other transactions
            }
        }
    }

    private function processTransactionItem(array $tx): void
    {
        $actor = $this->upsertAccountObject($this->getAccountData());
        $master = $this->resolveMasterIntegration();

        // If counterparty matches a known pot id, link to the pot account object
        $counterpartyId = $tx['counterparty']['account_id'] ?? $tx['counterparty']['id'] ?? $tx['counterparty'] ?? null;
        $target = null;
        if ($counterpartyId) {
            $target = EventObject::where('user_id', $this->integration->user_id)
                ->where('concept', 'account')
                ->where('type', 'monzo_pot')
                ->whereJsonContains('metadata->pot_id', $counterpartyId)
                ->first();
        }

        if (! $target) {
            // Fallback: create/find generic counterparty
            $targetTitle = $tx['merchant']['name'] ?? ($tx['description'] ?? 'Unknown');
            $target = EventObject::updateOrCreate(
                [
                    'user_id' => $this->integration->user_id,
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

        $action = $this->setAction($tx);

        $event = Event::updateOrCreate(
            [
                'integration_id' => $this->integration->id,
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
                    'raw' => $tx,
                ],
                'target_id' => $target->id,
            ]
        );

        $this->tagTransactionEvent($event, $tx);
        $this->maybeAddTransactionBlocks($event, $tx);
    }

    private function upsertAccountObject(array $account): EventObject
    {
        $title = match ($account['type'] ?? null) {
            'uk_retail' => 'Current Account',
            'uk_retail_joint' => 'Joint Account',
            'uk_monzo_flex' => 'Monzo Flex',
            'uk_prepaid' => 'Monzo OG',
            'uk_reward_account' => 'Monzo Rewards',
            default => 'Monzo Account',
        };

        $accountType = match ($account['type'] ?? null) {
            'uk_retail' => 'current_account',
            'uk_retail_joint' => 'current_account',
            'uk_monzo_flex' => 'credit_card',
            'uk_prepaid' => 'current_account',
            'uk_reward_account' => 'savings_account',
            default => 'other',
        };

        return EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'account',
                'type' => 'monzo_account',
                'title' => $title,
            ],
            [
                'time' => now(),
                'content' => null,
                'metadata' => [
                    'name' => $title,
                    'provider' => 'Monzo',
                    'account_type' => $accountType,
                    'account_id' => $account['id'] ?? null,
                    'currency' => $account['currency'] ?? 'GBP',
                    'raw' => $account,
                ],
            ]
        );
    }

    private function getAccountData(): array
    {
        // This would need to be passed or fetched, for now return basic data
        return [
            'id' => $this->accountId,
            'type' => 'uk_retail', // Default assumption
            'currency' => 'GBP',
        ];
    }

    private function resolveMasterIntegration()
    {
        $group = $this->integration->group;
        $master = Integration::where('integration_group_id', $group->id)
            ->where('service', 'monzo')
            ->where('instance_type', 'accounts')
            ->first();

        if (! $master) {
            // Create master instance if it doesn't exist
            $master = Integration::create([
                'user_id' => $this->integration->user_id,
                'integration_group_id' => $group->id,
                'service' => 'monzo',
                'name' => 'Accounts (Master)',
                'instance_type' => 'accounts',
                'configuration' => [],
            ]);
        }

        return $master;
    }

    private function setAction(array $tx): string
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
        if (! empty($tx['category'])) {
            $event->attachTag((string) $tx['category']);
        }
        // Debit/Credit tag
        $amount = (int) ($tx['amount'] ?? 0);
        $event->attachTag($amount < 0 ? 'debit' : 'credit');
        // Scheme tag
        if (! empty($tx['scheme'])) {
            $event->attachTag((string) $tx['scheme']);
        }
        // Currency tag
        if (! empty($tx['local_currency'])) {
            $event->attachTag((string) $tx['local_currency']);
        }
        // Merchant emoji/country/category
        if (! empty($tx['merchant']['emoji'])) {
            $event->attachTag((string) $tx['merchant']['emoji']);
        }
        if (! empty($tx['merchant']['address']['country'])) {
            $event->attachTag((string) $tx['merchant']['address']['country']);
        }
        if (! empty($tx['merchant']['category'])) {
            $event->attachTag((string) $tx['merchant']['category']);
        }
        // Decline / settled
        if ((int) ($tx['declined'] ?? 0) === 1) {
            $event->attachTag('declined');
            if (! empty($tx['decline_reason'])) {
                $event->attachTag((string) $tx['decline_reason']);
            }
        } elseif ((int) ($tx['pending'] ?? 0) !== 1) {
            $event->attachTag('settled');
        }
    }

    private function maybeAddTransactionBlocks(Event $event, array $tx): void
    {
        // Merchant details
        if (! empty($tx['merchant'])) {
            $m = (array) $tx['merchant'];
            $parts = [];
            if (! empty($m['name'])) {
                $parts['merchant'] = (string) $m['name'];
            }
            if (! empty($m['category'])) {
                $parts['category'] = (string) $m['category'];
            }
            if (! empty($m['address'])) {
                $addr = (array) $m['address'];
                $addrLine = implode(', ', array_values(array_filter([
                    $addr['address'] ?? null,
                    $addr['city'] ?? null,
                    $addr['postcode'] ?? null,
                    $addr['country'] ?? null,
                ])));
                if ($addrLine !== '') {
                    $parts['address'] = $addrLine;
                }
            }
            $event->blocks()->create([
                'time' => $event->time,
                'title' => 'Merchant',
                'metadata' => $parts,
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
            $metadata = [
                strtoupper((string) $localCurrency) => $loc,
                strtoupper((string) $txCurrency) => $gbp,
            ];
            if ($rate !== null) {
                $metadata['rate'] = $rate;
            }
            $event->blocks()->create([
                'time' => $event->time,
                'block_type' => 'foreign_exchange',
                'title' => 'FX',
                'metadata' => $metadata,
                'media_url' => null,
                'value' => null,
                'value_multiplier' => 1,
                'value_unit' => null,
            ]);
        }

        // Example block for virtual cards
        if (! empty($tx['virtual_card'])) {
            $vc = (array) $tx['virtual_card'];
            $event->blocks()->create([
                'time' => $event->time,
                'block_type' => 'virtual_card',
                'title' => 'Virtual Card',
                'metadata' => $vc,
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
            } catch (Exception $e) {
                // ignore
            }
            $event->blocks()->create([
                'time' => $event->time,
                'block_type' => 'pot',
                'title' => 'Pot Transfer',
                'metadata' => ['direction' => $direction, 'pot_name' => $potName ?? 'Pot'],
                'media_url' => null,
                'value' => abs($amount),
                'value_multiplier' => 100,
                'value_unit' => 'GBP',
            ]);
        }

        // External Account Transfers (Faster Payments / bank transfers)
        if ($scheme === 'payport_faster_payments') {
            $cp = (array) ($tx['counterparty'] ?? []);
            $details = [];
            if (! empty($cp['name'])) {
                $details['counterparty'] = (string) $cp['name'];
            }
            if (! empty($cp['sort_code']) && ! empty($cp['account_number'])) {
                $details['sort_code'] = $cp['sort_code'];
                $details['account_number'] = $cp['account_number'];
            }
            $event->blocks()->create([
                'time' => $event->time,
                'block_type' => 'bank_transfer',
                'title' => 'Bank Transfer',
                'metadata' => $details,
                'media_url' => null,
                'value' => abs($amount),
                'value_multiplier' => 100,
                'value_unit' => 'GBP',
            ]);
        }
    }
}
