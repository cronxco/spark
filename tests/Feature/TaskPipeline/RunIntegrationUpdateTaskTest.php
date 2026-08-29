<?php

namespace Tests\Feature\TaskPipeline;

use App\Jobs\OAuth\GitHub\GitHubActivityPull;
use App\Jobs\TaskPipeline\Tasks\RunIntegrationUpdateTask;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\TaskPipeline\TaskRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RunIntegrationUpdateTaskTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function execute_dispatches_fetch_jobs_and_marks_integration_triggered(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'github',
            'access_token' => 'test-token',
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'integration_group_id' => $group->id,
            'last_triggered_at' => null,
        ]);

        $task = TaskRegistry::getTask('run_integration_update');

        $job = new RunIntegrationUpdateTask($integration, $task);
        $job->handle();

        Queue::assertPushed(GitHubActivityPull::class, function ($job) use ($integration) {
            return $job->getIntegration()->id === $integration->id;
        });

        $this->assertNotNull($integration->fresh()->last_triggered_at);
    }

    #[Test]
    public function dispatching_twice_for_the_same_integration_while_the_first_is_still_queued_only_enqueues_once(): void
    {
        // ShouldBeUnique releases its lock as soon as a dispatched job finishes
        // an attempt (success or failure) - not after all retries are
        // exhausted. Under the `sync` queue driver each dispatch runs to
        // completion (and releases its lock) before the next dispatch call
        // even happens, so the race this guards against - a second dispatch
        // landing while the first is still sitting in the queue - can't be
        // observed with `sync`. Use the `database` driver instead, so the
        // first dispatch enqueues a row without executing it, and confirm
        // the second dispatch for the same integration is silently dropped.
        config(['queue.default' => 'database']);

        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'github',
            'access_token' => 'test-token',
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'integration_group_id' => $group->id,
            'last_triggered_at' => null,
        ]);

        $task = TaskRegistry::getTask('run_integration_update');

        RunIntegrationUpdateTask::dispatch($integration, $task);
        RunIntegrationUpdateTask::dispatch($integration, $task);

        $this->assertDatabaseCount('jobs', 1);
    }

    #[Test]
    public function task_is_not_applicable_when_integration_is_paused(): void
    {
        $integration = Integration::factory()->create([
            'configuration' => ['paused' => true],
        ]);

        $task = TaskRegistry::getTask('run_integration_update');

        $this->assertFalse($task->isApplicableTo($integration));
    }

    #[Test]
    public function task_is_not_applicable_when_integration_is_processing(): void
    {
        $integration = Integration::factory()->create([
            'last_triggered_at' => Carbon::now()->subMinutes(2),
            'last_successful_update_at' => null,
        ]);

        $task = TaskRegistry::getTask('run_integration_update');

        $this->assertFalse($task->isApplicableTo($integration));
    }

    #[Test]
    public function task_is_not_applicable_when_integration_should_throttle(): void
    {
        $integration = Integration::factory()->create([
            'configuration' => ['update_frequency_minutes' => 15],
            'last_successful_update_at' => Carbon::now()->subMinutes(20),
            'last_triggered_at' => Carbon::now()->subMinutes(5),
        ]);

        $task = TaskRegistry::getTask('run_integration_update');

        $this->assertFalse($task->isApplicableTo($integration));
    }

    #[Test]
    public function task_is_applicable_when_integration_is_free_to_run(): void
    {
        $integration = Integration::factory()->create([
            'last_triggered_at' => null,
            'last_successful_update_at' => null,
        ]);

        $task = TaskRegistry::getTask('run_integration_update');

        $this->assertTrue($task->isApplicableTo($integration));
    }
}
