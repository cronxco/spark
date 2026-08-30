<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\TriggerFlintDigestRoutineJob;
use App\Models\EventObject;
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

        config(['services.flint_routine.url' => 'https://routine.test/webhook']);
    }

    #[Test]
    public function successful_dispatch_records_a_task_execution_anchored_to_the_digest_object(): void
    {
        Http::fake(['routine.test/*' => Http::response(['ok' => true], 200)]);
        $user = User::factory()->create();

        (new TriggerFlintDigestRoutineJob($user, 'morning', '2026-06-15', 'America/New_York', 'scheduled'))->handle();

        $digest = EventObject::where('user_id', $user->id)
            ->where('concept', 'digest')
            ->where('type', 'morning_digest')
            ->firstOrFail();

        $execution = TaskExecution::where('entity_type', 'object')
            ->where('entity_id', $digest->id)
            ->where('task_key', 'flint_digest_morning')
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

        $execution = TaskExecution::where('task_key', 'flint_digest_morning')->firstOrFail();

        $this->assertSame('fallback', $execution->triggered_by);
    }

    #[Test]
    public function sleep_score_dispatch_records_the_sleep_score_trigger_reason(): void
    {
        Http::fake(['routine.test/*' => Http::response(['ok' => true], 200)]);
        $user = User::factory()->create();

        (new TriggerFlintDigestRoutineJob($user, 'morning', '2026-06-15', 'America/New_York', 'sleep_score', (string) Str::uuid()))->handle();

        $execution = TaskExecution::where('task_key', 'flint_digest_morning')->firstOrFail();

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
        } catch (RequestException) {
            // Expected: the job re-throws so the queue worker retries.
        }

        $execution = TaskExecution::where('task_key', 'flint_digest_evening')->firstOrFail();

        $this->assertSame('failed', $execution->status);
        $this->assertStringContainsString('500', $execution->error);
    }

    #[Test]
    public function pre_created_digest_object_is_reused_by_the_mcp_creation_flow(): void
    {
        Http::fake(['routine.test/*' => Http::response(['ok' => true], 200)]);
        $user = User::factory()->create();

        (new TriggerFlintDigestRoutineJob($user, 'morning', '2026-06-15', 'America/New_York', 'scheduled'))->handle();

        $preCreated = EventObject::where('user_id', $user->id)
            ->where('concept', 'digest')
            ->where('type', 'morning_digest')
            ->firstOrFail();

        $result = app(FlintDigestService::class)->create($user, [
            'title' => 'Morning Digest',
            'period' => 'morning',
            'date' => '2026-06-15',
        ]);

        $this->assertSame($preCreated->id, $result['digest_object_id']);
        $this->assertSame(1, EventObject::where('user_id', $user->id)->where('concept', 'digest')->count());
    }
}
