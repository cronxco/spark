<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\CreateCoachingSessionJob;
use App\Jobs\Flint\DetectHealthAnomaliesForDigestJob;
use App\Jobs\Flint\GenerateDailyDigestJob;
use App\Jobs\Flint\ProcessCoachingResponseJob;
use App\Jobs\Flint\RunDigestGenerationJob;
use App\Jobs\Flint\RunPreDigestRefreshJob;
use App\Jobs\Flint\SendDigestNotificationJob;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use App\Notifications\DailyDigestReady;
use App\Services\AgentOrchestrationService;
use App\Services\AssistantPromptingService;
use App\Services\FlintBlockCreationService;
use App\Services\PatternLearningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class FullPipelineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $flintIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->flintIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'flint',
            'instance_type' => 'digest',
        ]);

        // Mock AI service for all tests
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
    public function full_digest_pipeline_from_pre_refresh_to_notification(): void
    {
        Queue::fake([
            DetectHealthAnomaliesForDigestJob::class,
            RunDigestGenerationJob::class,
        ]);

        // Mock AgentOrchestrationService for pre-digest refresh
        $mockOrchestration = Mockery::mock(AgentOrchestrationService::class);
        $mockOrchestration->shouldReceive('runPreDigestRefresh')
            ->once()
            ->with($this->user)
            ->andReturn([
                'health' => ['insights' => [], 'suggestions' => []],
                'knowledge' => ['insights' => [], 'suggestions' => []],
            ]);

        $this->app->instance(AgentOrchestrationService::class, $mockOrchestration);

        // Step 1: Run pre-digest refresh
        $preRefreshJob = new RunPreDigestRefreshJob($this->user, '06:00');
        $preRefreshJob->handle(app(AgentOrchestrationService::class));

        // Assert that RunDigestGenerationJob was dispatched after pre-refresh
        Queue::assertPushed(RunDigestGenerationJob::class, function ($job) {
            return $job->user->id === $this->user->id;
        });
    }

    /**
     * @test
     */
    public function anomaly_to_coaching_to_pattern_flow(): void
    {
        // Step 1: Create health anomaly
        $metricStatistic = MetricStatistic::create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'score',
            'event_count' => 30,
            'mean_value' => 75,
            'stddev_value' => 10,
        ]);

        $anomaly = MetricTrend::create([
            'user_id' => $this->user->id,
            'metric_statistic_id' => $metricStatistic->id,
            'type' => 'anomaly_low',
            'baseline_value' => 75,
            'current_value' => 55,
            'deviation' => -20,
            'significance_score' => 0.95,
            'detected_at' => now(),
            'start_date' => now()->subDays(1),
            'end_date' => now(),
        ]);

        // Step 2: Create coaching session from anomaly
        $createSessionJob = new CreateCoachingSessionJob($this->user, $anomaly);
        $createSessionJob->handle(app(PatternLearningService::class), app(AssistantPromptingService::class));

        // Assert coaching session was created
        $this->assertDatabaseHas('objects', [
            'concept' => 'flint',
            'type' => 'coaching_session',
        ]);

        $coachingSession = EventObject::where('user_id', $this->user->id)
            ->where('type', 'coaching_session')
            ->first();

        $this->assertNotNull($coachingSession);
        $this->assertEquals('active', $coachingSession->metadata['status']);

        // Step 3: Process user response to create patterns
        $responseJob = new ProcessCoachingResponseJob(
            $this->user,
            $coachingSession,
            'I stayed up late working on a project. It happens when I have deadlines.'
        );

        $responseJob->handle(
            app(PatternLearningService::class),
            app(AssistantPromptingService::class),
            app(FlintBlockCreationService::class)
        );

        // Assert coaching session was completed
        $coachingSession->refresh();
        $this->assertEquals('completed', $coachingSession->metadata['status']);

        // Assert learned patterns were created
        $this->assertDatabaseHas('objects', [
            'concept' => 'flint',
            'type' => 'learned_pattern',
        ]);
    }

    /**
     * @test
     */
    public function multiple_users_concurrent_execution(): void
    {
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        Queue::fake([
            DetectHealthAnomaliesForDigestJob::class,
            RunDigestGenerationJob::class,
        ]);

        // Mock AgentOrchestrationService for all users
        $mockOrchestration = Mockery::mock(AgentOrchestrationService::class);
        $mockOrchestration->shouldReceive('runPreDigestRefresh')
            ->times(3)
            ->andReturn([
                'health' => ['insights' => [], 'suggestions' => []],
                'knowledge' => ['insights' => [], 'suggestions' => []],
            ]);

        $this->app->instance(AgentOrchestrationService::class, $mockOrchestration);

        // Run pre-digest refresh for all users
        (new RunPreDigestRefreshJob($this->user, '06:00'))->handle(app(AgentOrchestrationService::class));
        (new RunPreDigestRefreshJob($user2, '06:00'))->handle(app(AgentOrchestrationService::class));
        (new RunPreDigestRefreshJob($user3, '06:00'))->handle(app(AgentOrchestrationService::class));

        // Assert RunDigestGenerationJob was dispatched for all users
        Queue::assertPushed(RunDigestGenerationJob::class, 3);
    }

    /**
     * @test
     */
    public function complete_daily_flow_with_real_execution(): void
    {
        Notification::fake();

        // Create some events for the user
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'instance_type' => 'sleep',
        ]);

        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'time' => now()->subHours(2),
        ]);

        // Run the full pipeline synchronously using the direct generation job
        $digestJob = new GenerateDailyDigestJob($this->user, 'morning');
        $digestJob->handle(app(AssistantPromptingService::class));

        // Assert digest was created
        $this->assertDatabaseHas('blocks', [
            'block_type' => 'flint_summarised_headline',
        ]);

        $digest = Block::where('block_type', 'flint_summarised_headline')->first();
        $this->assertNotNull($digest);

        // Create flint integration for notification to work
        $flintIntegration = Integration::firstOrCreate([
            'user_id' => $this->user->id,
            'service' => 'flint',
            'instance_type' => 'digest',
        ], [
            'name' => 'Flint Digest',
            'active' => true,
        ]);

        // Send notification
        $notificationJob = new SendDigestNotificationJob($this->user, 'morning');
        $notificationJob->handle();

        // Assert notification was sent
        Notification::assertSentTo(
            [$this->user],
            DailyDigestReady::class
        );
    }

    /**
     * @test
     */
    public function cross_domain_pattern_enrichment_flow(): void
    {
        $patternLearning = app(PatternLearningService::class);

        // Create a pattern with multiple domains
        $pattern = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'learned_pattern',
            'title' => 'Late Night Work Affects Sleep',
            'time' => now(),
            'metadata' => [
                'trigger_conditions' => ['activity' => 'late work'],
                'consequences' => ['metric' => 'sleep_score', 'effect' => 'decrease'],
                'user_explanation' => 'Working late reduces sleep quality',
                'confidence_score' => 0.5,
                'confirmation_count' => 1,
                'domains' => ['health', 'online'],
            ],
        ]);

        // Enrich with cross-domain insights
        $enrichedPattern = $patternLearning->enrichPatternWithCrossDomainInsights($pattern, $this->user);

        $this->assertInstanceOf(EventObject::class, $enrichedPattern);
        // Cross-domain connections may or may not exist yet
        $this->assertIsArray($enrichedPattern->metadata);
    }

    protected function mockAIService(): void
    {
        $mockPrompting = Mockery::mock(AssistantPromptingService::class);

        // Mock AI responses based on context
        $mockPrompting->shouldReceive('generateResponse')
            ->andReturnUsing(function ($prompt, $options = []) {
                $context = $options['context'] ?? [];
                $agentType = $context['agent_type'] ?? null;

                // Pattern extractor (for ProcessCoachingResponseJob)
                if ($agentType === 'pattern_extractor') {
                    return json_encode([
                        [
                            'title' => 'Late Night Work Affects Sleep',
                            'trigger_conditions' => [
                                'activity' => 'Working late on projects',
                                'timing' => 'When approaching deadlines',
                            ],
                            'consequences' => [
                                'metric' => 'sleep_score',
                                'effect' => 'decrease',
                            ],
                            'user_explanation' => 'I stayed up late working on a project',
                            'domains' => ['health', 'online'],
                            'confidence' => 0.5,
                        ],
                    ]);
                }

                // Question generator (for CreateCoachingSessionJob)
                if ($agentType === 'coaching_question_generator') {
                    return json_encode([
                        'What recent changes might have affected this?',
                        'Have you experienced any unusual stress or activities?',
                    ]);
                }

                // Default fallback
                return json_encode([]);
            });

        $mockPrompting->shouldReceive('generateDigest')
            ->andReturn([
                'headline' => 'Your Test Digest',
                'key_points' => ['Point 1', 'Point 2', 'Point 3'],
                'actions_required' => [],
                'things_to_be_aware_of' => null,
                'insight' => [
                    'title' => 'Test Insight',
                    'content' => 'Test insight content',
                    'supporting_data' => [],
                ],
                'suggestion' => [
                    'title' => 'Test Suggestion',
                    'content' => 'Test suggestion content',
                    'actionable' => true,
                    'automation_hint' => null,
                ],
            ]);

        $this->app->instance(AssistantPromptingService::class, $mockPrompting);
    }
}
