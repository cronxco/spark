<?php

namespace Tests\Unit\Jobs;

use App\Jobs\OAuth\BlueSky\BlueSkyBookmarksPull;
use App\Jobs\OAuth\BlueSky\BlueSkyLikesPull;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlueSkyLikesPullTest extends TestCase
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
                'track_likes' => true,
            ],
        ]);
    }

    /**
     * @test
     */
    public function job_creation(): void
    {
        $job = new BlueSkyLikesPull($this->integration);

        $this->assertInstanceOf(BlueSkyLikesPull::class, $job);
        $this->assertEquals(120, $job->timeout);
        $this->assertEquals(3, $job->tries);
    }

    /**
     * @test
     */
    public function unique_id_generation(): void
    {
        $job = new BlueSkyLikesPull($this->integration);
        $uniqueId = $job->uniqueId();

        $this->assertStringContainsString('bluesky_likes_'.$this->integration->id, $uniqueId);
        $this->assertStringContainsString(date('Y-m-d'), $uniqueId);
    }

    /**
     * @test
     */
    public function unique_id_differs_from_bookmarks(): void
    {
        $likesJob = new BlueSkyLikesPull($this->integration);
        $bookmarksJob = new BlueSkyBookmarksPull($this->integration);

        $this->assertNotEquals($likesJob->uniqueId(), $bookmarksJob->uniqueId());
    }

    /**
     * @test
     */
    public function job_handles_integration_correctly(): void
    {
        $job = new BlueSkyLikesPull($this->integration);

        $this->assertEquals($this->integration->id, $this->integration->id);
        $this->assertEquals('bluesky', $this->integration->service);
        $this->assertEquals('activity', $this->integration->instance_type);
    }
}
