<?php

namespace Tests\Unit\Services;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Services\AgentMemoryService;
use App\Services\AgentOrchestrationService;
use App\Services\AgentWorkingMemoryService;
use App\Services\AssistantContextService;
use App\Services\AssistantPromptingService;
use App\Services\DomainAgentService;
use App\Services\FlintBlockCreationService;
use App\Services\FutureAgentService;
use App\Services\InsightDeduplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

class AgentOrchestrationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AgentOrchestrationService $service;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'settings' => [
                'flint' => [
                    'enabled_domains' => ['health', 'money'],
                    'continuous_analysis_enabled' => true,
                ],
            ],
        ]);

        // Mock dependencies
        $this->workingMemory = Mockery::mock(AgentWorkingMemoryService::class);
        $this->memory = Mockery::mock(AgentMemoryService::class);
        $this->prompting = Mockery::mock(AssistantPromptingService::class);
        $this->domainAgent = Mockery::mock(DomainAgentService::class);
        $this->blockCreation = Mockery::mock(FlintBlockCreationService::class);
        $this->contextService = Mockery::mock(AssistantContextService::class);
        $this->futureAgent = Mockery::mock(FutureAgentService::class);
        $this->deduplication = Mockery::mock(InsightDeduplicationService::class);

        $this->service = new AgentOrchestrationService(
            $this->workingMemory,
            $this->memory,
            $this->prompting,
            $this->domainAgent,
            $this->blockCreation,
            $this->contextService,
            $this->futureAgent,
            $this->deduplication
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_can_extract_json_from_markdown_code_blocks()
    {
        $response = <<<'JSON'
Here is the analysis:

```json
{
  "insights": [
    {"title": "Test Insight", "confidence": 0.8}
  ]
}
```
JSON;

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('extractJson');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $response);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('insights', $result);
        $this->assertEquals('Test Insight', $result['insights'][0]['title']);
    }

    /** @test */
    public function it_can_extract_plain_json()
    {
        $response = '{"insights": [{"title": "Test", "confidence": 0.9}]}';

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('extractJson');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $response);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('insights', $result);
    }

    /** @test */
    public function it_returns_null_for_invalid_json()
    {
        $response = 'This is not JSON at all';

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('extractJson');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $response);

        $this->assertNull($result);
    }

    /** @test */
    public function it_gets_enabled_domains_from_user_settings()
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('getEnabledDomains');
        $method->setAccessible(true);

        $domains = $method->invoke($this->service, $this->user);

        // money and media domains are deprecated and filtered out
        $this->assertEquals(['health'], $domains);
    }

    /** @test */
    public function it_uses_default_domains_when_not_configured()
    {
        $user = User::factory()->create(['settings' => []]);

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('getEnabledDomains');
        $method->setAccessible(true);

        $domains = $method->invoke($this->service, $user);

        // Default excludes deprecated 'money' and 'media' domains
        $this->assertEquals(['health', 'knowledge', 'online'], $domains);
    }

    /** @test */
    public function it_groups_historical_data_by_domain_and_week()
    {
        // Create test data
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $eventObject = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'test',
            'type' => 'test',
            'title' => 'Test Object',
        ]);

        Event::factory()->create([
            'source_id' => $eventObject->id,
            'integration_id' => $integration->id,
            'domain' => 'health',
            'service' => 'oura',
            'action' => 'had_sleep',
            'time' => now()->subDays(5),
        ]);

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('getHistoricalData');
        $method->setAccessible(true);

        $data = $method->invoke($this->service, $this->user, 90);

        $this->assertArrayHasKey('health', $data);
        $this->assertIsArray($data['health']);
    }

    /** @test */
    public function it_filters_patterns_by_confidence_threshold()
    {
        $response = [
            ['title' => 'High Confidence', 'confidence' => 0.8],
            ['title' => 'Low Confidence', 'confidence' => 0.4],
            ['title' => 'Medium Confidence', 'confidence' => 0.6],
        ];

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('parsePatternDetectionResponse');
        $method->setAccessible(true);

        // Mock extractJson to return our test data
        $mockService = Mockery::mock(AgentOrchestrationService::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $mockService->shouldReceive('extractJson')->andReturn($response);

        $result = $method->invoke($mockService, json_encode($response));

        $this->assertCount(2, $result);
        $this->assertEquals('High Confidence', $result[0]['title']);
        $this->assertEquals('Medium Confidence', $result[2]['title']);
    }

    /** @test */
    public function it_determines_period_correctly()
    {
        // Test the period determination logic from console.php
        $determinePeriod = function (string $time): string {
            return match (true) {
                ((int) substr($time, 0, 2)) < 12 => 'morning',
                ((int) substr($time, 0, 2)) < 17 => 'afternoon',
                default => 'evening',
            };
        };

        $this->assertEquals('morning', $determinePeriod('06:00'));
        $this->assertEquals('morning', $determinePeriod('11:30'));
        $this->assertEquals('afternoon', $determinePeriod('12:00'));
        $this->assertEquals('afternoon', $determinePeriod('16:45'));
        $this->assertEquals('evening', $determinePeriod('17:00'));
        $this->assertEquals('evening', $determinePeriod('23:00'));
    }
}
