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
