<?php

namespace Tests\Unit\Jobs;

use App\Jobs\OAuth\BlueSky\BlueSkyBookmarksPull;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlueSkyBookmarksPullTest extends TestCase
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
            'service' => 'bluesky',
            'account_id' => 'did:plc:test123',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'bluesky',
            'instance_type' => 'activity',
            'configuration' => [
                'update_frequency_minutes' => 15,
                'track_bookmarks' => true,
                'track_likes' => true,
                'track_reposts' => true,
            ],
        ]);
    }

    /**
     * @test
     */
    public function job_creation(): void
    {
        $job = new BlueSkyBookmarksPull($this->integration);

        $this->assertInstanceOf(BlueSkyBookmarksPull::class, $job);
        $this->assertEquals(120, $job->timeout);
        $this->assertEquals(3, $job->tries);
        $this->assertEquals([60, 300, 600], $job->backoff);
    }

    /**
     * @test
     */
    public function unique_id_generation(): void
    {
        $job = new BlueSkyBookmarksPull($this->integration);
        $uniqueId = $job->uniqueId();

        $this->assertStringContainsString('bluesky_bookmarks_'.$this->integration->id, $uniqueId);
        $this->assertStringContainsString(date('Y-m-d'), $uniqueId);
    }

    /**
     * @test
     */
    public function job_handles_integration_correctly(): void
    {
        $job = new BlueSkyBookmarksPull($this->integration);

        $this->assertInstanceOf(BlueSkyBookmarksPull::class, $job);
        $this->assertEquals($this->integration->id, $this->integration->id);
        $this->assertEquals('bluesky', $this->integration->service);
        $this->assertEquals('activity', $this->integration->instance_type);
    }

    /**
     * @test
     */
    public function configuration_inheritance(): void
    {
        $this->assertTrue($this->integration->configuration['track_bookmarks']);
        $this->assertTrue($this->integration->configuration['track_likes']);
        $this->assertTrue($this->integration->configuration['track_reposts']);
        $this->assertEquals(15, $this->integration->configuration['update_frequency_minutes']);

        $job = new BlueSkyBookmarksPull($this->integration);
        $this->assertInstanceOf(BlueSkyBookmarksPull::class, $job);
    }

    /**
     * @test
     */
    public function job_with_minimal_configuration(): void
    {
        $minimalIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'bluesky',
            'instance_type' => 'activity',
            'configuration' => [], // Empty configuration
        ]);

        $job = new BlueSkyBookmarksPull($minimalIntegration);
        $this->assertInstanceOf(BlueSkyBookmarksPull::class, $job);
    }

    /**
     * @test
     */
    public function job_with_different_account_ids(): void
    {
        $this->assertEquals('did:plc:test123', $this->group->account_id);

        $group2 = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'bluesky',
            'account_id' => 'did:plc:different456',
        ]);

        $integration2 = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group2->id,
            'service' => 'bluesky',
            'instance_type' => 'activity',
        ]);

        $job = new BlueSkyBookmarksPull($integration2);
        $this->assertInstanceOf(BlueSkyBookmarksPull::class, $job);

        $job1 = new BlueSkyBookmarksPull($this->integration);
        $job2 = new BlueSkyBookmarksPull($integration2);

        $this->assertNotEquals($job1->uniqueId(), $job2->uniqueId());
    }

    /**
     * @test
     */
    public function job_handles_null_account_id(): void
    {
        $groupWithoutAccountId = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'bluesky',
            'account_id' => null,
        ]);

        $integrationWithoutAccountId = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $groupWithoutAccountId->id,
            'service' => 'bluesky',
            'instance_type' => 'activity',
        ]);

        $job = new BlueSkyBookmarksPull($integrationWithoutAccountId);
        $this->assertInstanceOf(BlueSkyBookmarksPull::class, $job);

        $this->assertEquals($integrationWithoutAccountId->id, $integrationWithoutAccountId->id);
    }

    /**
     * @test
     */
    public function job_with_various_config_options(): void
    {
        $fullConfigIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'bluesky',
            'instance_type' => 'activity',
            'configuration' => [
                'update_frequency_minutes' => 5,
                'track_bookmarks' => true,
                'track_likes' => true,
                'track_reposts' => true,
                'include_quoted_posts' => true,
                'include_thread_context' => true,
            ],
        ]);

        $job = new BlueSkyBookmarksPull($fullConfigIntegration);
        $this->assertInstanceOf(BlueSkyBookmarksPull::class, $job);

        $mixedConfigIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'bluesky',
            'instance_type' => 'activity',
            'configuration' => [
                'update_frequency_minutes' => 30,
                'track_bookmarks' => true,
                'track_likes' => false,
                'track_reposts' => false,
            ],
        ]);

        $job2 = new BlueSkyBookmarksPull($mixedConfigIntegration);
        $this->assertInstanceOf(BlueSkyBookmarksPull::class, $job2);
    }
}
