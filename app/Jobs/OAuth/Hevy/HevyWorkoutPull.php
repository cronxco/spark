<?php

namespace App\Jobs\OAuth\Hevy;

use App\Integrations\Hevy\HevyPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Hevy\HevyWorkoutData;
use Illuminate\Support\Facades\Log;

class HevyWorkoutPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'hevy';
    }

    protected function getJobType(): string
    {
        return 'workout';
    }

    protected function fetchData(): array
    {
        $plugin = new HevyPlugin;

        return $plugin->pullWorkoutData($this->integration);
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData['data'] ?? [])) {
            Log::info('Hevy: No workout data to process', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        Log::info('Hevy: Dispatching workout processing job', [
            'integration_id' => $this->integration->id,
            'workout_count' => count($rawData['data']),
        ]);

        HevyWorkoutData::dispatch($this->integration, $rawData);
    }
}
