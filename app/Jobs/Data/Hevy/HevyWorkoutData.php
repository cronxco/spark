<?php

namespace App\Jobs\Data\Hevy;

use App\Integrations\Hevy\HevyPlugin;
use App\Jobs\Base\BaseProcessingJob;
use Illuminate\Support\Facades\Log;

class HevyWorkoutData extends BaseProcessingJob
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
        return 'hevy';
    }

    protected function getJobType(): string
    {
        return 'workout';
    }

    protected function process(): void
    {
        $workouts = $this->normalizeWorkouts($this->rawData);
        $plugin = new HevyPlugin;

        Log::info('Hevy: Processing workout data', [
            'integration_id' => $this->integration->id,
            'workout_count' => count($workouts),
        ]);

        foreach ($workouts as $workout) {
            if (! is_array($workout)) {
                Log::warning('Hevy: Skipping non-array workout item', [
                    'integration_id' => $this->integration->id,
                    'type' => gettype($workout),
                ]);

                continue;
            }

            $plugin->createWorkoutEvent($this->integration, $workout);
        }

        Log::info('Hevy: Completed processing workout data', [
            'integration_id' => $this->integration->id,
        ]);
    }

    private function normalizeWorkouts(array $rawData): array
    {
        $items = [];
        if (is_array($rawData)) {
            if (isset($rawData['data']) && is_array($rawData['data'])) {
                $items = $rawData['data'];
            } elseif (isset($rawData['workouts']) && is_array($rawData['workouts'])) {
                $items = $rawData['workouts'];
            } elseif (array_is_list($rawData)) {
                $items = $rawData;
            }
        }

        return $items;
    }
}
