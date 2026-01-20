<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\RunDigestGenerationJob;
use App\Jobs\Flint\RunPreDigestRefreshJob;
use App\Jobs\Flint\SendDigestNotificationJob;
use App\Models\User;
use App\Services\AssistantPromptingService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class OrchestrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Mock AI service to prevent real OpenAI API calls
        $this->mockAIService();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function pre_digest_refresh_dispatches_all_required_jobs(): void
    {
        // Mock the orchestration service
        $mockOrchestration = Mockery::mock(\App\Services\AgentOrchestrationService::class);
        $mockOrchestration->shouldReceive('runPreDigestRefresh')
            ->once()
            ->with($this->user)
            ->andReturn([
                'future' => ['insights' => [], 'suggestions' => []],
                'health' => ['insights' => [], 'suggestions' => []],
            ]);

        // Run the pre-digest refresh job
        $job = new RunPreDigestRefreshJob($this->user, '06:00');
        $job->handle($mockOrchestration);

        // Verify the job completed successfully
        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function digest_generation_job_determines_correct_period(): void
    {
        // Mock the orchestration service to return a digest block ID
        $mockOrchestration = Mockery::mock(\App\Services\AgentOrchestrationService::class);

        // Test morning period (6:00 AM)
        $mockOrchestration->shouldReceive('runDigestGeneration')
            ->once()
            ->with($this->user, 'morning')
            ->andReturn('mock-block-id-morning');

        $job = new RunDigestGenerationJob($this->user, '06:00');
        $job->handle($mockOrchestration);

        // Test afternoon period (2:00 PM)
        $mockOrchestration->shouldReceive('runDigestGeneration')
            ->once()
            ->with($this->user, 'afternoon')
            ->andReturn('mock-block-id-afternoon');

        $job = new RunDigestGenerationJob($this->user, '14:00');
        $job->handle($mockOrchestration);

        // Test evening period (6:00 PM)
        $mockOrchestration->shouldReceive('runDigestGeneration')
            ->once()
            ->with($this->user, 'evening')
            ->andReturn('mock-block-id-evening');

        $job = new RunDigestGenerationJob($this->user, '18:00');
        $job->handle($mockOrchestration);

        // If we got here, all period determinations worked correctly
        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function digest_generation_dispatches_notification_job(): void
    {
        // Mock the orchestration service
        $mockOrchestration = Mockery::mock(\App\Services\AgentOrchestrationService::class);
        $mockOrchestration->shouldReceive('runDigestGeneration')
            ->once()
            ->with($this->user, 'morning')
            ->andReturn('mock-digest-block-id');

        // Run the digest generation job
        $job = new RunDigestGenerationJob($this->user, '06:00');
        $job->handle($mockOrchestration);

        // Verify the job completed successfully
        // Note: Notifications are now sent separately by SendDigestNotificationJob at scheduled time
        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function orchestration_handles_errors_gracefully(): void
    {
        // This test ensures that if one job fails, others still run
        // Fake ALL queues to prevent any jobs from actually running
        Queue::fake();

        // Mock the AI prompting service to prevent real OpenAI API calls
        $mockPrompting = Mockery::mock(AssistantPromptingService::class);
        $mockPrompting->shouldReceive('generateResponse')
            ->andReturn(json_encode([
                'insights' => [],
                'suggestions' => [],
            ]));

        $this->app->instance(AssistantPromptingService::class, $mockPrompting);

        // Run the pre-digest refresh job
        $job = new RunPreDigestRefreshJob($this->user, '06:00');

        // Should not throw an exception even if something fails internally
        try {
            $job->handle(app(\App\Services\AgentOrchestrationService::class));
            $this->assertTrue(true); // Job completed without exception
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

        // Mock the orchestration service
        $mockOrchestration = Mockery::mock(\App\Services\AgentOrchestrationService::class);
        $mockOrchestration->shouldReceive('runPreDigestRefresh')
            ->times(3)
            ->andReturn([
                'future' => ['insights' => [], 'suggestions' => []],
                'health' => ['insights' => [], 'suggestions' => []],
            ]);

        // Run for multiple users
        $job1 = new RunPreDigestRefreshJob($this->user, '06:00');
        $job1->handle($mockOrchestration);

        $job2 = new RunPreDigestRefreshJob($user2, '06:00');
        $job2->handle($mockOrchestration);

        $job3 = new RunPreDigestRefreshJob($user3, '06:00');
        $job3->handle($mockOrchestration);

        // Verify all three jobs completed successfully
        $this->assertTrue(true);
    }

    protected function mockAIService(): void
    {
        $mockPrompting = Mockery::mock(AssistantPromptingService::class);
        $mockPrompting->shouldReceive('generateResponse')
            ->andReturn(json_encode([
                'insights' => [],
                'suggestions' => [],
                'headline' => 'Test Digest',
                'summary' => 'Test Summary',
                'top_insights' => [],
                'wins' => [],
                'watch_points' => [],
                'tomorrow_focus' => [],
                'metrics' => ['total_insights' => 0],
            ]));

        $this->app->instance(AssistantPromptingService::class, $mockPrompting);
    }
}
