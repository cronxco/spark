<?php

namespace App\Jobs\OAuth\Oura;

use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Oura\OuraRestModePeriodData;

class OuraRestModePeriodPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'rest_mode_period';
    }

    protected function fetchData(): array
    {
        $plugin = $this->getPlugin();
        $daysBack = (int) ($this->integration->configuration['days_back'] ?? 14); // Rest periods may be longer
        $startDate = now()->subDays($daysBack)->toDateString();
        $endDate = now()->toDateString();

        $data = $plugin->getJson('/usercollection/rest_mode_period', $this->integration, [
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

        OuraRestModePeriodData::dispatch($this->integration, $rawData);
    }
}
