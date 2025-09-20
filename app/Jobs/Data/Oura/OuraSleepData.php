<?php

namespace App\Jobs\Data\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseProcessingJob;
use Illuminate\Support\Facades\Log;

class OuraSleepData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'sleep';
    }

    protected function process(): void
    {
        $sleepItems = $this->rawData;
        $plugin = new OuraPlugin;

        if (empty($sleepItems)) {
            return;
        }

        Log::info('OuraSleepData: Processing sleep data', [
            'integration_id' => $this->integration->id,
            'sleep_count' => count($sleepItems),
        ]);

        foreach ($sleepItems as $item) {
            $plugin->createDailyRecordEvent($this->integration, 'sleep', $item, [
                'score_field' => 'score',
                'contributors_field' => 'contributors',
                'title' => 'Sleep',
                'value_unit' => 'percent',
            ]);
        }

        Log::info('OuraSleepData: Completed processing sleep data', [
            'integration_id' => $this->integration->id,
        ]);
    }
}
