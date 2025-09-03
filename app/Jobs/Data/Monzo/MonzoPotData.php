<?php

namespace App\Jobs\Data\Monzo;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;

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
        $date = now()->toDateString();

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
            $potObject = $this->upsertPotObject($pot);

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
    }

    private function upsertPotObject(array $pot): EventObject
    {
        $master = $this->resolveMasterIntegration();

        // Determine account type based on deletion status
        $isDeleted = (bool) ($pot['deleted'] ?? false);
        $accountType = $isDeleted ? 'monzo_archived_pot' : 'monzo_pot';

        return EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'account',
                'type' => $accountType,
                'title' => $pot['name'] ?? 'Pot',
            ],
            [
                'time' => $pot['created'] ?? now(),
                'content' => (string) ($pot['balance'] ?? 0),
                'metadata' => [
                    'name' => $pot['name'] ?? 'Pot',
                    'provider' => 'Monzo',
                    'account_type' => 'savings_account',
                    'pot_id' => $pot['id'] ?? null,
                    'deleted' => $isDeleted,
                    'currency' => 'GBP',
                ],
                'url' => null,
                'media_url' => null,
            ]
        );
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
}
