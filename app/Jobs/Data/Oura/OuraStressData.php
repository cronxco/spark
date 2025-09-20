<?php

namespace App\Jobs\Data\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseProcessingJob;
use Illuminate\Support\Facades\Log;

class OuraStressData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'stress';
    }

    protected function process(): void
    {
        $stressItems = $this->rawData;
        $plugin = new OuraPlugin;

        if (empty($stressItems)) {
            return;
        }

        Log::info('OuraStressData: Processing stress data', [
            'integration_id' => $this->integration->id,
            'stress_count' => count($stressItems),
        ]);

        foreach ($stressItems as $item) {
            $plugin->createDailyRecordEvent($this->integration, 'stress', $item, [
                'score_field' => 'stress_score',
                'contributors_field' => 'contributors',
                'title' => 'Stress',
                'value_unit' => 'percent',
                'contributors_value_unit' => 'percent',
            ]);
        }

        Log::info('OuraStressData: Completed processing stress data', [
            'integration_id' => $this->integration->id,
        ]);
    }
}
