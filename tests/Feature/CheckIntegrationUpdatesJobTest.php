<?php

namespace Tests\Feature;

use App\Jobs\CheckIntegrationUpdates;
use App\Jobs\OAuth\GitHub\GitHubActivityPull;
use App\Jobs\OAuth\Spotify\SpotifyListeningPull;
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
    public function job_dispatches_processing_jobs_for_integrations_that_need_updating()
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
        $integration3 = Integration::factory()->create([
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
        $integration4 = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'integration_group_id' => $group4->id,
            'last_successful_update_at' => null,
        ]);

        $job = new CheckIntegrationUpdates;
        $job->handle();

        // Should dispatch service-specific jobs for integrations 1 and 2
        Queue::assertPushed(GitHubActivityPull::class, 1);
        Queue::assertPushed(SpotifyListeningPull::class, 1);

        // Verify that the jobs were dispatched with the correct integrations
        Queue::assertPushed(GitHubActivityPull::class, function ($job) use ($integration1) {
            return $job->getIntegration()->id === $integration1->id;
        });
        Queue::assertPushed(SpotifyListeningPull::class, function ($job) use ($integration2) {
            return $job->getIntegration()->id === $integration2->id;
        });
    }

    #[Test]
    public function job_skips_integrations_that_are_currently_processing()
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

        $job = new CheckIntegrationUpdates;
        $job->handle();

        // Should not dispatch any jobs
        Queue::assertNotPushed(ProcessIntegrationData::class);
    }

    #[Test]
    public function job_skips_integrations_that_were_recently_triggered()
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
            'configuration' => ['update_frequency_minutes' => 15],
            'last_successful_update_at' => Carbon::now()->subMinutes(20),
            'last_triggered_at' => Carbon::now()->subMinutes(5), // Recently triggered
        ]);

        $job = new CheckIntegrationUpdates;
        $job->handle();

        // Should not dispatch any jobs
        Queue::assertNotPushed(ProcessIntegrationData::class);
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
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'integration_group_id' => $group->id,
            'last_successful_update_at' => null,
        ]);

        $job = new CheckIntegrationUpdates;
        $job->handle();

        // Should not dispatch any jobs
        Queue::assertNotPushed(ProcessIntegrationData::class);
    }

    #[Test]
    public function job_handles_empty_result_set()
    {
        Queue::fake();

        $job = new CheckIntegrationUpdates;
        $job->handle();

        // Should not dispatch any jobs
        Queue::assertNotPushed(ProcessIntegrationData::class);
    }
}
