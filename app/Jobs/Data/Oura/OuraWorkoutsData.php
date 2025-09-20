<?php

namespace App\Jobs\Data\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseProcessingJob;
use Illuminate\Support\Facades\Log;

class OuraWorkoutsData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'workouts';
    }

    protected function process(): void
    {
        $workouts = $this->rawData;
        $plugin = new OuraPlugin;

        if (empty($workouts)) {
            return;
        }

        Log::info('OuraWorkoutsData: Processing workouts', [
            'integration_id' => $this->integration->id,
            'workout_count' => count($workouts),
        ]);

        foreach ($workouts as $item) {
            $plugin->createWorkoutEvent($this->integration, $item);
        }

        Log::info('OuraWorkoutsData: Completed processing workouts', [
            'integration_id' => $this->integration->id,
        ]);
    }
}
