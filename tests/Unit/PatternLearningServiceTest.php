<?php

namespace Tests\Unit;

use App\Models\EventObject;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use App\Services\PatternLearningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatternLearningServiceTest extends TestCase
{
    use RefreshDatabase;

    private PatternLearningService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PatternLearningService::class);
        $this->user = User::factory()->create();
    }

    /**
     * @test
     */
    public function creates_coaching_session_for_anomaly(): void
    {
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
            'start_date' => now()->subDays(7),
            'end_date' => now(),
            'baseline_value' => 75,
            'current_value' => 55,
            'deviation' => 20,
            'significance_score' => 0.8,
        ]);

        $questions = [
            'Have you been under more stress lately?',
            'Any changes to your sleep routine?',
        ];

        $session = $this->service->createCoachingSession(
            $this->user,
            $anomaly,
            $questions
        );

        $this->assertInstanceOf(EventObject::class, $session);
        $this->assertEquals('flint', $session->concept);
        $this->assertEquals('coaching_session', $session->type);
        $this->assertEquals('active', $session->metadata['status']);
        $this->assertEquals($anomaly->id, $session->metadata['anomaly_id']);
        $this->assertEquals($questions, $session->metadata['ai_questions']);
    }

    /**
     * @test
     */
    public function processes_coaching_response_and_extracts_patterns(): void
    {
        $session = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'coaching_session',
            'title' => 'Test Session',
            'time' => now(),
            'metadata' => [
                'status' => 'active',
                'anomaly_id' => 'test-anomaly-id',
                'ai_questions' => ['Question 1?'],
            ],
        ]);

        $userResponse = 'I have been working late nights and not sleeping well.';
        $extractedPatterns = [
            [
                'title' => 'Late Night Work',
                'trigger_conditions' => ['activity' => 'working late'],
                'consequences' => ['metric' => 'sleep_score', 'effect' => 'decreases'],
                'user_explanation' => 'Working late causes poor sleep',
                'domains' => ['health'],
                'confidence' => 0.4,
            ],
        ];

        $updatedSession = $this->service->processCoachingResponse(
            $session,
            $userResponse,
            $extractedPatterns
        );

        $this->assertEquals('completed', $updatedSession->metadata['status']);
        $this->assertEquals($userResponse, $updatedSession->metadata['user_response']);
        $this->assertNotNull($updatedSession->metadata['responded_at']);

        // Check that pattern was stored
        $learnedPattern = EventObject::where('user_id', $this->user->id)
            ->where('concept', 'flint')
            ->where('type', 'learned_pattern')
            ->where('title', 'Late Night Work')
            ->first();

        $this->assertNotNull($learnedPattern);
        $this->assertEquals('Working late causes poor sleep', $learnedPattern->metadata['user_explanation']);
    }

    /**
     * @test
     */
    public function stores_learned_pattern(): void
    {
        $patternData = [
            'title' => 'Coffee Before Bed',
            'trigger_conditions' => ['activity' => 'drinking coffee', 'timing' => 'evening'],
            'consequences' => ['metric' => 'sleep_score', 'effect' => 'decreases'],
            'user_explanation' => 'Coffee in the evening disrupts my sleep',
            'domains' => ['health'],
        ];

        $pattern = $this->service->storeLearnedPattern(
            $this->user->id,
            $patternData
        );

        $this->assertInstanceOf(EventObject::class, $pattern);
        $this->assertEquals('flint', $pattern->concept);
        $this->assertEquals('learned_pattern', $pattern->type);
        $this->assertEquals('Coffee Before Bed', $pattern->title);
        $this->assertEquals(0.3, $pattern->metadata['confidence_score']);
        $this->assertEquals(1, $pattern->metadata['confirmation_count']);
    }

    /**
     * @test
     */
    public function updates_pattern_confidence_on_reconfirmation(): void
    {
        // Create initial pattern
        $patternData = [
            'title' => 'Alcohol Effects',
            'trigger_conditions' => ['activity' => 'drinking alcohol'],
            'consequences' => ['metric' => 'hrv', 'effect' => 'decreases'],
            'user_explanation' => 'Alcohol lowers my HRV',
            'domains' => ['health'],
        ];

        $pattern = $this->service->storeLearnedPattern($this->user->id, $patternData);
        $initialConfidence = $pattern->metadata['confidence_score'];

        // Reconfirm the same pattern
        $updatedPattern = $this->service->storeLearnedPattern($this->user->id, $patternData);

        $this->assertEquals($pattern->id, $updatedPattern->id);
        $this->assertGreaterThan($initialConfidence, $updatedPattern->metadata['confidence_score']);
        $this->assertEquals(2, $updatedPattern->metadata['confirmation_count']);
    }

    /**
     * @test
     */
    public function finds_relevant_patterns_for_anomaly(): void
    {
        // Create some learned patterns
        EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'learned_pattern',
            'title' => 'Sleep Pattern',
            'time' => now(),
            'metadata' => [
                'trigger_conditions' => ['service' => 'oura', 'action' => 'had_sleep_score'],
                'consequences' => ['metric' => 'oura.had_sleep_score.score'],
                'user_explanation' => 'Test pattern',
                'confidence_score' => 0.6,
                'confirmation_count' => 2,
            ],
        ]);

        EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'learned_pattern',
            'title' => 'Unrelated Pattern',
            'time' => now(),
            'metadata' => [
                'trigger_conditions' => ['service' => 'monzo'],
                'consequences' => ['metric' => 'spending'],
                'user_explanation' => 'Unrelated',
                'confidence_score' => 0.5,
                'confirmation_count' => 1,
            ],
        ]);

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

        $relevantPatterns = $this->service->findRelevantPatterns($this->user, $anomaly);

        $this->assertCount(1, $relevantPatterns);
        $this->assertEquals('Sleep Pattern', $relevantPatterns->first()->title);
    }

    /**
     * @test
     */
    public function gets_active_coaching_sessions(): void
    {
        // Create active session
        EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'coaching_session',
            'title' => 'Active Session',
            'time' => now(),
            'metadata' => ['status' => 'active'],
        ]);

        // Create completed session
        EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'coaching_session',
            'title' => 'Completed Session',
            'time' => now()->subDay(),
            'metadata' => ['status' => 'completed'],
        ]);

        $activeSessions = $this->service->getActiveCoachingSessions($this->user);

        $this->assertCount(1, $activeSessions);
        $this->assertEquals('Active Session', $activeSessions->first()->title);
    }

    /**
     * @test
     */
    public function dismisses_coaching_session(): void
    {
        $session = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'coaching_session',
            'title' => 'Test Session',
            'time' => now(),
            'metadata' => ['status' => 'active'],
        ]);

        $dismissedSession = $this->service->dismissCoachingSession($session);

        $this->assertEquals('dismissed', $dismissedSession->metadata['status']);
        $this->assertNotNull($dismissedSession->metadata['dismissed_at']);
    }

    /**
     * @test
     */
    public function gets_learned_patterns_with_minimum_confidence(): void
    {
        EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'learned_pattern',
            'title' => 'High Confidence Pattern',
            'time' => now(),
            'metadata' => ['confidence_score' => 0.8],
        ]);

        EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'learned_pattern',
            'title' => 'Low Confidence Pattern',
            'time' => now(),
            'metadata' => ['confidence_score' => 0.2],
        ]);

        $highConfidencePatterns = $this->service->getLearnedPatterns($this->user, 0.5);

        $this->assertCount(1, $highConfidencePatterns);
        $this->assertEquals('High Confidence Pattern', $highConfidencePatterns->first()->title);
    }

    /**
     * @test
     */
    public function suggests_explanations_based_on_learned_patterns(): void
    {
        EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'learned_pattern',
            'title' => 'Late Night Pattern',
            'time' => now(),
            'metadata' => [
                'trigger_conditions' => ['service' => 'oura'],
                'consequences' => ['metric' => 'oura.had_sleep_score.score'],
                'user_explanation' => 'Late nights affect my sleep',
                'confidence_score' => 0.7,
                'confirmation_count' => 3,
            ],
        ]);

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

        $suggestions = $this->service->suggestExplanations($this->user, $anomaly);

        $this->assertNotEmpty($suggestions);
        $this->assertEquals('Late nights affect my sleep', $suggestions[0]['suggestion']);
        $this->assertEquals(0.7, $suggestions[0]['confidence']);
        $this->assertEquals(3, $suggestions[0]['confirmations']);
    }
}
