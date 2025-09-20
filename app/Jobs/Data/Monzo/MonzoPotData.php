<?php

namespace App\Jobs\Data\Monzo;

use App\Integrations\Monzo\MonzoPlugin;
use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Support\Facades\Log;

class MonzoPotData extends BaseProcessingJob
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
        return 'pots';
    }

    protected function process(): void
    {
        $pots = $this->rawData;
        $plugin = new MonzoPlugin;
        $date = now()->toDateString();

        Log::info('MonzoPotData: Processing pot data', [
            'integration_id' => $this->integration->id,
            'pot_count' => count($pots),
        ]);

        // Create or update the target "day" object for pot balance events
        $dayObject = EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
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

        foreach ($pots as $pot) {
            // Upsert the pot object
            $potObject = $plugin->upsertPotObject($this->integration, $pot);

            // Create balance event for the pot
            $balance = (int) ($pot['balance'] ?? 0); // Monzo API returns balance in pence
            $sourceId = 'monzo_pot_balance_' . $pot['id'] . '_' . $date;

            Event::updateOrCreate(
                [
                    'integration_id' => $this->integration->id,
                    'source_id' => $sourceId,
                ],
                [
                    'time' => $date . ' 23:59:59',
                    'actor_id' => $potObject->id,
                    'service' => 'monzo',
                    'domain' => 'money',
                    'action' => 'had_balance',
                    'value' => abs($balance), // integer pence
                    'value_multiplier' => 100, // 100 pence = £1
                    'value_unit' => 'GBP',
                    'event_metadata' => [
                        'pot_id' => $pot['id'] ?? null,
                        'pot_name' => $pot['name'] ?? 'Pot',
                        'snapshot_date' => $date,
                    ],
                    'target_id' => $dayObject->id,
                ]
            );
        }

        Log::info('MonzoPotData: Completed processing pot data', [
            'integration_id' => $this->integration->id,
        ]);
    }
}
