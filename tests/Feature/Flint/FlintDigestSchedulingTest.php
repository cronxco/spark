<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\SendDigestNotificationJob;
use App\Jobs\Flint\TriggerFlintDigestRoutineJob;
use App\Jobs\TaskPipeline\Tasks\DispatchMorningDigestOnSleepScoreTask;
use App\Jobs\TaskPipeline\Tasks\NotifyOnDigestReadyTask;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use App\Services\TaskPipeline\TaskRegistry;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

class FlintDigestSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // June 2026 is EDT (UTC-4); 2026-06-15 is a Monday (weekday).

    #[Test]
    public function evening_digest_triggers_after_local_slot(): void
    {
        Bus::fake();
        $user = $this->newYorkUser();

        // 19:30 America/New_York == 23:30 UTC.
        Carbon::setTestNow('2026-06-15 23:30:00');

        $this->runDispatcher();

        Bus::assertDispatched(TriggerFlintDigestRoutineJob::class, function ($job) use ($user) {
            return $job->user->id === $user->id
                && $job->period === 'evening'
                && $job->localDate === '2026-06-15'
                && $job->triggerReason === 'scheduled';
        });
    }

    #[Test]
    public function morning_digest_waits_when_no_sleep_and_before_fallback(): void
    {
        Bus::fake();
        $this->newYorkUser();

        // 08:00 NY: after the 07:30 weekday slot, before the 11:00 fallback, no sleep yet.
        Carbon::setTestNow('2026-06-15 12:00:00');

        $this->runDispatcher();

        Bus::assertNotDispatched(TriggerFlintDigestRoutineJob::class, fn ($job) => $job->period === 'morning');
    }

    #[Test]
    public function morning_digest_triggers_with_scheduled_reason_when_sleep_present(): void
    {
        Bus::fake();
        $user = $this->newYorkUser();
        $sleep = $this->seedSleepScore($user, '2026-06-15');

        // 08:00 NY: after the morning slot, sleep already in.
        Carbon::setTestNow('2026-06-15 12:00:00');

        $this->runDispatcher();

        Bus::assertDispatched(TriggerFlintDigestRoutineJob::class, function ($job) use ($user, $sleep) {
            return $job->user->id === $user->id
                && $job->period === 'morning'
                && $job->triggerReason === 'scheduled'
                && $job->sleepScoreEventId === $sleep->id;
        });
    }

    #[Test]
    public function morning_digest_triggers_with_fallback_reason_after_cutoff(): void
    {
        Bus::fake();
        $this->newYorkUser();

        // 11:30 NY: past the 11:00 fallback cutoff, still no sleep.
        Carbon::setTestNow('2026-06-15 15:30:00');

        $this->runDispatcher();

        Bus::assertDispatched(TriggerFlintDigestRoutineJob::class, function ($job) {
            return $job->period === 'morning' && $job->triggerReason === 'fallback';
        });
    }

    #[Test]
    public function sleep_task_fires_morning_digest_immediately_when_past_slot(): void
    {
        Bus::fake();
        $user = $this->newYorkUser();
        $sleep = $this->seedSleepScore($user, '2026-06-15');

        // 08:00 NY: after the morning slot.
        Carbon::setTestNow('2026-06-15 12:00:00');

        $task = TaskRegistry::getTask('dispatch_morning_digest_on_sleep_score');
        (new DispatchMorningDigestOnSleepScoreTask($sleep, $task))->handle();

        Bus::assertDispatched(TriggerFlintDigestRoutineJob::class, function ($job) use ($sleep) {
            return $job->period === 'morning'
                && $job->triggerReason === 'sleep_score'
                && $job->sleepScoreEventId === $sleep->id;
        });
    }

    #[Test]
    public function sleep_task_does_nothing_before_morning_slot(): void
    {
        Bus::fake();
        $user = $this->newYorkUser();
        $sleep = $this->seedSleepScore($user, '2026-06-15');

        // 06:00 NY: before the 07:30 weekday slot.
        Carbon::setTestNow('2026-06-15 10:00:00');

        $task = TaskRegistry::getTask('dispatch_morning_digest_on_sleep_score');
        (new DispatchMorningDigestOnSleepScoreTask($sleep, $task))->handle();

        Bus::assertNotDispatched(TriggerFlintDigestRoutineJob::class);
    }

    #[Test]
    public function sleep_task_ignores_historical_back_fill(): void
    {
        Bus::fake();
        $user = $this->newYorkUser();
        $sleep = $this->seedSleepScore($user, '2026-06-10'); // not today

        Carbon::setTestNow('2026-06-15 12:00:00');

        $task = TaskRegistry::getTask('dispatch_morning_digest_on_sleep_score');
        (new DispatchMorningDigestOnSleepScoreTask($sleep, $task))->handle();

        Bus::assertNotDispatched(TriggerFlintDigestRoutineJob::class);
    }

    #[Test]
    public function digest_notification_task_dispatches_send_digest_notification_job(): void
    {
        Bus::fake();
        $user = $this->newYorkUser();

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'flint',
        ]);
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'event_metadata' => ['period' => 'morning'],
        ]);

        $task = TaskRegistry::getTask('notify_on_digest_ready');
        $this->assertNotNull($task);

        (new NotifyOnDigestReadyTask($event, $task))->handle();

        Bus::assertDispatched(SendDigestNotificationJob::class, function ($job) use ($user) {
            return $job->user->id === $user->id;
        });
    }

    #[Test]
    public function creating_a_digest_summary_event_dispatches_exactly_one_notification(): void
    {
        Bus::fake([SendDigestNotificationJob::class]);
        $user = $this->newYorkUser();

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'flint',
        ]);

        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'flint',
            'domain' => 'knowledge',
            'action' => 'had_summary',
            'time' => now(),
            'value' => null,
            'value_unit' => null,
            'event_metadata' => ['period' => 'morning'],
        ]);

        // Exactly one - the task pipeline path is now the sole trigger, so
        // there is no risk of a duplicate direct dispatch alongside it.
        Bus::assertDispatchedTimes(SendDigestNotificationJob::class, 1);
    }

    #[Test]
    public function evening_slot_follows_the_latest_acknowledged_timezone(): void
    {
        Bus::fake();

        // Profile is London, but the user acknowledges a move to New York mid-trip.
        $user = User::factory()->create([
            'settings' => [
                'timezone' => 'Europe/London',
                'flint' => ['digests_enabled' => true],
            ],
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'daily_checkin',
        ]);
        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'daily_checkin',
            'action' => 'time_travel',
            'event_metadata' => ['timezone' => 'Europe/London', 'acknowledged_at' => '2026-06-15T06:00:00.000000Z'],
        ]);
        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'daily_checkin',
            'action' => 'time_travel',
            'event_metadata' => ['timezone' => 'America/New_York', 'acknowledged_at' => '2026-06-15T12:00:00.000000Z'],
        ]);

        // 23:30 UTC == 19:30 New York (fires) but 00:30 London (would not). The
        // later NY acknowledgement must move the evening slot.
        Carbon::setTestNow('2026-06-15 23:30:00');

        $this->runDispatcher();

        Bus::assertDispatched(TriggerFlintDigestRoutineJob::class, function ($job) use ($user) {
            return $job->user->id === $user->id
                && $job->period === 'evening'
                && $job->timezone === 'America/New_York';
        });
    }

    /**
     * Create a user with digests enabled whose effective timezone is New York.
     */
    private function newYorkUser(): User
    {
        $user = User::factory()->create([
            'settings' => [
                'timezone' => 'Europe/London',
                'flint' => ['digests_enabled' => true],
            ],
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'daily_checkin',
        ]);
        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'daily_checkin',
            'action' => 'time_travel',
            'event_metadata' => [
                'timezone' => 'America/New_York',
                'acknowledged_at' => '2026-06-10T10:00:00.000000Z',
            ],
        ]);

        return $user;
    }

    private function seedSleepScore(User $user, string $day): Event
    {
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'oura',
        ]);

        return Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'event_metadata' => ['day' => $day],
        ]);
    }

    private function runDispatcher(): void
    {
        $schedule = app(Schedule::class);
        $event = collect($schedule->events())
            ->first(fn ($e) => ($e->description ?? null) === 'flint-digest-dispatcher');

        $this->assertNotNull($event, 'flint-digest-dispatcher schedule not found');

        $property = new ReflectionProperty($event, 'callback');
        $property->setAccessible(true);
        app()->call($property->getValue($event));
    }
}
