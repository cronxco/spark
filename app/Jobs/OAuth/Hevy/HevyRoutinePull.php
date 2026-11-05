<?php

namespace App\Jobs\OAuth\Hevy;

use App\Integrations\Hevy\HevyPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Hevy\HevyRoutineData;
use Illuminate\Support\Facades\Log;

class HevyRoutinePull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'hevy';
    }

    protected function getJobType(): string
    {
        return 'routine';
    }

    protected function fetchData(): array
    {
        $plugin = new HevyPlugin;

        return $plugin->pullRoutineData($this->integration);
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        $routines = $rawData['routines'] ?? [];

        if (empty($routines)) {
            Log::info('Hevy: No routine data to process', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        HevyRoutineData::dispatch($this->integration, $rawData);
    }
}
