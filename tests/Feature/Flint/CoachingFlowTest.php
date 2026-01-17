<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\CreateCoachingSessionJob;
use App\Jobs\Flint\DetectHealthAnomaliesForDigestJob;
use App\Jobs\Flint\ProcessCoachingResponseJob;
use App\Models\EventObject;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use App\Services\AssistantPromptingService;
use App\Services\PatternLearningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class CoachingFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function detect_health_anomalies_dispatches_coaching_session_jobs(): void
    {
        // First fake the inner job, then run the detection job synchronously
        Queue::fake([CreateCoachingSessionJob::class]);

        // Create a metric statistic and anomaly for the user
        $metricStatistic = MetricStatistic::create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'score',
            'event_count' => 30,
            'mean_value' => 75,
            'stddev_value' => 10,
        ]);

        MetricTrend::create([
            'metric_statistic_id' => $metricStatistic->id,
            'type' => 'anomaly_low',
            'detected_at' => now(),
            'start_date' => now()->subDays(7),
            'end_date' => now(),
            'baseline_value' => 75,
            'current_value' => 55,
            'deviation' => 20,
            'significance_score' => 0.8,
        ]);

        // Run the detection job synchronously
        $job = new DetectHealthAnomaliesForDigestJob($this->user);
        $job->handle();

        Queue::assertPushed(CreateCoachingSessionJob::class);
    }

    /**
     * @test
     */
    public function detect_health_anomalies_skips_already_addressed_anomalies(): void
    {
        Queue::fake();

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
            'metric_statistic_id' => $metricStatistic->id,
            'type' => 'anomaly_low',
            'detected_at' => now(),
            'baseline_value' => 75,
            'current_value' => 55,
            'deviation' => 20,
            'significance_score' => 0.8,
        ]);

        // Create existing coaching session for this anomaly
        EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'coaching_session',
            'title' => 'Existing Session',
            'time' => now(),
            'metadata' => [
                'status' => 'active',
                'anomaly_id' => $anomaly->id,
            ],
        ]);

        DetectHealthAnomaliesForDigestJob::dispatch($this->user);

        Queue::assertNotPushed(CreateCoachingSessionJob::class);
    }

    /**
     * @test
     */
    public function create_coaching_session_job_creates_session_with_questions(): void
    {
        // Mock the prompting service to return test questions
        $mockPrompting = Mockery::mock(AssistantPromptingService::class);
        $mockPrompting->shouldReceive('generateResponse')
            ->once()
            ->andReturn('["Question 1?", "Question 2?", "Question 3?"]');

        $this->app->instance(AssistantPromptingService::class, $mockPrompting);

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
            'metric_statistic_id' => $metricStatistic->id,
            'type' => 'anomaly_low',
            'detected_at' => now(),
            'baseline_value' => 75,
            'current_value' => 55,
            'deviation' => 20,
            'significance_score' => 0.8,
        ]);

        $job = new CreateCoachingSessionJob($this->user, $anomaly);
        $job->handle(
            app(PatternLearningService::class),
            $mockPrompting
        );

        $session = EventObject::where('user_id', $this->user->id)
            ->where('concept', 'flint')
            ->where('type', 'coaching_session')
            ->first();

        $this->assertNotNull($session);
        $this->assertEquals('active', $session->metadata['status']);
        $this->assertCount(3, $session->metadata['ai_questions']);
    }

    /**
     * @test
     */
    public function process_coaching_response_extracts_patterns_and_completes_session(): void
    {
        // Mock the prompting service to return extracted patterns
        $mockPrompting = Mockery::mock(AssistantPromptingService::class);
        $mockPrompting->shouldReceive('generateResponse')
            ->once()
            ->andReturn('[{"title": "Late Work Pattern", "trigger_conditions": {"activity": "working late"}, "consequences": {"metric": "sleep", "effect": "decreases"}, "user_explanation": "Working late affects my sleep", "domains": ["health"], "confidence": 0.4}]');

        $this->app->instance(AssistantPromptingService::class, $mockPrompting);

        $session = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'coaching_session',
            'title' => 'Test Session',
            'time' => now(),
            'metadata' => [
                'status' => 'active',
                'anomaly_id' => null, // No anomaly linked for this test
                'anomaly_context' => [
                    'metric_name' => 'Sleep Score',
                    'service' => 'oura',
                    'action' => 'had_sleep_score',
                    'type_label' => 'Anomaly Low',
                    'deviation_percent' => 25,
                ],
                'ai_questions' => ['Question 1?'],
            ],
        ]);

        $userResponse = 'I have been working late and stressed.';

        $job = new ProcessCoachingResponseJob($this->user, $session, $userResponse);
        $job->handle(
            app(PatternLearningService::class),
            $mockPrompting,
            app(\App\Services\FlintBlockCreationService::class)
        );

        // Refresh session
        $session->refresh();

        $this->assertEquals('completed', $session->metadata['status']);
        $this->assertEquals($userResponse, $session->metadata['user_response']);

        // Check pattern was created
        $pattern = EventObject::where('user_id', $this->user->id)
            ->where('concept', 'flint')
            ->where('type', 'learned_pattern')
            ->where('title', 'Late Work Pattern')
            ->first();

        $this->assertNotNull($pattern);
    }

    /**
     * @test
     */
    public function coaching_session_can_be_dismissed(): void
    {
        $session = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'coaching_session',
            'title' => 'Test Session',
            'time' => now(),
            'metadata' => ['status' => 'active'],
        ]);

        $patternLearning = app(PatternLearningService::class);
        $patternLearning->dismissCoachingSession($session);

        $session->refresh();

        $this->assertEquals('dismissed', $session->metadata['status']);
        $this->assertNotNull($session->metadata['dismissed_at']);
    }

    /**
     * @test
     */
    public function coaching_flow_acknowledges_source_anomaly(): void
    {
        $mockPrompting = Mockery::mock(AssistantPromptingService::class);
        $mockPrompting->shouldReceive('generateResponse')
            ->once()
            ->andReturn('[]');

        $this->app->instance(AssistantPromptingService::class, $mockPrompting);

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
            'metric_statistic_id' => $metricStatistic->id,
            'type' => 'anomaly_low',
            'detected_at' => now(),
            'baseline_value' => 75,
            'current_value' => 55,
            'deviation' => 20,
            'significance_score' => 0.8,
        ]);

        $session = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'coaching_session',
            'title' => 'Test Session',
            'time' => now(),
            'metadata' => [
                'status' => 'active',
                'anomaly_id' => $anomaly->id,
                'anomaly_context' => [
                    'metric_name' => 'Sleep Score',
                    'type_label' => 'Anomaly Low',
                    'deviation_percent' => 25,
                ],
                'ai_questions' => ['Question 1?'],
            ],
        ]);

        $job = new ProcessCoachingResponseJob($this->user, $session, 'Test response');
        $job->handle(
            app(PatternLearningService::class),
            $mockPrompting,
            app(\App\Services\FlintBlockCreationService::class)
        );

        // Refresh anomaly
        $anomaly->refresh();

        $this->assertNotNull($anomaly->acknowledged_at);
    }
}
