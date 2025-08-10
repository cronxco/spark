<?php

namespace Tests\Feature;

use App\Jobs\CheckIntegrationUpdates;
use App\Jobs\ProcessIntegrationData;
use App\Models\Integration;
use App\Models\User;
use App\Models\IntegrationGroup;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CheckIntegrationUpdatesJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_dispatches_processing_jobs_for_integrations_that_need_updating()
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
            'update_frequency_minutes' => 15,
            'last_successful_update_at' => Carbon::now()->subMinutes(20),
        ]);

        // Integration that doesn't need updating
        $group3 = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'slack',
            'access_token' => 'test-token',
        ]);
        $integration3 = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'slack',
            'integration_group_id' => $group3->id,
            'update_frequency_minutes' => 15,
            'last_successful_update_at' => Carbon::now()->subMinutes(10),
        ]);

        // Integration without access token (should be skipped)
        $group4 = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'github',
            'access_token' => null,
        ]);
        $integration4 = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'integration_group_id' => $group4->id,
            'last_successful_update_at' => null,
        ]);

        $job = new CheckIntegrationUpdates();
        $job->handle();

        // Should dispatch ProcessIntegrationData jobs for integrations 1 and 2
        Queue::assertPushed(ProcessIntegrationData::class, 2);
        
        // We can't access the protected integration property directly, so we'll just verify the count
        // The job dispatching logic is tested in the integration tests
    }

    public function test_job_skips_integrations_that_are_currently_processing()
    {
        Queue::fake();

        $user = User::factory()->create();
        
        // Integration that is currently processing
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

        $job = new CheckIntegrationUpdates();
        $job->handle();

        // Should not dispatch any jobs
        Queue::assertNotPushed(ProcessIntegrationData::class);
    }

    public function test_job_skips_integrations_that_were_recently_triggered()
    {
        Queue::fake();

        $user = User::factory()->create();
        
        // Integration that was recently triggered (within frequency window)
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'github',
            'access_token' => 'test-token',
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'integration_group_id' => $group->id,
            'update_frequency_minutes' => 15,
            'last_successful_update_at' => Carbon::now()->subMinutes(20),
            'last_triggered_at' => Carbon::now()->subMinutes(5), // Recently triggered
        ]);

        $job = new CheckIntegrationUpdates();
        $job->handle();

        // Should not dispatch any jobs
        Queue::assertNotPushed(ProcessIntegrationData::class);
    }

    public function test_job_handles_integrations_without_access_token()
    {
        Queue::fake();

        $user = User::factory()->create();
        
        // Integration without access token (should be skipped)
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'github',
            'access_token' => null,
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'integration_group_id' => $group->id,
            'last_successful_update_at' => null,
        ]);

        $job = new CheckIntegrationUpdates();
        $job->handle();

        // Should not dispatch any jobs
        Queue::assertNotPushed(ProcessIntegrationData::class);
    }

    public function test_job_handles_empty_result_set()
    {
        Queue::fake();

        $job = new CheckIntegrationUpdates();
        $job->handle();

        // Should not dispatch any jobs
        Queue::assertNotPushed(ProcessIntegrationData::class);
    }
}
