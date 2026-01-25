<?php

namespace Tests\Unit\Jobs;

use App\Jobs\OAuth\Karakeep\KarakeepBookmarksPull;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class KarakeepBookmarksPullTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected IntegrationGroup $group;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'karakeep',
            'access_token' => 'test_token',
            'auth_metadata' => [
                'api_url' => 'https://karakeep.test',
            ],
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'karakeep',
            'instance_type' => 'bookmarks',
            'configuration' => [
                'update_frequency_minutes' => 30,
                'fetch_limit' => 50,
                'sync_highlights' => true,
            ],
        ]);
    }

    /**
     * @test
     */
    public function job_creation(): void
    {
        $job = new KarakeepBookmarksPull($this->integration);

        $this->assertInstanceOf(KarakeepBookmarksPull::class, $job);
        $this->assertEquals(120, $job->timeout);
        $this->assertEquals(3, $job->tries);
        $this->assertEquals([60, 300, 600], $job->backoff);
    }

    /**
     * @test
     */
    public function unique_id_generation(): void
    {
        $job = new KarakeepBookmarksPull($this->integration);
        $uniqueId = $job->uniqueId();

        $this->assertStringContainsString('karakeep_bookmarks_' . $this->integration->id, $uniqueId);
        $this->assertStringContainsString(date('Y-m-d'), $uniqueId);
    }

    /**
     * @test
     */
    public function job_handles_integration_correctly(): void
    {
        $job = new KarakeepBookmarksPull($this->integration);

        $this->assertInstanceOf(KarakeepBookmarksPull::class, $job);
        $this->assertEquals($this->integration->id, $job->getIntegration()->id);
        $this->assertEquals('karakeep', $this->integration->service);
        $this->assertEquals('bookmarks', $this->integration->instance_type);
    }

    /**
     * @test
     */
    public function configuration_inheritance(): void
    {
        $this->assertEquals(30, $this->integration->configuration['update_frequency_minutes']);
        $this->assertEquals(50, $this->integration->configuration['fetch_limit']);
        $this->assertTrue($this->integration->configuration['sync_highlights']);

        $job = new KarakeepBookmarksPull($this->integration);
        $this->assertInstanceOf(KarakeepBookmarksPull::class, $job);
    }

    /**
     * @test
     */
    public function job_with_different_configurations(): void
    {
        // Test with highlights disabled
        $integrationNoHighlights = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'karakeep',
            'instance_type' => 'bookmarks',
            'configuration' => [
                'update_frequency_minutes' => 60,
                'fetch_limit' => 25,
                'sync_highlights' => false,
            ],
        ]);

        $job = new KarakeepBookmarksPull($integrationNoHighlights);
        $this->assertInstanceOf(KarakeepBookmarksPull::class, $job);
        $this->assertFalse($integrationNoHighlights->configuration['sync_highlights']);

        // Test with custom fetch limit
        $integrationCustomLimit = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'karakeep',
            'instance_type' => 'bookmarks',
            'configuration' => [
                'fetch_limit' => 100,
            ],
        ]);

        $job2 = new KarakeepBookmarksPull($integrationCustomLimit);
        $this->assertInstanceOf(KarakeepBookmarksPull::class, $job2);
        $this->assertEquals(100, $integrationCustomLimit->configuration['fetch_limit']);
    }

    /**
     * @test
     */
    public function job_has_correct_service_and_type(): void
    {
        $job = new KarakeepBookmarksPull($this->integration);

        // Use reflection to test protected methods
        $reflection = new ReflectionClass($job);

        $getServiceName = $reflection->getMethod('getServiceName');
        $getServiceName->setAccessible(true);
        $this->assertEquals('karakeep', $getServiceName->invoke($job));

        $getJobType = $reflection->getMethod('getJobType');
        $getJobType->setAccessible(true);
        $this->assertEquals('bookmarks', $getJobType->invoke($job));
    }
}
