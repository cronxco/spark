<?php

namespace Tests\Feature\TaskPipeline;

use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Event;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessTaskPipelineJobQueueContractTest extends TestCase
{
    #[Test]
    public function job_is_deferred_until_the_dispatching_transaction_commits(): void
    {
        // Model `created`/`updated` hooks dispatch this job from inside their
        // caller's open transaction. The redis queue connection defaults to
        // after_commit=false, so without this contract Horizon can grab the
        // job — and fail to restore the not-yet-committed model — before the
        // transaction commits.
        $this->assertFalse(config('queue.connections.redis.after_commit'));

        $job = new ProcessTaskPipelineJob(Event::factory()->make());

        $this->assertInstanceOf(ShouldQueueAfterCommit::class, $job);
    }
}
