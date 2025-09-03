<?php

namespace App\Jobs\Data\GoCardless;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Support\Facades\Log;

class GoCardlessBalanceData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'gocardless';
    }

    protected function getJobType(): string
    {
        return 'balances';
    }

    protected function process(): void
    {
        $balanceData = $this->rawData;

        // Extract balances from the API response
        $balances = $balanceData['balances'] ?? [];

        if (empty($balances)) {
            return;
        }

        // Process balances for each account (this would be enhanced with proper account data in real implementation)
        $this->processBalances($balances);
    }

    /**
     * Create balance event with full GoCardless logic
     */
    protected function createBalanceEvent(array $balance): void
    {
        $balanceReferenceDate = $balance['referenceDate'] ?? now()->toDateString();
        $balanceType = $balance['balanceType'] ?? 'unknown';
        $accountId = $this->integration->group->account_id ?? 'unknown';

        $sourceId = 'balance_' . $accountId . '_' . $balanceReferenceDate;

        // Create or update actor (bank account)
        $accountObject = $this->upsertAccountObject($balance);

        // Create day target
        $dayObject = EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'day',
                'type' => 'day',
                'title' => $balanceReferenceDate,
            ],
            [
                'time' => $balanceReferenceDate . ' 00:00:00',
                'content' => null,
                'metadata' => ['date' => $balanceReferenceDate],
            ]
        );

        $eventData = [
            'user_id' => $this->integration->user_id,
            'action' => 'had_balance',
            'domain' => 'money',
            'service' => 'gocardless',
            'time' => $balance['referenceDate'] ?? now(),
            'value' => abs((float) ($balance['balanceAmount']['amount'] ?? 0)),
            'value_multiplier' => 100,
            'value_unit' => $balance['balanceAmount']['currency'] ?? 'EUR',
            'actor_id' => $accountObject->id, // Set directly (original simple design)
            'target_id' => $dayObject->id, // Set directly (original simple design)
            'event_metadata' => [
                'balance_type' => $balanceType,
                'reference_date' => $balanceReferenceDate,
                'account_id' => $accountId,
                'last_change_date_time' => $balance['lastChangeDateTime'] ?? null,
                'last_committed_transaction' => $balance['lastCommittedTransaction'] ?? null,
                'credit_limit_included' => $balance['creditLimitIncluded'] ?? false,
            ],
        ];

        Log::info('GoCardless createBalanceEvent: creating balance event', [
            'integration_id' => $this->integration->id,
            'account_id' => $accountId,
            'source_id' => $sourceId,
            'balance_type' => $balanceType,
            'balance_amount' => $balance['balanceAmount']['amount'] ?? 'unknown',
            'balance_currency' => $balance['balanceAmount']['currency'] ?? 'unknown',
            'event_data' => $eventData,
        ]);

        $event = Event::updateOrCreate(
            [
                'integration_id' => $this->integration->id,
                'source_id' => $sourceId,
            ],
            $eventData
        );

        Log::info('GoCardless createBalanceEvent: balance event created/updated', [
            'integration_id' => $this->integration->id,
            'event_id' => $event->id,
            'source_id' => $sourceId,
        ]);

        // Create or update actor (bank account)
        $accountObject = $this->upsertAccountObject($balance);

        // Create day target
        $dayObject = EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'day',
                'type' => 'day',
                'title' => $balanceReferenceDate,
            ],
            [
                'time' => $balanceReferenceDate . ' 00:00:00',
                'content' => null,
                'metadata' => ['date' => $balanceReferenceDate],
            ]
        );

        // Add tags
        $event->syncTags([
            'money',
            'balance',
            'bank',
            'gocardless',
            $balanceType,
        ]);

        // Add balance blocks for additional data
        $this->addBalanceBlocks($event, $balance);
    }

    /**
     * Add balance blocks for additional metadata
     */
    protected function addBalanceBlocks(Event $event, array $balance): void
    {
        // Balance type information block
        $event->blocks()->create([
            'time' => $event->time,
            'block_type' => 'balance_info',
            'title' => 'Balance Information',
            'metadata' => [
                'balance_type' => $balance['balanceType'] ?? 'unknown',
                'reference_date' => $balance['referenceDate'] ?? null,
                'last_change' => $balance['lastChangeDateTime'] ?? null,
                'last_committed_transaction' => $balance['lastCommittedTransaction'] ?? null,
                'credit_limit_included' => $balance['creditLimitIncluded'] ?? false,
            ],
            'value' => null,
            'value_multiplier' => 1,
            'value_unit' => null,
        ]);

        // Add balance change comparison if available
        $this->addBalanceChangeBlock($event, $balance);
    }

    /**
     * Add balance change block comparing to previous balance
     */
    protected function addBalanceChangeBlock(Event $event, array $balance): void
    {
        $currentAmount = (float) ($balance['balanceAmount']['amount'] ?? 0);
        $currency = $balance['balanceAmount']['currency'] ?? 'EUR';
        $referenceDate = $balance['referenceDate'] ?? now()->toDateString();

        // Find previous balance event for this account
        $previousEvent = Event::where('integration_id', $this->integration->id)
            ->where('service', 'gocardless')
            ->where('action', 'had_balance')
            ->where('time', '<', $referenceDate . ' 00:00:00')
            ->orderBy('time', 'desc')
            ->first();

        if (! $previousEvent) {
            return;
        }

        $previousAmount = (float) ($previousEvent->value / 100); // Convert from cents
        $change = $currentAmount - $previousAmount;

        if (abs($change) < 0.01) { // Ignore very small changes
            return;
        }

        $event->blocks()->create([
            'time' => $event->time,
            'block_type' => 'balance_change',
            'title' => 'Balance Change',
            'metadata' => [
                'change_type' => $change > 0 ? 'increase' : 'decrease',
                'previous_balance' => $previousAmount,
                'current_balance' => $currentAmount,
                'change_amount' => $change,
                'text' => $change > 0 ? 'Balance increased' : 'Balance decreased',
            ],
            'value' => (int) abs($change * 100), // Convert to cents
            'value_multiplier' => 100,
            'value_unit' => $currency,
        ]);
    }

    /**
     * Upsert account object for balance events
     */
    protected function upsertAccountObject(array $balance): EventObject
    {
        // This is a simplified version - in practice we'd have account data from the balance API
        $accountId = $this->integration->group->account_id ?? 'unknown';
        $accountName = 'Bank Account'; // Would be derived from account data in real implementation

        // First, try to find an existing onboarding-created account object
        $onboardingIntegrationId = 'onboarding_' . $this->integration->group_id . '_' . $accountId;

        $existingObject = EventObject::where('user_id', $this->integration->user_id)
            ->where('concept', 'account')
            ->where('type', 'bank_account')
            ->where('title', $accountName)
            ->whereJsonContains('metadata->integration_id', $onboardingIntegrationId)
            ->first();

        if ($existingObject) {
            // Update the integration ID to point to the real integration
            $existingObject->update([
                'metadata->integration_id' => $this->integration->id,
            ]);

            return $existingObject;
        }

        // Create new account object
        return EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'account',
                'type' => 'bank_account',
                'title' => $accountName,
            ],
            [
                'content' => json_encode(['account_id' => $accountId]),
                'metadata' => [
                    'integration_id' => $this->integration->id,
                    'name' => $accountName,
                    'provider' => 'GoCardless',
                    'account_type' => 'current_account',
                    'currency' => $balance['balanceAmount']['currency'] ?? 'EUR',
                    'account_number' => $accountId,
                ],
            ]
        );
    }

    private function processBalances(array $balances): void
    {
        foreach ($balances as $balance) {
            Log::info('GoCardless processBalances: processing balance', [
                'integration_id' => $this->integration->id,
                'balance_type' => $balance['balanceType'] ?? 'unknown',
                'balance_amount' => $balance['balanceAmount']['amount'] ?? 'unknown',
            ]);

            $this->createBalanceEvent($balance);
        }
    }
}
