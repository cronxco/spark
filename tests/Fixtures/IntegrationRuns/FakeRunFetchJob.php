<?php

namespace Tests\Fixtures\IntegrationRuns;

use App\Jobs\Base\BaseFetchJob;

/**
 * A fetch job that returns canned items and dispatches one processing job
 * per item, recording the run status it saw while fetching.
 */
class FakeRunFetchJob extends BaseFetchJob
{
    /**
     * @var array<int, string|null>
     */
    public static array $statusesSeenWhileFetching = [];

    protected function getServiceName(): string
    {
        return 'github';
    }

    protected function getJobType(): string
    {
        return 'fake_run';
    }

    protected function fetchData(): array
    {
        self::$statusesSeenWhileFetching[] = $this->integration->fresh()->configuration['last_run']['status'] ?? null;

        return ['first', 'second'];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        foreach ($rawData as $item) {
            FakeRunProcessingJob::dispatch($this->integration, [$item]);
        }
    }
}
