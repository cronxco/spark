<?php

namespace App\Jobs\OAuth\Oura;

use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Oura\OuraSleepTimeData;

class OuraSleepTimePull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'sleep_time';
    }

    protected function fetchData(): array
    {
        $plugin = $this->getPlugin();
        $daysBack = (int) ($this->integration->configuration['days_back'] ?? 7);
        $startDate = now()->subDays($daysBack)->toDateString();
        $endDate = now()->toDateString();

        $data = $plugin->getJson('/usercollection/sleep_time', $this->integration, [
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

        OuraSleepTimeData::dispatch($this->integration, $rawData);
    }
}
