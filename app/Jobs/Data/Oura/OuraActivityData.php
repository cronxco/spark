<?php

namespace App\Jobs\Data\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseProcessingJob;
use Illuminate\Support\Facades\Log;

class OuraActivityData extends BaseProcessingJob
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
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'activity';
    }

    protected function process(): void
    {
        $activityItems = $this->rawData;
        $plugin = new OuraPlugin;

        if (empty($activityItems)) {
            return;
        }

        Log::info('OuraActivityData: Processing activity data', [
            'integration_id' => $this->integration->id,
            'activity_count' => count($activityItems),
        ]);

        foreach ($activityItems as $item) {
            $plugin->createDailyRecordEvent($this->integration, 'activity', $item, [
                'score_field' => 'score',
                'contributors_field' => 'contributors',
                'title' => 'Activity',
                'value_unit' => 'percent',
                'contributors_value_unit' => 'percent',
                'details_fields' => [
                    'steps', 'cal_total', 'equivalent_walking_distance', 'target_calories', 'non_wear_time',
                ],
            ]);
        }

        Log::info('OuraActivityData: Completed processing activity data', [
            'integration_id' => $this->integration->id,
        ]);
    }
}
