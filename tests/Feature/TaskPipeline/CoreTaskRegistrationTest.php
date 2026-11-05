<?php

namespace Tests\Feature\TaskPipeline;

use App\Jobs\TaskPipeline\Tasks\DispatchMorningDigestOnSleepScoreTask;
use App\Jobs\TaskPipeline\Tasks\NotifyOnDigestReadyTask;
use App\Jobs\TaskPipeline\Tasks\RunIntegrationUpdateTask;
use App\Services\TaskPipeline\TaskRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards against core tasks being registered with a jobClass string that
 * doesn't resolve to a real class - e.g. an unqualified class name used
 * without a matching `use` import resolves relative to the registering
 * file's own namespace instead of the intended one.
 */
class CoreTaskRegistrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_registered_core_task_has_a_loadable_job_class(): void
    {
        $tasks = TaskRegistry::getAllTasks();

        $this->assertNotEmpty($tasks);

        foreach ($tasks as $task) {
            $this->assertTrue(
                class_exists($task->jobClass),
                "Task '{$task->key}' is registered with jobClass '{$task->jobClass}', which does not exist."
            );
        }
    }

    #[Test]
    public function dispatch_morning_digest_on_sleep_score_resolves_to_the_correct_job_class(): void
    {
        $task = TaskRegistry::getTask('dispatch_morning_digest_on_sleep_score');

        $this->assertNotNull($task);
        $this->assertSame(
            DispatchMorningDigestOnSleepScoreTask::class,
            $task->jobClass
        );
    }

    #[Test]
    public function notify_on_digest_ready_is_registered(): void
    {
        $task = TaskRegistry::getTask('notify_on_digest_ready');

        $this->assertNotNull($task);
        $this->assertSame(
            NotifyOnDigestReadyTask::class,
            $task->jobClass
        );
        $this->assertSame(['event'], $task->appliesTo);
        $this->assertSame(['service' => 'flint', 'action' => 'had_summary'], $task->conditions);
    }

    #[Test]
    public function run_integration_update_is_registered(): void
    {
        $task = TaskRegistry::getTask('run_integration_update');

        $this->assertNotNull($task);
        $this->assertSame(
            RunIntegrationUpdateTask::class,
            $task->jobClass
        );
        $this->assertSame(['integration'], $task->appliesTo);
    }
}
