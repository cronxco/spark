<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\TriggerFlintDigestRoutineJob;
use App\Jobs\Flint\TriggerFlintRoutineJob;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QueueContractTest extends TestCase
{
    #[Test]
    public function flint_timeout_ordering_prevents_overlapping_attempts(): void
    {
        $user = User::factory()->create();
        $digest = new TriggerFlintDigestRoutineJob($user, 'morning', '2026-08-31', 'UTC', 'scheduled');
        $routine = new TriggerFlintRoutineJob($user, 'topics', '2026-08-31', 'UTC');
        $horizonTimeout = (int) config('horizon.environments.production.supervisor-5.timeout');
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        $this->assertSame(660, $digest->timeout);
        $this->assertSame(660, $routine->timeout);
        $this->assertTrue($digest->failOnTimeout);
        $this->assertTrue($routine->failOnTimeout);
        $this->assertSame(900, $horizonTimeout);
        $this->assertSame(930, $retryAfter);
        $this->assertTrue($digest->timeout < $horizonTimeout);
        $this->assertTrue($horizonTimeout < $retryAfter);
    }
}
