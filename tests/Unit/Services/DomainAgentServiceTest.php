<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\DomainAgentService;
use ReflectionClass;
use Tests\TestCase;

class DomainAgentServiceTest extends TestCase
{
    protected DomainAgentService $service;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DomainAgentService;
        $this->user = User::factory()->make(['id' => 1]);
    }

    /** @test */
    public function it_builds_domain_prompt_with_all_sections()
    {
        $events = [
            [
                'service' => 'oura',
                'action' => 'had_sleep',
                'time' => '2024-01-01 08:00:00',
                'value' => 85,
                'value_unit' => 'score',
            ],
        ];

        $learning = [
            'successful_insights' => [
                ['type' => 'observation', 'rating' => 5],
            ],
        ];

        $feedbackStats = [
            'rating_average' => 4.5,
            'total_feedback_count' => 10,
            'dismissed_count' => 1,
        ];

        $queries = [
            [
                'from_domain' => 'money',
                'question' => 'Did health affect spending?',
            ],
        ];

        $prompt = $this->service->buildDomainPrompt(
            $this->user,
            'health',
            $events,
            $learning,
            $feedbackStats,
            $queries
        );

        $this->assertStringContainsString('Health Domain Agent', $prompt);
        $this->assertStringContainsString('Recent Activity', $prompt);
        $this->assertStringContainsString('oura', $prompt);
        $this->assertStringContainsString('Learning from Past Insights', $prompt);
        $this->assertStringContainsString('Questions from Other Agents', $prompt);
    }

    /** @test */
    public function it_returns_correct_system_prompt_for_health_domain()
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('getSystemPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->service, 'health');

        $this->assertStringContainsString('Health Domain Agent', $prompt);
        $this->assertStringContainsString('health coach', $prompt);
        $this->assertStringContainsString('sleep', $prompt);
        $this->assertStringContainsString('HRV', $prompt);
    }

    /** @test */
    public function it_returns_correct_system_prompt_for_money_domain()
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('getSystemPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->service, 'money');

        $this->assertStringContainsString('Money Domain Agent', $prompt);
        $this->assertStringContainsString('spending patterns', $prompt);
        $this->assertStringContainsString('matter-of-fact', $prompt);
    }

    /** @test */
    public function it_returns_correct_system_prompt_for_media_domain()
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('getSystemPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->service, 'media');

        $this->assertStringContainsString('Media Domain Agent', $prompt);
        $this->assertStringContainsString('music', $prompt);
        $this->assertStringContainsString('listening habits', $prompt);
    }

    /** @test */
    public function it_returns_correct_system_prompt_for_knowledge_domain()
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('getSystemPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->service, 'knowledge');

        $this->assertStringContainsString('Knowledge Domain Agent', $prompt);
        $this->assertStringContainsString('learning', $prompt);
        $this->assertStringContainsString('Fetch', $prompt);
    }

    /** @test */
    public function it_returns_correct_system_prompt_for_online_domain()
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('getSystemPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->service, 'online');

        $this->assertStringContainsString('Online Domain Agent', $prompt);
        $this->assertStringContainsString('productivity', $prompt);
        $this->assertStringContainsString('Todoist', $prompt);
    }

    /** @test */
    public function it_returns_generic_prompt_for_unknown_domain()
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('getSystemPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->service, 'custom_domain');

        $this->assertStringContainsString('custom_domain domain', $prompt);
        $this->assertStringContainsString('Domain Agent', $prompt);
    }

    /** @test */
    public function it_formats_events_context_correctly()
    {
        $events = [
            [
                'service' => 'oura',
                'action' => 'had_sleep',
                'time' => '2024-01-01 08:00:00',
                'value' => 85,
                'value_unit' => 'score',
                'event_metadata' => [],
            ],
            [
                'service' => 'oura',
                'action' => 'had_sleep',
                'time' => '2024-01-02 08:00:00',
                'value' => 78,
                'value_unit' => 'score',
                'event_metadata' => [],
            ],
            [
                'service' => 'strava',
                'action' => 'completed_run',
                'time' => '2024-01-01 18:00:00',
                'value' => 5000,
                'value_unit' => 'meters',
                'event_metadata' => [],
            ],
        ];

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('formatEventsContext');
        $method->setAccessible(true);

        $formatted = $method->invoke($this->service, $events, 'health');

        $this->assertStringContainsString('Found 3 events', $formatted);
        $this->assertStringContainsString('oura', $formatted);
        $this->assertStringContainsString('strava', $formatted);
        $this->assertStringContainsString('had_sleep', $formatted);
        $this->assertStringContainsString('completed_run', $formatted);
    }

    /** @test */
    public function it_groups_events_by_service_and_action()
    {
        $events = [
            ['service' => 'oura', 'action' => 'had_sleep', 'time' => '2024-01-01', 'event_metadata' => []],
            ['service' => 'oura', 'action' => 'had_sleep', 'time' => '2024-01-02', 'event_metadata' => []],
            ['service' => 'strava', 'action' => 'completed_run', 'time' => '2024-01-01', 'event_metadata' => []],
        ];

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('formatEventsContext');
        $method->setAccessible(true);

        $formatted = $method->invoke($this->service, $events, 'health');

        // Should show count of 2 for oura:had_sleep
        $this->assertStringContainsString('2 events', $formatted);
    }

    /** @test */
    public function it_handles_empty_events_gracefully()
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('formatEventsContext');
        $method->setAccessible(true);

        $formatted = $method->invoke($this->service, [], 'health');

        $this->assertEquals('No recent activity in this domain.', $formatted);
    }

    /** @test */
    public function it_formats_learning_context_with_feedback_stats()
    {
        $learning = [
            'successful_insights' => [
                ['type' => 'observation', 'rating' => 5],
            ],
            'user_preferences' => [
                'insight_length' => 'concise',
            ],
        ];

        $feedbackStats = [
            'rating_average' => 4.5,
            'total_feedback_count' => 10,
            'dismissed_count' => 2,
        ];

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('formatLearningContext');
        $method->setAccessible(true);

        $formatted = $method->invoke($this->service, $learning, $feedbackStats);

        $this->assertStringContainsString('Learning from Past Insights', $formatted);
        $this->assertStringContainsString('Average rating: 4.5/5', $formatted);
        $this->assertStringContainsString('Dismissed: 2', $formatted);
    }

    /** @test */
    public function it_formats_queries_context()
    {
        $queries = [
            [
                'from_domain' => 'money',
                'question' => 'Did health metrics correlate with spending?',
            ],
            [
                'from_domain' => 'media',
                'question' => 'Did music choices reflect health status?',
            ],
        ];

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('formatQueriesContext');
        $method->setAccessible(true);

        $formatted = $method->invoke($this->service, $queries);

        $this->assertStringContainsString('Questions from Other Agents', $formatted);
        $this->assertStringContainsString('From money agent', $formatted);
        $this->assertStringContainsString('Did health metrics correlate', $formatted);
    }

    /** @test */
    public function it_parses_agent_response_with_valid_json()
    {
        $response = json_encode([
            'insights' => [
                ['title' => 'Test Insight', 'confidence' => 0.8],
            ],
            'suggestions' => [
                ['title' => 'Test Suggestion'],
            ],
        ]);

        $parsed = $this->service->parseAgentResponse($response);

        $this->assertArrayHasKey('insights', $parsed);
        $this->assertArrayHasKey('suggestions', $parsed);
        $this->assertEquals('Test Insight', $parsed['insights'][0]['title']);
    }

    /** @test */
    public function it_returns_fallback_structure_for_invalid_json()
    {
        $response = 'This is not valid JSON';

        $parsed = $this->service->parseAgentResponse($response);

        $this->assertArrayHasKey('insights', $parsed);
        $this->assertArrayHasKey('suggestions', $parsed);
        $this->assertArrayHasKey('metrics', $parsed);
        $this->assertEmpty($parsed['insights']);
        $this->assertEquals($response, $parsed['reasoning']);
    }

    /** @test */
    public function it_extracts_json_from_markdown_code_blocks()
    {
        $response = <<<'JSON'
Here is the analysis:

```json
{
  "insights": [{"title": "Test", "confidence": 0.9}]
}
```
JSON;

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('extractJson');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $response);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('insights', $result);
    }
}
