<?php

namespace App\Jobs\Data\Monzo;

use App\Integrations\Monzo\MonzoPlugin;
use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Support\Facades\Log;

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
        $plugin = new MonzoPlugin;

        $account = $balanceData['_account'];
        unset($balanceData['_account']);

        $balance = (int) ($balanceData['balance'] ?? 0); // cents
        $spendToday = (int) ($balanceData['spend_today'] ?? 0); // cents
        $date = now()->toDateString();

        Log::info('MonzoBalanceData: Processing balance data', [
            'integration_id' => $this->integration->id,
            'account_id' => $this->accountId,
            'balance' => $balance,
            'spend_today' => $spendToday,
        ]);

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
                'actor_id' => $plugin->upsertAccountObject($this->integration, $account)->id,
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
        $plugin->addBalanceBlocks($event, $this->integration, $account, $date, $balance, $spendToday);

        Log::info('MonzoBalanceData: Completed processing balance data', [
            'integration_id' => $this->integration->id,
        ]);
    }
}
