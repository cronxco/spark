<?php

namespace App\Jobs\Data\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseProcessingJob;
use Illuminate\Support\Facades\Log;

class OuraReadinessData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'readiness';
    }

    protected function process(): void
    {
        $readinessItems = $this->rawData;
        $plugin = new OuraPlugin;

        if (empty($readinessItems)) {
            return;
        }

        Log::info('OuraReadinessData: Processing readiness data', [
            'integration_id' => $this->integration->id,
            'readiness_count' => count($readinessItems),
        ]);

        foreach ($readinessItems as $item) {
            $plugin->createDailyRecordEvent($this->integration, 'readiness', $item, [
                'score_field' => 'score',
                'contributors_field' => 'contributors',
                'title' => 'Readiness',
                'value_unit' => 'percent',
                'contributors_value_unit' => 'percent',
            ]);
        }

        Log::info('OuraReadinessData: Completed processing readiness data', [
            'integration_id' => $this->integration->id,
        ]);
    }
}
