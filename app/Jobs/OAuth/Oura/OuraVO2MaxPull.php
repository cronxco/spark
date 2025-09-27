<?php

namespace App\Jobs\OAuth\Oura;

use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Oura\OuraVO2MaxData;

class OuraVO2MaxPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'vo2_max';
    }

    protected function fetchData(): array
    {
        $plugin = $this->getPlugin();
        $daysBack = (int) ($this->integration->configuration['days_back'] ?? 30); // VO2 max is less frequent
        $startDate = now()->subDays($daysBack)->toDateString();
        $endDate = now()->toDateString();

        $data = $plugin->getJson('/usercollection/vO2_max', $this->integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        return $data['data'] ?? [];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        OuraVO2MaxData::dispatch($this->integration, $rawData);
    }
}
