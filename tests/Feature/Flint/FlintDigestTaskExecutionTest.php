<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\TriggerFlintDigestRoutineJob;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\TaskExecution;
use App\Models\User;
use App\Services\FlintDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlintDigestTaskExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.flint_routine.routines.digest.url' => 'https://routine.test/webhook']);
    }

    #[Test]
    public function successful_dispatch_records_a_task_execution_anchored_to_the_flint_integration(): void
    {
        Http::fake(['routine.test/*' => Http::response(['ok' => true], 200)]);
        $user = User::factory()->create();

        (new TriggerFlintDigestRoutineJob($user, 'morning', '2026-06-15', 'America/New_York', 'scheduled'))->handle();

        $integration = Integration::where('user_id', $user->id)
            ->where('service', 'flint')
            ->firstOrFail();

        $execution = TaskExecution::where('entity_type', 'integration')
            ->where('entity_id', $integration->id)
            ->where('task_key', 'flint_routine_digest')
            ->firstOrFail();

        $this->assertSame('success', $execution->status);
        $this->assertSame('scheduled', $execution->triggered_by);
        $this->assertSame($user->id, $execution->user_id);
    }

    #[Test]
    public function fallback_dispatch_records_fallback_as_the_trigger_reason(): void
    {
        Http::fake(['routine.test/*' => Http::response(['ok' => true], 200)]);
        $user = User::factory()->create();

        (new TriggerFlintDigestRoutineJob($user, 'morning', '2026-06-15', 'America/New_York', 'fallback'))->handle();

        $execution = TaskExecution::where('task_key', 'flint_routine_digest')->firstOrFail();

        $this->assertSame('fallback', $execution->triggered_by);
    }

    #[Test]
    public function sleep_score_dispatch_records_the_sleep_score_trigger_reason(): void
    {
        Http::fake(['routine.test/*' => Http::response(['ok' => true], 200)]);
        $user = User::factory()->create();

        (new TriggerFlintDigestRoutineJob($user, 'morning', '2026-06-15', 'America/New_York', 'sleep_score', (string) Str::uuid()))->handle();

        $execution = TaskExecution::where('task_key', 'flint_routine_digest')->firstOrFail();

        $this->assertSame('sleep_score', $execution->triggered_by);
    }

    #[Test]
    public function failed_webhook_response_records_a_failed_task_execution_with_the_error(): void
    {
        Http::fake(['routine.test/*' => Http::response('server exploded', 500)]);
        $user = User::factory()->create();

        $job = new TriggerFlintDigestRoutineJob($user, 'evening', '2026-06-15', 'America/New_York', 'scheduled');

        try {
            $job->handle();
            $this->fail('Expected the job to throw on a non-2xx webhook response.');
        } catch (RequestException $exception) {
            $job->failed($exception);
        }

        $execution = TaskExecution::where('task_key', 'flint_routine_digest')->firstOrFail();

        $this->assertSame('failed', $execution->status);
        $this->assertStringContainsString('500', $execution->error);
    }

    #[Test]
    public function retry_clears_the_previous_webhook_error(): void
    {
        Http::fakeSequence()
            ->push('server exploded', 500)
            ->push(['ok' => true], 200);
        $user = User::factory()->create();
        $job = new TriggerFlintDigestRoutineJob($user, 'evening', '2026-06-15', 'America/New_York', 'scheduled');

        try {
            $job->handle();
            $this->fail('Expected the first webhook response to fail.');
        } catch (RequestException) {
            // Expected: the same run owns the marker and may retry below.
        }

        $job->handle();

        $execution = TaskExecution::where('task_key', 'flint_routine_digest')->firstOrFail();

        $this->assertSame('success', $execution->status);
        $this->assertNull($execution->error);
    }

    #[Test]
    public function the_digest_object_is_created_only_by_the_mcp_write_flow(): void
    {
        Http::fake(['routine.test/*' => Http::response(['ok' => true], 200)]);
        $user = User::factory()->create();

        (new TriggerFlintDigestRoutineJob($user, 'morning', '2026-06-15', 'America/New_York', 'scheduled'))->handle();

        $this->assertSame(0, EventObject::where('user_id', $user->id)->where('concept', 'digest')->count());

        $result = app(FlintDigestService::class)->create($user, [
            'title' => 'Morning Digest',
            'period' => 'morning',
            'date' => '2026-06-15',
        ]);

        $this->assertSame(1, EventObject::where('user_id', $user->id)->where('concept', 'digest')->count());
        $this->assertNotNull($result['digest_object_id']);
    }
}
