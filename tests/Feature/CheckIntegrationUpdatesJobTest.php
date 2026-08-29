<?php

namespace Tests\Feature;

use App\Jobs\CheckIntegrationUpdates;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckIntegrationUpdatesJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function job_dispatches_pipeline_task_for_integrations_that_need_updating()
    {
        Queue::fake();

        $user = User::factory()->create();

        // Integration that needs updating (never updated)
        $group1 = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'github',
            'access_token' => 'test-token',
        ]);
        $integration1 = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'integration_group_id' => $group1->id,
            'last_successful_update_at' => null,
        ]);

        // Integration that needs updating (frequency elapsed)
        $group2 = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'spotify',
            'access_token' => 'test-token',
        ]);
        $integration2 = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'spotify',
            'integration_group_id' => $group2->id,
            'configuration' => ['update_frequency_minutes' => 15],
            'last_successful_update_at' => Carbon::now()->subMinutes(20),
        ]);

        // Integration that doesn't need updating
        $group3 = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'slack',
            'access_token' => 'test-token',
        ]);
        Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'slack',
            'integration_group_id' => $group3->id,
            'configuration' => ['update_frequency_minutes' => 15],
            'last_successful_update_at' => Carbon::now()->subMinutes(10),
        ]);

        // Integration without access token (should be skipped)
        $group4 = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'github',
            'access_token' => null,
        ]);
        Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'integration_group_id' => $group4->id,
            'last_successful_update_at' => null,
        ]);

        $job = new CheckIntegrationUpdates;
        $job->handle();

        // Should dispatch the pipeline for integrations 1 and 2 only
        Queue::assertPushed(ProcessTaskPipelineJob::class, 2);

        Queue::assertPushed(ProcessTaskPipelineJob::class, function ($job) use ($integration1) {
            return $job->model->is($integration1) && $job->taskFilter === ['run_integration_update'];
        });
        Queue::assertPushed(ProcessTaskPipelineJob::class, function ($job) use ($integration2) {
            return $job->model->is($integration2) && $job->taskFilter === ['run_integration_update'];
        });
    }

    #[Test]
    public function job_dispatches_pipeline_task_even_for_integrations_currently_processing()
    {
        // Pause/processing/throttle are now evaluated by the pipeline task's
        // shouldRun condition (see RunIntegrationUpdateTaskTest), not by this
        // scheduler job - it only filters on isDue().
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
            'last_successful_update_at' => null,
            'last_triggered_at' => Carbon::now()->subMinutes(5), // Recently triggered
        ]);

        $job = new CheckIntegrationUpdates;
        $job->handle();

        Queue::assertPushed(ProcessTaskPipelineJob::class, function ($job) use ($integration) {
            return $job->model->is($integration);
        });
    }

    #[Test]
    public function job_does_not_dispatch_for_paused_integrations()
    {
        Queue::fake();

        $user = User::factory()->create();

        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'github',
            'access_token' => 'test-token',
        ]);
        Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'integration_group_id' => $group->id,
            'configuration' => ['paused' => true],
            'last_successful_update_at' => null,
        ]);

        $job = new CheckIntegrationUpdates;
        $job->handle();

        Queue::assertNotPushed(ProcessTaskPipelineJob::class);
    }

    #[Test]
    public function job_handles_integrations_without_access_token()
    {
        Queue::fake();

        $user = User::factory()->create();

        // Integration without access token (should be skipped)
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'github',
            'access_token' => null,
        ]);
        Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'integration_group_id' => $group->id,
            'last_successful_update_at' => null,
        ]);

        $job = new CheckIntegrationUpdates;
        $job->handle();

        Queue::assertNotPushed(ProcessTaskPipelineJob::class);
    }

    #[Test]
    public function job_handles_empty_result_set()
    {
        Queue::fake();

        $job = new CheckIntegrationUpdates;
        $job->handle();

        Queue::assertNotPushed(ProcessTaskPipelineJob::class);
    }
}
