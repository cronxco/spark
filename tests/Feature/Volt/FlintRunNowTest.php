<?php

namespace Tests\Feature\Volt;

use App\Jobs\Flint\TriggerFlintDigestRoutineJob;
use App\Jobs\Flint\TriggerFlintRoutineJob;
use App\Models\ActionProgress;
use App\Models\User;
use App\Services\Flint\FlintRunDispatcher;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlintRunNowTest extends TestCase
{
    #[Test]
    public function the_run_now_action_queues_a_forced_routine(): void
    {
        Queue::fake();

        Volt::actingAs(User::factory()->create())
            ->test('global-progress-indicator')
            ->dispatch('run-flint-routine', skill: 'flint-reading-list')
            ->assertHasNoErrors();

        Queue::assertPushed(
            TriggerFlintRoutineJob::class,
            fn ($job) => $job->routine === 'reading_list' && $job->force
        );
    }

    #[Test]
    public function the_digest_routine_goes_through_the_digest_job(): void
    {
        Queue::fake();

        Volt::actingAs(User::factory()->create())
            ->test('global-progress-indicator')
            ->dispatch('run-flint-routine', skill: 'spark-day-briefing-async', period: 'evening');

        Queue::assertPushed(
            TriggerFlintDigestRoutineJob::class,
            fn ($job) => $job->period === 'evening' && $job->force && $job->triggerReason === 'manual'
        );
    }

    #[Test]
    public function an_unknown_routine_queues_nothing(): void
    {
        Queue::fake();

        Volt::actingAs(User::factory()->create())
            ->test('global-progress-indicator')
            ->dispatch('run-flint-routine', skill: 'not_a_routine');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_spotlight_event_reaches_the_same_action(): void
    {
        Queue::fake();

        Volt::actingAs(User::factory()->create())
            ->test('global-progress-indicator')
            ->dispatch('run-flint-routine', routine: 'topics');

        Queue::assertPushed(TriggerFlintRoutineJob::class, fn ($job) => $job->routine === 'topics');
    }

    #[Test]
    public function run_with_openai_persists_the_driver_override_on_the_job_and_progress(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        Volt::actingAs($user)
            ->test('global-progress-indicator')
            ->dispatch('run-flint-routine', skill: 'spark-day-briefing-async', period: 'evening', driverOverride: 'openai');

        Queue::assertPushed(TriggerFlintDigestRoutineJob::class, fn ($job) => $job->driverOverride === 'openai');
        $this->assertDatabaseHas('action_progress', [
            'user_id' => $user->id,
            'action_type' => 'flint_skill',
        ]);
        $this->assertSame('openai', ActionProgress::where('user_id', $user->id)->latest()->first()->details['driver']);
    }

    #[Test]
    public function invalid_driver_overrides_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(FlintRunDispatcher::class)->dispatch(
            User::factory()->create(),
            skill: 'flint-topics',
            driverOverride: 'invalid',
        );
    }
}
