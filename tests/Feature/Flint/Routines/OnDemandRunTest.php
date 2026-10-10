<?php

namespace Tests\Feature\Flint\Routines;

use App\Jobs\Flint\TriggerFlintDigestRoutineJob;
use App\Jobs\Flint\TriggerFlintRoutineJob;
use App\Models\Event;
use App\Models\User;
use App\Services\FlintDigestService;
use App\Services\TaskPipeline\TaskExecutionStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OnDemandRunTest extends TestCase
{
    #[Test]
    public function the_command_runs_a_routine_now(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->artisan('flint:run-skill', ['skill' => 'flint-topics', '--user' => $user->email, '--date' => '2026-03-04'])
            ->assertSuccessful();
    }

    #[Test]
    public function the_command_rejects_an_unknown_routine(): void
    {
        User::factory()->create();

        $this->artisan('flint:run-skill', ['skill' => 'not_a_routine'])->assertFailed();
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
        $this->assertStringContainsString('Unknown Flint skill', json_encode($response->json()));
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

    #[Test]
    public function a_dry_run_captures_the_writes_to_a_file_instead_of_applying_them(): void
    {
        Queue::fake();
        Storage::fake('local');
        config([
            'services.openai.api_key' => 'test-key',
            'services.openai.models.reasoning' => 'test-reasoning-model',
            'services.flint_routine.cronxtools_url' => 'https://mcp.example.test/token/sse',
        ]);
        Http::fakeSequence()
            ->push([
                'id' => 'resp-paused',
                'status' => 'completed',
                'output' => [[
                    'type' => 'mcp_approval_request',
                    'id' => 'mcpr-digest',
                    'server_label' => 'cronxtools',
                    'name' => 'spark__create-flint-digest',
                    'arguments' => json_encode(['title' => 'News', 'blocks' => []]),
                ]],
            ])
            ->push([
                'id' => 'resp-done',
                'status' => 'completed',
                'output' => [['type' => 'message', 'content' => [['text' => 'Run notes.']]]],
            ]);
        $user = User::factory()->create();

        $this->artisan('flint:run-skill', [
            'skill' => 'flint-news-roundup',
            '--user' => $user->email,
            '--date' => '2026-03-04',
            '--dry-run' => true,
        ])->assertSuccessful();

        $files = Storage::disk('local')->files('flint-dry-runs');
        $this->assertCount(1, $files);
        $record = json_decode(Storage::disk('local')->get($files[0]), true);
        $this->assertSame('test-reasoning-model', $record['model']);
        $this->assertSame('2026-03-04', $record['local_date']);
        $this->assertSame('spark__create-flint-digest', $record['writes'][0]['tool']);
        $this->assertSame('Run notes.', $record['text']);
        $this->assertStringNotContainsString('mcp.example.test', json_encode($record));
        $this->assertSame(0, Event::query()->where('service', 'flint')->count());
        Queue::assertNothingPushed();
        Http::assertSent(fn ($request) => is_string($request['input'])
            && json_decode($request['input'], true)['dry_run'] === true);
    }

    #[Test]
    public function a_dry_run_refuses_the_webhook_driver(): void
    {
        Storage::fake('local');
        Http::fake();
        $user = User::factory()->create();

        $this->artisan('flint:run-skill', [
            'skill' => 'flint-news-roundup',
            '--user' => $user->email,
            '--driver' => 'webhook',
            '--dry-run' => true,
        ])->assertFailed();

        Http::assertNothingSent();
        $this->assertSame([], Storage::disk('local')->files('flint-dry-runs'));
    }
}
