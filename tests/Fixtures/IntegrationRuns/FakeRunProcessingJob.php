<?php

namespace Tests\Fixtures\IntegrationRuns;

use App\Jobs\Base\BaseProcessingJob;

/**
 * A processing job that does nothing, for driving integration runs in tests.
 */
class FakeRunProcessingJob extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'github';
    }

    protected function getJobType(): string
    {
        return 'fake_run';
    }

    protected function process(): void {}
}
