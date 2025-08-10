<?php

namespace Tests\Feature;

use App\Jobs\CheckIntegrationUpdates;
use App\Jobs\ProcessIntegrationData;
use App\Models\Integration;
use App\Models\User;
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
        $integration1 = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'last_successful_update_at' => null,
            'access_token' => 'test-token',
        ]);

        // Integration that needs updating (frequency elapsed)
        $integration2 = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'spotify',
            'update_frequency_minutes' => 15,
            'last_successful_update_at' => Carbon::now()->subMinutes(20),
            'access_token' => 'test-token',
        ]);

        // Integration that doesn't need updating
        $integration3 = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'slack',
            'update_frequency_minutes' => 15,
            'last_successful_update_at' => Carbon::now()->subMinutes(10),
            'access_token' => 'test-token',
        ]);

        // Integration without access token (should be skipped)
        $integration4 = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'last_successful_update_at' => null,
            'access_token' => null,
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
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'last_successful_update_at' => null,
            'last_triggered_at' => Carbon::now()->subMinutes(5), // Recently triggered
            'access_token' => 'test-token',
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
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'update_frequency_minutes' => 15,
            'last_successful_update_at' => Carbon::now()->subMinutes(20),
            'last_triggered_at' => Carbon::now()->subMinutes(5), // Recently triggered
            'access_token' => 'test-token',
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
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'last_successful_update_at' => null,
            'access_token' => null,
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
