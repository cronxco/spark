<?php

namespace App\Jobs\Data\Monzo;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\EventObject;

class MonzoBalanceData extends BaseProcessingJob
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
        return 'balances';
    }

    protected function process(): void
    {
        $balanceData = $this->rawData;
        $account = $balanceData['_account'];
        unset($balanceData['_account']);

        $balance = (int) ($balanceData['balance'] ?? 0); // cents
        $spendToday = (int) ($balanceData['spend_today'] ?? 0); // cents
        $date = now()->toDateString();

        // Create or update the target "day" object (target_id is NOT NULL in events)
        $dayObject = EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'day',
                'type' => 'day',
                'title' => $date,
            ],
            [
                'integration_id' => $this->integration->id,
                'time' => $date . ' 00:00:00',
                'content' => null,
                'metadata' => ['date' => $date],
            ]
        );

        $event = Event::updateOrCreate(
            [
                'integration_id' => $this->integration->id,
                'source_id' => 'monzo_balance_' . $account['id'] . '_' . $date,
            ],
            [
                'time' => $date . ' 23:59:59',
                'actor_id' => $this->upsertAccountObject($account)->id,
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
        $this->addBalanceBlocks($event, $account, $date, $balance, $spendToday);
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
                'integration_id' => $this->integration->id,
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

    private function addBalanceBlocks(Event $event, array $account, string $date, int $balance, int $spendToday): void
    {
        // Spend Today block
        $event->blocks()->create([
            'time' => $event->time,
            'block_type' => 'spend_today',
            'title' => 'Spend Today',
            'metadata' => [],
            'media_url' => null,
            'value' => abs($spendToday),
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
        ]);

        // Balance Change vs previous day
        $actorId = $event->actor_id;
        $prev = Event::where('integration_id', $this->integration->id)
            ->where('service', 'monzo')
            ->where('action', 'had_balance')
            ->where('actor_id', $actorId)
            ->where('time', '<', $date . ' 00:00:00')
            ->orderBy('time', 'desc')
            ->first();

        if ($prev) {
            $prevVal = (int) abs($prev->value ?? 0);
            $currentVal = (int) abs($balance);
            $delta = $currentVal - $prevVal; // cents

            if ($delta !== 0) {
                $event->blocks()->create([
                    'time' => $event->time,
                    'block_type' => 'balance_change',
                    'title' => 'Balance Change',
                    'metadata' => ['text' => $delta > 0 ? 'Up' : 'Down'],
                    'media_url' => null,
                    'value' => abs($delta),
                    'value_multiplier' => 100,
                    'value_unit' => 'GBP',
                ]);
            }
        }
    }
}
