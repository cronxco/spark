<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\TriggerFlintRoutineJob;
use App\Models\TaskExecution;
use App\Models\User;
use App\Services\FlintDigestService;
use App\Services\TaskPipeline\TaskExecutionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TriggerFlintRoutineJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        config([
            'services.flint_routine.routines.topics.url' => 'https://routine.example.test/topics',
            'services.flint_routine.routines.reading_list.url' => 'https://routine.example.test/reading',
            'services.flint_routine.routines.news_roundup.url' => null,
            'services.flint_routine.secret' => 'shh',
        ]);
    }

    private static function marker(string $userId, string $routine): string
    {
        return TriggerFlintRoutineJob::markerKey($userId, '2026-06-14', $routine);
    }

    #[Test]
    public function posts_to_the_routine_webhook_with_a_signed_payload(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->runJob('topics');

        Http::assertSent(fn ($request) => $request->url() === 'https://routine.example.test/topics'
            && $request->hasHeader('Authorization', 'Bearer shh')
            && $request['routine'] === 'topics'
            && $request['local_date'] === '2026-06-14'
            && $request['timezone'] === 'America/New_York'
            && $request['user_id'] === (string) $this->user->id
            && $request['idempotency_key'] === TriggerFlintRoutineJob::markerKey($this->user->id, '2026-06-14', 'topics'));

        $this->assertTrue(Cache::has(
            TriggerFlintRoutineJob::markerKey($this->user->id, '2026-06-14', 'topics')
        ));
    }

    #[Test]
    public function each_routine_posts_to_its_own_webhook(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->runJob('reading_list');

        Http::assertSent(fn ($request) => $request->url() === 'https://routine.example.test/reading'
            && $request['routine'] === 'reading_list');
    }

    #[Test]
    public function it_does_not_fire_twice_for_the_same_local_day(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->runJob('topics');
        $this->runJob('topics');

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_routine_without_a_configured_webhook_sends_nothing_but_says_so(): void
    {
        Http::fake();

        $this->runJob('news_roundup');

        Http::assertNothingSent();
        $this->assertFalse(Cache::has(
            TriggerFlintRoutineJob::markerKey($this->user->id, '2026-06-14', 'news_roundup')
        ));

        // An unconfigured routine is the exact case worth seeing in the admin
        // view, so it records not_applicable rather than going dark.
        $execution = TaskExecution::where('task_key', 'flint_routine_news_roundup')->firstOrFail();
        $this->assertSame('not_applicable', $execution->status);
    }

    #[Test]
    public function a_successful_dispatch_records_a_task_execution_against_the_flint_integration(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->runJob('topics');

        $integration = app(FlintDigestService::class)->resolveIntegration($this->user);

        $execution = TaskExecution::where('entity_type', 'integration')
            ->where('entity_id', $integration->id)
            ->where('task_key', 'flint_routine_topics')
            ->firstOrFail();

        $this->assertSame('success', $execution->status);
        $this->assertSame($this->user->id, $execution->user_id);
    }

    #[Test]
    public function each_routine_records_under_its_own_task_key(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->runJob('topics');
        $this->runJob('reading_list');

        $this->assertSame(
            ['flint_routine_reading_list', 'flint_routine_topics'],
            TaskExecution::query()->orderBy('task_key')->pluck('task_key')->all(),
        );
    }

    #[Test]
    public function an_unknown_routine_is_a_no_op(): void
    {
        Http::fake();

        $this->runJob('not_a_routine');

        Http::assertNothingSent();
        $this->assertSame(0, TaskExecution::query()->count());
    }

    #[Test]
    public function an_attempt_failure_stays_retryable_until_the_terminal_failure_callback(): void
    {
        Http::fake(['*' => Http::response(['error' => 'nope'], 500)]);

        $job = new TriggerFlintRoutineJob($this->user, 'topics', '2026-06-14', 'America/New_York');
        $exception = null;
        try {
            $job->handle(app(FlintDigestService::class), app(TaskExecutionStore::class));
            $this->fail('Expected the failed webhook call to throw.');
        } catch (RequestException $caught) {
            $exception = $caught;
        }

        $this->assertTrue(Cache::has(
            TriggerFlintRoutineJob::markerKey($this->user->id, '2026-06-14', 'topics')
        ));
        $this->assertSame(
            'retrying',
            TaskExecution::where('task_key', 'flint_routine_topics')->firstOrFail()->status,
        );

        $job->failed($exception);

        $this->assertFalse(Cache::has(
            TriggerFlintRoutineJob::markerKey($this->user->id, '2026-06-14', 'topics')
        ));
        $this->assertSame(
            'failed',
            TaskExecution::where('task_key', 'flint_routine_topics')->firstOrFail()->status,
        );
    }

    #[Test]
    public function manual_runs_leave_scheduled_markers_and_last_success_untouched(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $scheduled = new TriggerFlintRoutineJob($this->user, 'topics', '2026-06-14', 'America/New_York');
        $scheduled->handle(app(FlintDigestService::class), app(TaskExecutionStore::class));
        $marker = self::marker($this->user->id, 'topics');
        $scheduledMarker = Cache::get($marker);

        $manual = new TriggerFlintRoutineJob($this->user, 'topics', '2026-06-14', 'America/New_York', true);
        $manual->handle(app(FlintDigestService::class), app(TaskExecutionStore::class));

        $execution = TaskExecution::where('task_key', 'flint_routine_topics')->firstOrFail();
        $this->assertSame($scheduledMarker, Cache::get($marker));
        $this->assertSame('scheduled', $execution->last_success['trigger_source']);
        $this->assertSame('manual', collect($execution->history)->last()['trigger_source']);
        $this->assertCount(2, $execution->history);
        Http::assertSentCount(2);
    }

    private function runJob(string $routine = 'topics'): void
    {
        (new TriggerFlintRoutineJob($this->user, $routine, '2026-06-14', 'America/New_York'))
            ->handle(app(FlintDigestService::class), app(TaskExecutionStore::class));
    }
}
