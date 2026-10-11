<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\TriggerFlintRoutineJob;
use App\Models\Integration;
use App\Models\User;
use App\Services\Flint\FlintScheduleService;
use App\Services\FlintDigestService;
use App\Services\TaskPipeline\TaskDefinition;
use App\Services\TaskPipeline\TaskExecutionStore;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

class FlintScheduleOccurrencesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function an_old_single_time_schedule_has_one_keyless_occurrence(): void
    {
        $user = $this->user(['reading_list_time' => '18:15']);

        $occurrences = $this->service()->occurrencesOn($user, 'reading_list', Carbon::parse('2026-10-13', 'Europe/London'));

        $this->assertCount(1, $occurrences);
        $this->assertNull($occurrences[0]['key']);
        $this->assertSame('18:15', $occurrences[0]['at']->format('H:i'));
        $this->assertSame(
            'flint:routine-triggered:reading_list:1:2026-10-13',
            TriggerFlintRoutineJob::markerKey(1, '2026-10-13', 'reading_list', null),
        );
    }

    #[Test]
    public function times_make_one_occurrence_each_and_only_the_latest_passed_one_is_due(): void
    {
        $user = $this->user(['schedules' => ['topics' => ['frequency' => 'daily', 'times' => ['18:00', '08:00', '13:00', '08:00', 'bad']]]]);

        $keys = array_column($this->service()->occurrencesOn($user, 'topics', Carbon::parse('2026-10-13', 'Europe/London')), 'key');
        $this->assertSame(['08:00', '13:00', '18:00'], $keys);

        $this->assertNull($this->service()->dueOccurrence($user, 'topics', Carbon::parse('2026-10-13 07:59', 'Europe/London')));
        $this->assertSame('13:00', $this->service()->dueOccurrence($user, 'topics', Carbon::parse('2026-10-13 15:00', 'Europe/London'))['key']);
    }

    #[Test]
    public function times_still_follow_the_frequency_days(): void
    {
        $user = $this->user(['schedules' => ['topics' => ['frequency' => 'weekdays', 'times' => ['09:00', '17:00']]]]);

        $this->assertCount(2, $this->service()->occurrencesOn($user, 'topics', Carbon::parse('2026-10-12', 'Europe/London')));
        $this->assertSame([], $this->service()->occurrencesOn($user, 'topics', Carbon::parse('2026-10-11', 'Europe/London')));
    }

    #[Test]
    public function a_cron_decides_its_own_days_and_times(): void
    {
        $user = $this->user(['schedules' => ['news_roundup' => ['cron' => '30 7,19 * * 1-5']]]);

        $this->assertSame(['07:30', '19:30'], array_column($this->service()->occurrencesOn($user, 'news_roundup', Carbon::parse('2026-10-12', 'Europe/London')), 'key'));
        $this->assertSame([], $this->service()->occurrencesOn($user, 'news_roundup', Carbon::parse('2026-10-11', 'Europe/London')));
        $this->assertSame('07:30', $this->service()->dueOccurrence($user, 'news_roundup', Carbon::parse('2026-10-12 12:00', 'Europe/London'))['key']);
    }

    #[Test]
    public function times_and_a_cron_combine(): void
    {
        $user = $this->user(['schedules' => ['topics' => ['frequency' => 'daily', 'times' => ['09:00'], 'cron' => '0 21 * * *']]]);

        $this->assertSame(['09:00', '21:00'], array_column($this->service()->occurrencesOn($user, 'topics', Carbon::parse('2026-10-12', 'Europe/London')), 'key'));
    }

    #[Test]
    public function an_invalid_cron_or_time_list_falls_back_to_the_single_time(): void
    {
        $user = $this->user([
            'topics_time' => '19:00',
            'schedules' => ['topics' => ['frequency' => 'daily', 'times' => ['25:00', 'noon'], 'cron' => 'every day']],
        ]);

        $occurrences = $this->service()->occurrencesOn($user, 'topics', Carbon::parse('2026-10-12', 'Europe/London'));

        $this->assertCount(1, $occurrences);
        $this->assertNull($occurrences[0]['key']);
        $this->assertSame('19:00', $occurrences[0]['at']->format('H:i'));
    }

    #[Test]
    public function a_cron_with_the_wrong_number_of_fields_is_ignored(): void
    {
        $user = $this->user(['schedules' => ['topics' => ['cron' => '0 0 9 * * *']]]);

        $this->assertNull($this->service()->cadence($user, 'topics') === null ? null : $this->service()->cadence($user, 'topics')['frequency'] ?? null);
        $this->assertNull($this->service()->occurrencesOn($user, 'topics', Carbon::parse('2026-10-12', 'Europe/London'))[0]['key'] ?? null);
    }

    #[Test]
    public function slots_are_evaluated_in_the_users_timezone_across_the_spring_dst_change(): void
    {
        $user = $this->user(['schedules' => ['topics' => ['frequency' => 'daily', 'times' => ['01:30', '09:00'], 'cron' => '0 18 * * *']]], 'Europe/London');

        $day = Carbon::parse('2026-03-29', 'Europe/London');
        $occurrences = $this->service()->occurrencesOn($user, 'topics', $day);

        $this->assertSame(['01:30', '09:00', '18:00'], array_column($occurrences, 'key'));
        $this->assertSame('2026-03-29 09:00:00 +01:00', $occurrences[1]['at']->format('Y-m-d H:i:s P'));
        $this->assertSame('2026-03-29 18:00:00 +01:00', $occurrences[2]['at']->format('Y-m-d H:i:s P'));
        $this->assertSame('2026-03-28 18:00:00 +00:00', $this->service()->occurrencesOn($user, 'topics', Carbon::parse('2026-03-28', 'Europe/London'))[2]['at']->format('Y-m-d H:i:s P'));
    }

    #[Test]
    public function an_hour_that_repeats_in_autumn_is_still_one_occurrence(): void
    {
        $user = $this->user(['schedules' => ['topics' => ['cron' => '30 1 * * *']]], 'Europe/London');

        $occurrences = $this->service()->occurrencesOn($user, 'topics', Carbon::parse('2026-10-25', 'Europe/London'));

        $this->assertSame(['01:30'], array_column($occurrences, 'key'));
    }

    #[Test]
    public function next_eligible_run_uses_the_next_slot(): void
    {
        $user = $this->user(['schedules' => ['topics' => ['frequency' => 'daily', 'times' => ['08:00', '18:00']]]], 'Europe/London');
        Carbon::setTestNow('2026-10-13 12:00:00');

        $this->assertSame('2026-10-13 18:00', $this->service()->nextEligibleRun($user, 'topics')->format('Y-m-d H:i'));

        Carbon::setTestNow('2026-10-13 19:30:00');
        $this->assertSame('2026-10-14 08:00', $this->service()->nextEligibleRun($user, 'topics')->format('Y-m-d H:i'));
    }

    #[Test]
    public function each_occurrence_has_its_own_idempotency_key(): void
    {
        $this->assertNotSame(
            TriggerFlintRoutineJob::markerKey(1, '2026-10-13', 'topics', '08:00'),
            TriggerFlintRoutineJob::markerKey(1, '2026-10-13', 'topics', '18:00'),
        );
        $this->assertSame('flint:routine-triggered:topics:1:2026-10-13:0800', TriggerFlintRoutineJob::markerKey(1, '2026-10-13', 'topics', '08:00'));
        $this->assertNotSame(
            TriggerFlintRoutineJob::attemptsKey(1, '2026-10-13', 'topics', '08:00'),
            TriggerFlintRoutineJob::attemptsKey(1, '2026-10-13', 'topics', null),
        );
    }

    #[Test]
    public function a_second_occurrence_fires_after_the_first_and_each_fires_once(): void
    {
        config([
            'services.flint_routine.routines.topics.url' => 'https://routine.example.test/topics',
            'services.flint_routine.secret' => 'shh',
        ]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $user = $this->user(['schedules' => ['topics' => ['frequency' => 'daily', 'times' => ['08:00', '18:00']]]], 'Europe/London');

        $this->fire($user, '08:00');
        $this->fire($user, '08:00');
        $this->assertCount(1, Http::recorded());

        $this->completeLatest($user);
        $this->fire($user, '18:00');
        $this->fire($user, '18:00');

        $this->assertCount(2, Http::recorded());
        $payloads = collect(Http::recorded())->map(fn ($pair) => $pair[0]['text'])->all();
        $this->assertStringContainsString('"occurrence": "08:00"', $payloads[0]);
        $this->assertStringContainsString('"occurrence": "18:00"', $payloads[1]);
    }

    #[Test]
    public function a_routine_that_is_still_running_is_not_started_again(): void
    {
        config([
            'services.flint_routine.routines.topics.url' => 'https://routine.example.test/topics',
            'services.flint_routine.secret' => 'shh',
        ]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $user = $this->user(['schedules' => ['topics' => ['frequency' => 'daily', 'times' => ['08:00', '18:00']]]], 'Europe/London');

        $this->fire($user, '08:00');
        $this->assertTrue($this->service()->isRunning($user, 'topics'));

        $this->fire($user, '18:00');

        $this->assertCount(1, Http::recorded());
        $this->assertFalse(Cache::has(TriggerFlintRoutineJob::markerKey($user->id, '2026-10-13', 'topics', '18:00')));

        $this->completeLatest($user);
        $this->assertFalse($this->service()->isRunning($user, 'topics'));
        $this->fire($user, '18:00');
        $this->assertCount(2, Http::recorded());
    }

    #[Test]
    public function a_run_that_never_reported_back_stops_blocking_after_the_stale_window(): void
    {
        config([
            'services.flint_routine.routines.topics.url' => 'https://routine.example.test/topics',
            'services.flint_routine.secret' => 'shh',
        ]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $user = $this->user(['schedules' => ['topics' => ['frequency' => 'daily', 'times' => ['08:00', '18:00']]]], 'Europe/London');
        Carbon::setTestNow('2026-10-13 08:05:00');
        $this->fire($user, '08:00');

        $this->assertTrue($this->service()->isRunning($user, 'topics'));

        Carbon::setTestNow('2026-10-13 18:05:00');
        $this->assertFalse($this->service()->isRunning($user, 'topics'));
    }

    #[Test]
    public function the_dispatcher_fires_the_latest_due_occurrence_with_its_key(): void
    {
        Bus::fake();
        $user = $this->user([
            'topics_enabled' => true,
            'schedules' => ['topics' => ['frequency' => 'daily', 'times' => ['08:00', '13:00', '18:00']]],
        ], 'Europe/London');
        Carbon::setTestNow('2026-10-13 14:00:00');

        $this->runRoutineDispatcher();

        Bus::assertDispatched(TriggerFlintRoutineJob::class, fn ($job) => $job->user->id === $user->id && $job->routine === 'topics' && $job->occurrence === '13:00');
    }

    #[Test]
    public function the_dispatcher_does_not_repeat_a_taken_slot_but_takes_the_next_one(): void
    {
        Bus::fake();
        $user = $this->user([
            'topics_enabled' => true,
            'schedules' => ['topics' => ['frequency' => 'daily', 'times' => ['08:00', '18:00']]],
        ], 'Europe/London');
        Carbon::setTestNow('2026-10-13 07:00:00');
        Cache::put(TriggerFlintRoutineJob::markerKey($user->id, '2026-10-13', 'topics', '08:00'), 'x', now()->addDays(5));

        Carbon::setTestNow('2026-10-13 10:00:00');
        $this->runRoutineDispatcher();
        Bus::assertNotDispatched(TriggerFlintRoutineJob::class, fn ($job) => $job->routine === 'topics');

        Carbon::setTestNow('2026-10-13 18:10:00');
        $this->runRoutineDispatcher();
        Bus::assertDispatched(TriggerFlintRoutineJob::class, fn ($job) => $job->routine === 'topics' && $job->occurrence === '18:00');
    }

    #[Test]
    public function an_old_daily_setting_dispatches_exactly_as_before(): void
    {
        Bus::fake();
        $user = $this->user(['topics_enabled' => true, 'topics_time' => '19:00'], 'Europe/London');
        Carbon::setTestNow('2026-10-13 20:00:00');

        $this->runRoutineDispatcher();

        Bus::assertDispatched(TriggerFlintRoutineJob::class, fn ($job) => $job->user->id === $user->id && $job->occurrence === null);
    }

    private function fire(User $user, string $occurrence): void
    {
        (new TriggerFlintRoutineJob($user, 'topics', '2026-10-13', 'Europe/London', occurrence: $occurrence))
            ->handle(app(FlintDigestService::class), app(TaskExecutionStore::class));
    }

    private function completeLatest(User $user): void
    {
        $integration = Integration::query()->where('user_id', $user->id)->where('service', 'flint')->firstOrFail();
        $store = app(TaskExecutionStore::class);
        $attempt = $store->getTaskExecutions($integration)['flint_routine_topics']['last_attempt'];
        $task = new TaskDefinition(
            key: 'flint_routine_topics',
            name: 'Flint Topics Routine',
            description: 'test',
            jobClass: self::class,
            appliesTo: ['integration'],
            queue: 'flint',
        );
        $store->recordStatus($integration, $task, 'success', [
            'run_uuid' => $attempt['run_uuid'],
            'local_date' => '2026-10-13',
            'occurrence' => $attempt['occurrence'] ?? null,
            'trigger_source' => 'scheduled',
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    private function runRoutineDispatcher(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => ($event->description ?? null) === 'flint-routine-dispatcher');

        $this->assertNotNull($event);
        $property = new ReflectionProperty($event, 'callback');
        $property->setAccessible(true);
        app()->call($property->getValue($event));
    }

    private function user(array $flint = [], string $timezone = 'UTC'): User
    {
        return User::factory()->create(['settings' => ['timezone' => $timezone, 'flint' => $flint]]);
    }

    private function service(): FlintScheduleService
    {
        return app(FlintScheduleService::class);
    }
}
