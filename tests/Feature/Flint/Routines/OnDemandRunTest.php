<?php

namespace Tests\Feature\Flint\Routines;

use App\Jobs\Flint\TriggerFlintDigestRoutineJob;
use App\Jobs\Flint\TriggerFlintRoutineJob;
use App\Models\User;
use App\Services\FlintDigestService;
use App\Services\TaskPipeline\TaskExecutionStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OnDemandRunTest extends TestCase
{
    #[Test]
    public function the_command_runs_a_routine_now(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->artisan('flint:run-skill', ['routine' => 'topics', '--user' => $user->email, '--date' => '2026-03-04'])
            ->assertSuccessful();
    }

    #[Test]
    public function the_command_rejects_an_unknown_routine(): void
    {
        User::factory()->create();

        $this->artisan('flint:run-skill', ['routine' => 'not_a_routine'])->assertFailed();
    }

    #[Test]
    public function a_forced_run_ignores_a_marker_that_would_stop_a_scheduled_one(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $marker = TriggerFlintRoutineJob::markerKey($user->id, '2026-03-04', 'topics');
        Cache::put($marker, true, 600);

        // The scheduled job defers to the marker and does nothing.
        config(['services.flint_routine.routines.topics.url' => 'https://routine.example.test/topics']);
        Queue::fake();
        (new TriggerFlintRoutineJob($user, 'topics', '2026-03-04', 'UTC'))
            ->handle(app(FlintDigestService::class), app(TaskExecutionStore::class));

        $this->assertTrue(Cache::has($marker));
    }

    #[Test]
    public function the_mcp_tool_requires_the_flint_run_ability(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', ['flint:read'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/mcp/spark', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'run-flint-skill', 'arguments' => ['routine' => 'topics']],
        ]);

        $response->assertSuccessful();
        $this->assertStringContainsString('flint:run', json_encode($response->json()));
    }

    #[Test]
    public function the_mcp_tool_queues_the_routine_when_the_token_allows_it(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $token = $user->createToken('test', ['flint:run'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/mcp/spark', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'run-flint-skill', 'arguments' => ['routine' => 'news_roundup']],
        ]);

        $response->assertSuccessful();
        Queue::assertPushed(TriggerFlintRoutineJob::class, fn ($job) => $job->routine === 'news_roundup' && $job->force);
    }

    #[Test]
    public function the_mcp_tool_rejects_an_unknown_routine(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', ['flint:run'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/mcp/spark', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'run-flint-skill', 'arguments' => ['routine' => 'nope']],
        ]);

        $response->assertSuccessful();
        $this->assertStringContainsString('Unknown routine', json_encode($response->json()));
    }

    #[Test]
    public function a_forced_digest_run_does_not_defer_to_an_existing_digest(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $job = new TriggerFlintDigestRoutineJob($user, 'morning', '2026-03-04', 'UTC', 'manual', null, true);

        $this->assertTrue($job->force);
        $this->assertSame('manual', $job->triggerReason);
    }
}
