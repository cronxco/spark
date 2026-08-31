<?php

namespace Tests\Feature\Volt;

use App\Jobs\Flint\TriggerFlintDigestRoutineJob;
use App\Jobs\Flint\TriggerFlintRoutineJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
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
}
