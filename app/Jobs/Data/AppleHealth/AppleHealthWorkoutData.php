<?php

namespace App\Jobs\Data\AppleHealth;

use App\Integrations\AppleHealth\AppleHealthPlugin;
use App\Jobs\Base\BaseProcessingJob;
use Illuminate\Support\Facades\Log;

class AppleHealthWorkoutData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'apple_health';
    }

    protected function getJobType(): string
    {
        return 'workouts';
    }

    protected function process(): void
    {
        $workout = $this->rawData;
        $plugin = new AppleHealthPlugin;

        Log::info('AppleHealthWorkoutData: Processing workout', [
            'integration_id' => $this->integration->id,
        ]);

        $eventData = $plugin->mapWorkoutToEvent($workout, $this->integration);

        if ($eventData) {
            $this->createEvents([$eventData]);
        }

        Log::info('AppleHealthWorkoutData: Completed processing workout', [
            'integration_id' => $this->integration->id,
        ]);
    }
}
