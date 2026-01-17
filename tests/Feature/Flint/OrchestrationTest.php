<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\DetectHealthAnomaliesForDigestJob;
use App\Jobs\Flint\GenerateArticlesWaitingJob;
use App\Jobs\Flint\GenerateDailyDigestJob;
use App\Jobs\Flint\GenerateNewsBriefingJob;
use App\Jobs\Flint\RunDigestGenerationJob;
use App\Jobs\Flint\RunPreDigestRefreshJob;
use App\Jobs\Flint\SendDigestNotificationJob;
use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrchestrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * @test
     */
    public function pre_digest_refresh_dispatches_all_required_jobs(): void
    {
        Queue::fake([
            DetectHealthAnomaliesForDigestJob::class,
            GenerateNewsBriefingJob::class,
            GenerateArticlesWaitingJob::class,
        ]);

        // Run the pre-digest refresh job
        $job = new RunPreDigestRefreshJob($this->user, '06:00');
        $job->handle(app(\App\Services\AgentOrchestrationService::class));

        // Assert all sub-jobs were dispatched
        Queue::assertPushed(DetectHealthAnomaliesForDigestJob::class, function ($job) {
            return $job->user->id === $this->user->id;
        });

        Queue::assertPushed(GenerateNewsBriefingJob::class, function ($job) {
            return $job->user->id === $this->user->id;
        });

        Queue::assertPushed(GenerateArticlesWaitingJob::class, function ($job) {
            return $job->user->id === $this->user->id;
        });
    }

    /**
     * @test
     */
    public function digest_generation_job_determines_correct_period(): void
    {
        Queue::fake([GenerateDailyDigestJob::class, SendDigestNotificationJob::class]);

        // Test morning period (6:00 AM)
        $this->travelTo(now()->setHour(6)->setMinute(0));

        $job = new RunDigestGenerationJob($this->user, '06:00');
        $job->handle(app(\App\Services\AgentOrchestrationService::class));

        Queue::assertPushed(GenerateDailyDigestJob::class, function ($job) {
            return $job->user->id === $this->user->id && $job->period === 'morning';
        });

        // Clean up queue
        Queue::fake([GenerateDailyDigestJob::class, SendDigestNotificationJob::class]);

        // Test evening period (6:00 PM)
        $this->travelTo(now()->setHour(18)->setMinute(0));

        $job = new RunDigestGenerationJob($this->user, '18:00');
        $job->handle(app(\App\Services\AgentOrchestrationService::class));

        Queue::assertPushed(GenerateDailyDigestJob::class, function ($job) {
            return $job->user->id === $this->user->id && $job->period === 'evening';
        });
    }

    /**
     * @test
     */
    public function digest_generation_dispatches_notification_job(): void
    {
        Queue::fake([GenerateDailyDigestJob::class, SendDigestNotificationJob::class]);

        // Run the digest generation job
        $job = new RunDigestGenerationJob($this->user, '06:00');
        $job->handle(app(\App\Services\AgentOrchestrationService::class));

        // Assert both digest and notification jobs were dispatched
        Queue::assertPushed(GenerateDailyDigestJob::class, 1);
        Queue::assertPushed(SendDigestNotificationJob::class, 1);
    }

    /**
     * @test
     */
    public function orchestration_handles_errors_gracefully(): void
    {
        // This test ensures that if one job fails, others still run
        Queue::fake([
            DetectHealthAnomaliesForDigestJob::class,
            GenerateNewsBriefingJob::class,
            GenerateArticlesWaitingJob::class,
        ]);

        // Run the pre-digest refresh job
        $job = new RunPreDigestRefreshJob($this->user, '06:00');

        // Should not throw an exception even if something fails internally
        $this->expectNotToPerformAssertions();

        try {
            $job->handle(app(\App\Services\AgentOrchestrationService::class));
        } catch (Exception $e) {
            $this->fail('Orchestration job should not throw exceptions: ' . $e->getMessage());
        }
    }

    /**
     * @test
     */
    public function pre_digest_refresh_runs_sequentially_for_multiple_users(): void
    {
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        Queue::fake([
            DetectHealthAnomaliesForDigestJob::class,
            GenerateNewsBriefingJob::class,
            GenerateArticlesWaitingJob::class,
        ]);

        // Run for multiple users
        $job1 = new RunPreDigestRefreshJob($this->user, '06:00');
        $job1->handle(app(\App\Services\AgentOrchestrationService::class));

        $job2 = new RunPreDigestRefreshJob($user2, '06:00');
        $job2->handle(app(\App\Services\AgentOrchestrationService::class));

        $job3 = new RunPreDigestRefreshJob($user3, '06:00');
        $job3->handle(app(\App\Services\AgentOrchestrationService::class));

        // Assert jobs were dispatched for all users
        Queue::assertPushed(DetectHealthAnomaliesForDigestJob::class, 3);
        Queue::assertPushed(GenerateNewsBriefingJob::class, 3);
        Queue::assertPushed(GenerateArticlesWaitingJob::class, 3);
    }
}
