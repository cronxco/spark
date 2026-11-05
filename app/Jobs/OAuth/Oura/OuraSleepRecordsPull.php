<?php

namespace App\Jobs\OAuth\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Oura\OuraSleepRecordsData;

class OuraSleepRecordsPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'oura';
    }

    protected function getJobType(): string
    {
        return 'sleep_records';
    }

    protected function fetchData(): array
    {
        $plugin = new OuraPlugin;

        return $plugin->pullSleepRecordsData($this->integration);
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData)) {
            return;
        }

        // Dispatch sleep records processing job with all sleep record data
        OuraSleepRecordsData::dispatch($this->integration, $rawData);
    }
}
