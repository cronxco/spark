<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Actions\DispatchIntegrationFetchJobs;
use App\Jobs\TaskPipeline\BaseTaskJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Runs a single due integration's scheduled fetch, dispatched by
 * CheckIntegrationUpdates. Pause/processing/throttle state is checked via the
 * task's `shouldRun` condition, so a skipped integration is recorded as
 * not_applicable rather than a dispatched job that immediately no-ops.
 *
 * Unique per integration: CheckIntegrationUpdates can queue more than one
 * ProcessTaskPipelineJob for the same integration if the single-worker
 * `tasks` queue has backlog spanning its per-minute schedule tick (the
 * isProcessing() guard only becomes true once this job actually runs and
 * calls markAsTriggered()). This lock blocks a duplicate dispatch for as
 * long as an instance of this job is sitting unprocessed in the queue or
 * actively running - the exact backlog scenario above - which is where a
 * real duplicate DispatchIntegrationFetchJobs call would otherwise happen.
 * Laravel releases the lock as soon as an attempt finishes (success or
 * failure), not after all retries are exhausted, so it doesn't cover the
 * brief gap between a failed attempt and its retry; `uniqueFor` is a safety
 * TTL in case the lock is never released cleanly (e.g. a crashed worker),
 * not the intended hold duration.
 */
class RunIntegrationUpdateTask extends BaseTaskJob implements ShouldBeUnique
{
    public $uniqueFor = 1200;

    public function uniqueId(): string
    {
        return (string) $this->model->getKey();
    }

    protected function execute(): void
    {
        (new DispatchIntegrationFetchJobs)->dispatch($this->model);

        $this->model->markAsTriggered();
    }
}
