<?php

namespace App\Jobs\Data\Monzo;

use App\Integrations\Monzo\MonzoPlugin;
use App\Jobs\Base\BaseProcessingJob;
use App\Models\EventObject;
use Illuminate\Support\Facades\Log;

class MonzoAccountData extends BaseProcessingJob
{
    public function getIntegration()
    {
        return $this->integration;
    }

    public function getRawData()
    {
        return $this->rawData;
    }

    protected function getServiceName(): string
    {
        return 'monzo';
    }

    protected function getJobType(): string
    {
        return 'accounts';
    }

    protected function process(): void
    {
        $plugin = new MonzoPlugin;

        Log::info('MonzoAccountData: Processing account data', [
            'integration_id' => $this->integration->id,
        ]);

        // For accounts, we only need to upsert the account objects
        // Events are created by transaction/balance processing jobs
        $plugin->upsertAccountObject($this->integration, $this->rawData);

        // Also create day object for balance events if it doesn't exist
        $this->createDayObject();

        Log::info('MonzoAccountData: Completed processing account data', [
            'integration_id' => $this->integration->id,
        ]);
    }

    private function createDayObject(): void
    {
        $date = now()->toDateString();

        EventObject::updateOrCreate(
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
    }
}
