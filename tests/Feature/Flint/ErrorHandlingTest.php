<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\GenerateDailyDigestJob;
use App\Jobs\Flint\ProcessCoachingResponseJob;
use App\Models\EventObject;
use App\Models\User;
use App\Services\AssistantPromptingService;
use App\Services\FlintBlockCreationService;
use App\Services\PatternLearningService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class ErrorHandlingTest extends TestCase
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
    public function digest_handles_ai_service_failure_gracefully(): void
    {
        Log::spy();

        // Mock AI service to throw an exception
        $mockPrompting = Mockery::mock(AssistantPromptingService::class);
        $mockPrompting->shouldReceive('generateResponse')
            ->once()
            ->andThrow(new Exception('AI service unavailable'));

        $this->app->instance(AssistantPromptingService::class, $mockPrompting);

        // Run the job - should not throw exception
        $job = new GenerateDailyDigestJob($this->user, 'morning');

        try {
            $job->handle(app(FlintBlockCreationService::class), app(AssistantPromptingService::class));
        } catch (Exception $e) {
            // Job should handle the exception internally
        }

        // Assert error was logged
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, '[Flint]') || str_contains($message, 'digest');
            });
    }

    /**
     * @test
     */
    public function coaching_response_handles_json_parsing_errors(): void
    {
        // Mock AI service to return malformed JSON
        $mockPrompting = Mockery::mock(AssistantPromptingService::class);
        $mockPrompting->shouldReceive('generateResponse')
            ->once()
            ->andReturn('This is not valid JSON {broken');

        $this->app->instance(AssistantPromptingService::class, $mockPrompting);

        // Create coaching session
        $coachingSession = EventObject::factory()->create([
            'concept' => 'flint',
            'type' => 'coaching_session',
            'metadata' => [
                'status' => 'active',
                'anomaly_context' => [
                    'metric_name' => 'Sleep Score',
                    'type_label' => 'Low',
                    'deviation_percent' => 15,
                ],
                'ai_questions' => ['Why did this happen?'],
            ],
        ]);

        // Run the job - should handle malformed JSON gracefully
        $job = new ProcessCoachingResponseJob(
            $this->user,
            $coachingSession,
            'I stayed up late working'
        );

        try {
            $job->handle(
                app(PatternLearningService::class),
                app(AssistantPromptingService::class),
                app(FlintBlockCreationService::class)
            );
            $this->assertTrue(true); // Job completed without exception
        } catch (Exception $e) {
            // Should not throw - should handle gracefully
            $this->fail('Job should handle malformed JSON gracefully: ' . $e->getMessage());
        }
    }

    /**
     * @test
     */
    public function pattern_learning_handles_missing_metadata(): void
    {
        $patternLearning = app(PatternLearningService::class);

        // Create a coaching session with minimal metadata
        $coachingSession = EventObject::factory()->create([
            'concept' => 'flint',
            'type' => 'coaching_session',
            'metadata' => [
                'status' => 'active',
                // Missing anomaly_context, ai_questions, etc.
            ],
        ]);

        // Should not throw an exception
        try {
            $result = $patternLearning->processCoachingResponse(
                $coachingSession,
                'Test response',
                []
            );

            $this->assertInstanceOf(EventObject::class, $result);
        } catch (Exception $e) {
            $this->fail('Pattern learning should handle missing metadata: ' . $e->getMessage());
        }
    }

    /**
     * @test
     */
    public function digest_handles_database_transaction_failure(): void
    {
        Log::spy();

        // Create a scenario where database write might fail
        // Mock the block creation service to throw an exception
        $mockBlockService = Mockery::mock(FlintBlockCreationService::class);
        $mockBlockService->shouldReceive('getOrCreateFlintEvent')
            ->andThrow(new Exception('Database connection error'));

        $this->app->instance(FlintBlockCreationService::class, $mockBlockService);

        // Mock AI service
        $mockPrompting = Mockery::mock(AssistantPromptingService::class);
        $mockPrompting->shouldReceive('generateResponse')
            ->andReturn(json_encode([
                'headline' => 'Test',
                'summary' => 'Test',
                'top_insights' => [],
                'wins' => [],
                'watch_points' => [],
                'tomorrow_focus' => [],
                'metrics' => ['total_insights' => 0],
            ]));

        $this->app->instance(AssistantPromptingService::class, $mockPrompting);

        $job = new GenerateDailyDigestJob($this->user, 'morning');

        try {
            $job->handle(app(FlintBlockCreationService::class), app(AssistantPromptingService::class));
        } catch (Exception $e) {
            // Expected - database error occurred
        }

        // Assert error was logged
        Log::shouldHaveReceived('error');
    }

    /**
     * @test
     */
    public function pattern_extraction_handles_empty_ai_response(): void
    {
        // Mock AI service to return empty response
        $mockPrompting = Mockery::mock(AssistantPromptingService::class);
        $mockPrompting->shouldReceive('generateResponse')
            ->once()
            ->andReturn('');

        $this->app->instance(AssistantPromptingService::class, $mockPrompting);

        // Create coaching session
        $coachingSession = EventObject::factory()->create([
            'concept' => 'flint',
            'type' => 'coaching_session',
            'metadata' => [
                'status' => 'active',
                'anomaly_context' => [
                    'metric_name' => 'Sleep Score',
                    'type_label' => 'Low',
                    'deviation_percent' => 15,
                ],
                'ai_questions' => ['Test question'],
            ],
        ]);

        // Run the job
        $job = new ProcessCoachingResponseJob(
            $this->user,
            $coachingSession,
            'Test response'
        );

        // Should handle empty response gracefully
        try {
            $job->handle(
                app(PatternLearningService::class),
                app(AssistantPromptingService::class),
                app(FlintBlockCreationService::class)
            );
            $this->assertTrue(true); // Job completed without exception
        } catch (Exception $e) {
            $this->fail('Job should handle empty AI response: ' . $e->getMessage());
        }
    }
}
