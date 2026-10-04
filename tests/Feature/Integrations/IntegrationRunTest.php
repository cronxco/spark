<?php

namespace Tests\Feature\Integrations;

use App\Actions\DispatchIntegrationFetchJobs;
use App\Http\Resources\Compact\CompactIntegrationResource;
use App\Jobs\OAuth\GitHub\GitHubActivityPull;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\IntegrationRuns\IntegrationRunService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Fixtures\IntegrationRuns\FakeRunFetchJob;
use Tests\Fixtures\IntegrationRuns\FakeRunProcessingJob;
use Tests\TestCase;

class IntegrationRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FakeRunFetchJob::$statusesSeenWhileFetching = [];
    }

    #[Test]
    public function a_run_moves_from_requested_through_processing_to_up_to_date(): void
    {
        Queue::fake();
        $integration = $this->makeIntegration();

        $batch = app(IntegrationRunService::class)->start($integration, [new FakeRunFetchJob($integration)]);

        $this->assertSame('requested', $this->runStatus($integration));
        $this->assertSame($batch->id, $integration->fresh()->lastRun()['batch_id']);
        $this->assertSame('processing', $integration->fresh()->statusKey());

        $fetch = $this->pushedJob(FakeRunFetchJob::class);
        $fetch->handle();

        $this->assertSame(['fetching'], FakeRunFetchJob::$statusesSeenWhileFetching);
        $this->assertSame('processing', $this->runStatus($integration));
        Queue::assertPushed(FakeRunProcessingJob::class, 2);
        Queue::assertPushed(FakeRunProcessingJob::class, fn (FakeRunProcessingJob $job): bool => $job->batchId === $batch->id);
        $this->assertSame(3, Bus::findBatch($batch->id)->totalJobs);

        $fetch->batch()->recordSuccessfulJob('fetch');

        $this->assertSame('processing', $this->runStatus($integration));
        $this->assertNotNull($integration->fresh()->last_successful_update_at);
        $this->assertSame('processing', $integration->fresh()->statusKey(), 'Fetched but unprocessed data is not up to date.');

        foreach (Queue::pushed(FakeRunProcessingJob::class) as $index => $processing) {
            $processing->handle();
            $processing->batch()->recordSuccessfulJob("processing-{$index}");
        }

        $run = $integration->fresh()->lastRun();
        $this->assertSame('up_to_date', $run['status']);
        $this->assertSame(3, $run['processed_jobs']);
        $this->assertSame(0, $run['failed_jobs']);
        $this->assertNotNull($run['started_at']);
        $this->assertNotNull($run['finished_at']);
        $this->assertNull($run['error']);
        $this->assertSame('up_to_date', $integration->fresh()->statusKey());
    }

    #[Test]
    public function a_run_completes_end_to_end_on_the_sync_queue(): void
    {
        $integration = $this->makeIntegration();

        app(IntegrationRunService::class)->start($integration, [new FakeRunFetchJob($integration)]);

        $run = $integration->fresh()->lastRun();
        $this->assertSame('up_to_date', $run['status']);
        $this->assertSame(3, $run['processed_jobs']);
    }

    #[Test]
    public function a_failed_processing_job_leaves_the_run_partial(): void
    {
        Queue::fake();
        $integration = $this->makeIntegration();
        app(IntegrationRunService::class)->start($integration, [new FakeRunFetchJob($integration)]);

        $fetch = $this->pushedJob(FakeRunFetchJob::class);
        $fetch->handle();
        $fetch->batch()->recordSuccessfulJob('fetch');

        [$first, $second] = Queue::pushed(FakeRunProcessingJob::class)->values()->all();
        $first->batch()->recordSuccessfulJob('processing-1');
        $second->batch()->recordFailedJob('processing-2', new RuntimeException('Could not save the event'));

        $run = $integration->fresh()->lastRun();
        $this->assertSame('partial', $run['status']);
        $this->assertSame(1, $run['failed_jobs']);
        $this->assertSame(2, $run['processed_jobs']);
        $this->assertSame('Could not save the event', $run['error']);
        $this->assertNotSame('processing', $integration->fresh()->statusKey());
    }

    #[Test]
    public function the_updates_page_flags_a_partial_run(): void
    {
        Queue::fake();
        $integration = $this->makeIntegration(['name' => 'Work activity', 'last_successful_update_at' => now()]);
        app(IntegrationRunService::class)->start($integration, [new FakeRunFetchJob($integration)]);

        $fetch = $this->pushedJob(FakeRunFetchJob::class);
        $fetch->handle();
        $fetch->batch()->recordSuccessfulJob('fetch');
        foreach (Queue::pushed(FakeRunProcessingJob::class) as $index => $processing) {
            $processing->batch()->recordFailedJob("processing-{$index}", new RuntimeException('Could not save the event'));
        }

        Volt::actingAs($integration->user)
            ->test('updates.index')
            ->assertSee('Work activity')
            ->assertSee('Partly updated');
    }

    #[Test]
    public function the_mobile_detail_explains_a_failed_run(): void
    {
        Queue::fake();
        $integration = $this->makeIntegration(['last_successful_update_at' => now()]);
        app(IntegrationRunService::class)->start($integration, [new FakeRunFetchJob($integration)]);
        $this->pushedJob(FakeRunFetchJob::class)->batch()->recordFailedJob('fetch', new RuntimeException('Provider unavailable'));

        config(['ios.mobile_api_enabled' => true]);
        Sanctum::actingAs($integration->user, ['ios:read', 'ios:write']);

        $this->getJson("/api/v1/mobile/integrations/{$integration->id}")
            ->assertOk()
            ->assertJsonPath('integration.last_run.status', 'failed')
            ->assertJsonPath('integration.last_run.error', 'Provider unavailable')
            ->assertJsonPath('status_message', 'The last update failed.');
    }

    #[Test]
    public function a_run_where_every_job_fails_is_failed(): void
    {
        Queue::fake();
        $integration = $this->makeIntegration();
        app(IntegrationRunService::class)->start($integration, [new FakeRunFetchJob($integration)]);

        $fetch = $this->pushedJob(FakeRunFetchJob::class);
        $fetch->batch()->recordFailedJob('fetch', new RuntimeException('Provider unavailable'));

        $run = $integration->fresh()->lastRun();
        $this->assertSame('failed', $run['status']);
        $this->assertSame('Provider unavailable', $run['error']);
    }

    #[Test]
    public function a_run_that_never_finishes_is_reported_as_failed_once_it_stalls(): void
    {
        Queue::fake();
        $integration = $this->makeIntegration();
        app(IntegrationRunService::class)->start($integration, [new FakeRunFetchJob($integration)]);

        $this->travel(IntegrationRunService::STALL_AFTER_MINUTES + 1)->minutes();

        $integration = $integration->fresh();
        $this->assertSame('failed', $integration->lastRun()['status']);
        $this->assertFalse($integration->hasRunInFlight());
        $this->assertFalse($integration->isProcessing());
    }

    #[Test]
    public function a_fetch_job_dispatched_outside_a_run_works_as_before(): void
    {
        Queue::fake();
        $integration = $this->makeIntegration();

        FakeRunFetchJob::dispatch($integration);
        $this->pushedJob(FakeRunFetchJob::class)->handle();

        Queue::assertPushed(FakeRunProcessingJob::class, 2);
        Queue::assertPushed(FakeRunProcessingJob::class, fn (FakeRunProcessingJob $job): bool => $job->batchId === null);
        $this->assertNull($integration->fresh()->lastRun());
        $this->assertNotNull($integration->fresh()->last_successful_update_at);
    }

    #[Test]
    public function a_fetch_job_run_synchronously_outside_a_run_still_processes(): void
    {
        $integration = $this->makeIntegration();

        FakeRunFetchJob::dispatchSync($integration);

        $this->assertNull($integration->fresh()->lastRun());
        $this->assertNotNull($integration->fresh()->last_successful_update_at);
    }

    #[Test]
    public function the_dispatcher_queues_the_fetch_jobs_as_one_run(): void
    {
        Queue::fake();
        $integration = $this->makeIntegration(['instance_type' => 'activity']);

        $this->assertSame(1, (new DispatchIntegrationFetchJobs)->dispatch($integration));

        Queue::assertPushed(GitHubActivityPull::class, fn (GitHubActivityPull $job): bool => $job->batchId !== null);
        $this->assertSame('requested', $this->runStatus($integration));
    }

    #[Test]
    public function writing_the_run_keeps_every_other_configuration_key(): void
    {
        Queue::fake();
        $integration = $this->makeIntegration(['configuration' => [
            'api_key' => 'secret-key',
            'update_frequency_minutes' => 30,
            'schedule_times' => ['04:10', '10:10'],
        ]]);

        $batch = app(IntegrationRunService::class)->start($integration, [new FakeRunFetchJob($integration)]);

        Integration::query()->whereKey($integration->id)->update(['configuration->paused' => true]);

        $this->pushedJob(FakeRunFetchJob::class)->handle();
        app(IntegrationRunService::class)->markFinished((string) $integration->id, Bus::findBatch($batch->id));

        $configuration = $integration->fresh()->configuration;
        $this->assertSame('secret-key', $configuration['api_key']);
        $this->assertSame(30, $configuration['update_frequency_minutes']);
        $this->assertSame(['04:10', '10:10'], $configuration['schedule_times']);
        $this->assertTrue($configuration['paused'], 'A key written mid-run must survive the run\'s later writes.');
        $this->assertSame('up_to_date', $configuration['last_run']['status']);
    }

    #[Test]
    public function the_run_is_written_onto_empty_or_missing_configuration(): void
    {
        Queue::fake();

        foreach ([[], null] as $configuration) {
            $integration = $this->makeIntegration(['configuration' => $configuration]);

            app(IntegrationRunService::class)->start($integration, [new FakeRunFetchJob($integration)]);

            $this->assertSame('requested', $this->runStatus($integration));
        }
    }

    #[Test]
    public function a_stale_batch_cannot_overwrite_a_newer_run(): void
    {
        Queue::fake();
        $integration = $this->makeIntegration();
        $service = app(IntegrationRunService::class);

        $older = $service->start($integration, [new FakeRunFetchJob($integration)]);
        $newer = $service->start($integration, [new FakeRunFetchJob($integration)]);

        $service->markFinished((string) $integration->id, Bus::findBatch($older->id));

        $run = $integration->fresh()->lastRun();
        $this->assertSame($newer->id, $run['batch_id']);
        $this->assertSame('requested', $run['status']);
    }

    #[Test]
    public function the_mobile_resource_exposes_the_run_without_its_batch_id(): void
    {
        Queue::fake();
        $integration = $this->makeIntegration();
        $resource = fn (): array => (new CompactIntegrationResource($integration->fresh()))->resolve(Request::create('/'));

        $this->assertNull($resource()['last_run']);

        app(IntegrationRunService::class)->start($integration, [new FakeRunFetchJob($integration)]);

        $payload = $resource();
        $this->assertSame('processing', $payload['status']);
        $this->assertSame('requested', $payload['last_run']['status']);
        $this->assertArrayNotHasKey('batch_id', $payload['last_run']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeIntegration(array $attributes = []): Integration
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'github',
            'access_token' => 'test-token',
        ]);

        return Integration::factory()->create(array_merge([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'github',
        ], $attributes));
    }

    private function runStatus(Integration $integration): ?string
    {
        return $integration->fresh()->lastRun()['status'] ?? null;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function pushedJob(string $class): object
    {
        $job = Queue::pushed($class)->first();
        $this->assertNotNull($job, "{$class} was not queued.");

        return $job;
    }
}
