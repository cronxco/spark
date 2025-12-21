<?php

namespace Tests\Unit\Services;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\User;
use App\Services\AgentMemoryService;
use App\Services\AgentOrchestrationService;
use App\Services\AgentWorkingMemoryService;
use App\Services\AssistantPromptingService;
use App\Services\DomainAgentService;
use App\Services\FlintBlockCreationService;
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

        $this->service = new AgentOrchestrationService(
            $this->workingMemory,
            $this->memory,
            $this->prompting,
            $this->domainAgent,
            $this->blockCreation
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

        $this->assertEquals(['health', 'money'], $domains);
    }

    /** @test */
    public function it_uses_default_domains_when_not_configured()
    {
        $user = User::factory()->create(['settings' => []]);

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('getEnabledDomains');
        $method->setAccessible(true);

        $domains = $method->invoke($this->service, $user);

        $this->assertEquals(['health', 'money', 'media', 'knowledge', 'online'], $domains);
    }

    /** @test */
    public function it_groups_historical_data_by_domain_and_week()
    {
        // Create test events
        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'test',
            'type' => 'test',
            'title' => 'Test Object',
        ]);

        $event = Event::factory()->create([
            'user_id' => $this->user->id,
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
        $mockService = Mockery::mock(AgentOrchestrationService::class)->makePartial();
        $mockService->shouldReceive('extractJson')->andReturn($response);

        $result = $method->invoke($mockService, json_encode($response));

        $this->assertCount(2, $result);
        $this->assertEquals('High Confidence', $result[0]['title']);
        $this->assertEquals('Medium Confidence', $result[2]['title']);
    }

    /** @test */
    public function it_determines_period_correctly()
    {
        $reflection = new ReflectionClass(\App\Console\Kernel::class);
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);

        $method = $reflection->getMethod('determinePeriod');
        $method->setAccessible(true);

        $this->assertEquals('morning', $method->invoke($kernel, '06:00'));
        $this->assertEquals('morning', $method->invoke($kernel, '11:30'));
        $this->assertEquals('afternoon', $method->invoke($kernel, '12:00'));
        $this->assertEquals('afternoon', $method->invoke($kernel, '16:45'));
        $this->assertEquals('evening', $method->invoke($kernel, '17:00'));
        $this->assertEquals('evening', $method->invoke($kernel, '23:00'));
    }
}
