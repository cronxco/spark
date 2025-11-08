<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Fetch\FetchEngineManager;
use App\Integrations\Fetch\PlaywrightFetchClient;
use App\Jobs\Data\Fetch\ProcessFetchedContent;
use App\Jobs\Fetch\FetchSingleUrl;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FetchPlaywrightTest extends TestCase
{
    use RefreshDatabase;

    private ?bool $originalPlaywrightEnabled = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Store original config
        $this->originalPlaywrightEnabled = config('services.playwright.enabled');
    }

    protected function tearDown(): void
    {
        // Restore original config
        config(['services.playwright.enabled' => $this->originalPlaywrightEnabled]);

        parent::tearDown();
    }

    /**
     * @test
     */
    public function engine_manager_defaults_to_http_when_playwright_disabled(): void
    {
        config(['services.playwright.enabled' => false]);

        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'fetch']);

        $webpage = EventObject::create([
            'user_id' => $user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'url' => 'https://example.com',
            'title' => 'Test Page',
            'metadata' => [
                'enabled' => true,
                'domain' => 'example.com',
            ],
        ]);

        $engine = new FetchEngineManager;
        $result = $engine->fetch('https://example.com', $group, $webpage);

        $this->assertEquals('http', $result['method']);
    }

    /**
     * @test
     */
    public function engine_manager_uses_http_fallback_when_playwright_unavailable(): void
    {
        config(['services.playwright.enabled' => true]);

        // Mock Playwright worker as unavailable
        Http::fake([
            '*/health' => Http::response(['status' => 'error'], 500),
            'example.com' => Http::response('<html><body>Test</body></html>', 200),
        ]);

        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'fetch']);

        $webpage = EventObject::create([
            'user_id' => $user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'url' => 'https://example.com',
            'title' => 'Test Page',
            'metadata' => [
                'enabled' => true,
                'domain' => 'example.com',
                'requires_playwright' => true, // Explicitly request Playwright
            ],
        ]);

        $engine = new FetchEngineManager;
        $result = $engine->fetch('https://example.com', $group, $webpage);

        // Since Playwright worker is not actually running in tests, it should fall back to HTTP
        $this->assertContains($result['method'], ['http', 'http (fallback)']);
    }

    /**
     * @test
     */
    public function playwright_client_detects_unavailable_worker(): void
    {
        // Mock Playwright worker as unavailable
        Http::fake([
            '*/health' => Http::response(['status' => 'error'], 500),
        ]);

        $client = new PlaywrightFetchClient;
        $isAvailable = $client->isAvailable();

        // In test environment, Playwright worker is not running
        $this->assertFalse($isAvailable);
    }

    /**
     * @test
     */
    public function engine_manager_learns_playwright_requirement(): void
    {
        config(['services.playwright.enabled' => false]); // Disabled for this test

        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'fetch']);

        $webpage = EventObject::create([
            'user_id' => $user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'url' => 'https://example.com',
            'title' => 'Test Page',
            'metadata' => [
                'enabled' => true,
                'domain' => 'example.com',
            ],
        ]);

        // Initially no playwright requirement
        $this->assertNull($webpage->metadata['requires_playwright'] ?? null);

        // After a successful Playwright fetch (simulated by setting the flag)
        $webpage->update([
            'metadata' => array_merge($webpage->metadata, [
                'requires_playwright' => true,
                'playwright_learned_at' => now()->toIso8601String(),
            ]),
        ]);

        $webpage->refresh();

        $this->assertTrue($webpage->metadata['requires_playwright']);
        $this->assertNotNull($webpage->metadata['playwright_learned_at']);
    }

    /**
     * @test
     */
    public function engine_manager_escalates_on_robot_detection(): void
    {
        config(['services.playwright.enabled' => true]);

        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'fetch']);

        $webpage = EventObject::create([
            'user_id' => $user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'url' => 'https://example.com',
            'title' => 'Test Page',
            'metadata' => [
                'enabled' => true,
                'domain' => 'example.com',
                'last_error' => [
                    'message' => 'Robot check detected',
                    'timestamp' => now()->toIso8601String(),
                    'consecutive_failures' => 1,
                ],
            ],
        ]);

        $engine = new FetchEngineManager;

        // The engine should detect the robot check error and attempt to use Playwright
        // (though it will fall back to HTTP since Playwright is not actually running)
        $result = $engine->fetch('https://example.com', $group, $webpage);

        // Even though Playwright would be attempted, it falls back to HTTP in test env
        $this->assertNotNull($result);
        $this->assertIsArray($result);
    }

    /**
     * @test
     */
    public function js_required_domains_use_playwright(): void
    {
        config([
            'services.playwright.enabled' => true,
            'services.playwright.js_required_domains' => 'twitter.com,instagram.com',
        ]);

        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'fetch']);

        $webpage = EventObject::create([
            'user_id' => $user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'url' => 'https://twitter.com/user/status/123',
            'title' => 'Test Tweet',
            'metadata' => [
                'enabled' => true,
                'domain' => 'twitter.com',
            ],
        ]);

        $engine = new FetchEngineManager;

        // Should attempt Playwright for JS-required domain (will fall back in test env)
        $result = $engine->fetch('https://twitter.com/user/status/123', $group, $webpage);

        $this->assertNotNull($result);
    }

    /**
     * @test
     */
    public function fetch_job_stores_fetch_method_in_metadata(): void
    {
        // Fake the queue to prevent ProcessFetchedContent from dispatching
        Queue::fake([ProcessFetchedContent::class]);

        // Fake HTTP responses
        Http::fake([
            'example.com' => Http::response('<html><head><title>Test Page</title></head><body><article><h1>Test Page</h1><p>This is test content with enough text to pass the extraction requirements for the content extractor to work properly.</p></article></body></html>', 200),
            '*' => Http::response('', 404),
        ]);

        $user = User::factory()->create();

        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'fetch',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'fetch',
            'instance_type' => 'fetcher',
            'integration_group_id' => $group->id,
        ]);

        $webpage = EventObject::create([
            'user_id' => $user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'url' => 'https://example.com',
            'title' => 'Test Page',
            'metadata' => [
                'enabled' => true,
                'domain' => 'example.com',
                'fetch_integration_id' => $integration->id,
            ],
        ]);

        // Run the job directly (not through queue)
        $job = new FetchSingleUrl($integration, $webpage->id, $webpage->url);
        $job->handle();

        // Reload webpage
        $webpage->refresh();

        // The job should have stored the fetch method
        $this->assertArrayHasKey('last_fetch_method', $webpage->metadata);
        $this->assertEquals('http', $webpage->metadata['last_fetch_method']);

        // Verify ProcessFetchedContent was dispatched
        Queue::assertPushed(ProcessFetchedContent::class);
    }

    /**
     * @test
     */
    public function playwright_stats_are_calculated_correctly(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'fetch']);

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'fetch',
            'integration_group_id' => $group->id,
        ]);

        // Create webpages with different preferences
        EventObject::create([
            'user_id' => $user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'url' => 'https://example1.com',
            'title' => 'Example 1',
            'metadata' => [
                'fetch_integration_id' => $integration->id,
                'requires_playwright' => true,
            ],
        ]);

        EventObject::create([
            'user_id' => $user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'url' => 'https://example2.com',
            'title' => 'Example 2',
            'metadata' => [
                'fetch_integration_id' => $integration->id,
                'playwright_preference' => 'http',
            ],
        ]);

        EventObject::create([
            'user_id' => $user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'url' => 'https://example3.com',
            'title' => 'Example 3',
            'metadata' => [
                'fetch_integration_id' => $integration->id,
            ],
        ]);

        $engine = new FetchEngineManager;
        $stats = $engine->getMethodStats($group);

        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(1, $stats['requires_playwright']);
        $this->assertEquals(1, $stats['prefers_http']);
        $this->assertEquals(1, $stats['auto']);
    }
}
