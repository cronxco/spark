<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Actions\DispatchIntegrationFetchJobs;
use App\Jobs\TaskPipeline\BaseTaskJob;

/**
 * Runs a single due integration's scheduled fetch, dispatched by
 * CheckIntegrationUpdates. Pause/processing/throttle state is checked via the
 * task's `shouldRun` condition, so a skipped integration is recorded as
 * not_applicable rather than a dispatched job that immediately no-ops.
 */
class RunIntegrationUpdateTask extends BaseTaskJob
{
    protected function execute(): void
    {
        (new DispatchIntegrationFetchJobs)->dispatch($this->model);

        $this->model->markAsTriggered();
    }
}
