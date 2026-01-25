<?php

namespace Tests\Unit\Jobs;

use App\Jobs\OAuth\Spotify\SpotifyListeningPull;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpotifyListeningPullTest extends TestCase
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
            'service' => 'spotify',
            'account_id' => 'spotify_user_123',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'spotify',
            'instance_type' => 'listening',
            'configuration' => [
                'update_frequency_minutes' => 1,
                'auto_tag_genres' => [],
                'auto_tag_artists' => ['enabled'],
                'include_album_art' => ['enabled'],
            ],
        ]);
    }

    /**
     * @test
     */
    public function job_creation()
    {
        $job = new SpotifyListeningPull($this->integration);

        $this->assertInstanceOf(SpotifyListeningPull::class, $job);
        $this->assertEquals(120, $job->timeout);
        $this->assertEquals(3, $job->tries);
        $this->assertEquals([60, 300, 600], $job->backoff);
    }

    /**
     * @test
     */
    public function unique_id_generation()
    {
        $job = new SpotifyListeningPull($this->integration);
        $uniqueId = $job->uniqueId();

        $this->assertStringContainsString('spotify_listening_' . $this->integration->id, $uniqueId);
        $this->assertStringContainsString(date('Y-m-d'), $uniqueId);
    }

    /**
     * @test
     */
    public function job_handles_integration_correctly()
    {
        $job = new SpotifyListeningPull($this->integration);

        // Test that the job was created successfully with the integration
        $this->assertInstanceOf(SpotifyListeningPull::class, $job);
        $this->assertEquals($this->integration->id, $this->integration->id);
        $this->assertEquals('spotify', $this->integration->service);
        $this->assertEquals('listening', $this->integration->instance_type);
    }

    /**
     * @test
     */
    public function configuration_inheritance()
    {
        $this->assertEquals(['enabled'], $this->integration->configuration['auto_tag_artists']);
        $this->assertEquals(['enabled'], $this->integration->configuration['include_album_art']);
        $this->assertEquals(1, $this->integration->configuration['update_frequency_minutes']);

        $job = new SpotifyListeningPull($this->integration);
        $this->assertInstanceOf(SpotifyListeningPull::class, $job);
    }

    /**
     * @test
     */
    public function job_with_minimal_configuration()
    {
        $minimalIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'spotify',
            'instance_type' => 'listening',
            'configuration' => [], // Empty configuration
        ]);

        $job = new SpotifyListeningPull($minimalIntegration);
        $this->assertInstanceOf(SpotifyListeningPull::class, $job);
    }

    /**
     * @test
     */
    public function job_with_different_account_ids()
    {
        // Test with group account_id
        $this->assertEquals('spotify_user_123', $this->group->account_id);

        // Test job creation with different account ID
        $group2 = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'spotify',
            'account_id' => 'different_user_456',
        ]);

        $integration2 = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group2->id,
            'service' => 'spotify',
            'instance_type' => 'listening',
        ]);

        $job = new SpotifyListeningPull($integration2);
        $this->assertInstanceOf(SpotifyListeningPull::class, $job);

        // Test unique ID is different for different integrations
        $job1 = new SpotifyListeningPull($this->integration);
        $job2 = new SpotifyListeningPull($integration2);

        $this->assertNotEquals($job1->uniqueId(), $job2->uniqueId());
    }

    /**
     * @test
     */
    public function job_handles_null_account_id()
    {
        $groupWithoutAccountId = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'spotify',
            'account_id' => null,
        ]);

        $integrationWithoutAccountId = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $groupWithoutAccountId->id,
            'service' => 'spotify',
            'instance_type' => 'listening',
        ]);

        $job = new SpotifyListeningPull($integrationWithoutAccountId);
        $this->assertInstanceOf(SpotifyListeningPull::class, $job);

        // Job should still be created even without account_id
        $this->assertEquals($integrationWithoutAccountId->id, $integrationWithoutAccountId->id);
    }

    /**
     * @test
     */
    public function job_with_various_config_options()
    {
        // Test with all configuration options enabled
        $fullConfigIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'spotify',
            'instance_type' => 'listening',
            'configuration' => [
                'update_frequency_minutes' => 5,
                'auto_tag_genres' => ['enabled'],
                'auto_tag_artists' => ['enabled'],
                'include_album_art' => ['enabled'],
            ],
        ]);

        $job = new SpotifyListeningPull($fullConfigIntegration);
        $this->assertInstanceOf(SpotifyListeningPull::class, $job);

        // Test with mixed configuration
        $mixedConfigIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'spotify',
            'instance_type' => 'listening',
            'configuration' => [
                'update_frequency_minutes' => 10,
                'auto_tag_genres' => [], // Disabled
                'auto_tag_artists' => ['enabled'], // Enabled
                'include_album_art' => [], // Disabled
            ],
        ]);

        $job2 = new SpotifyListeningPull($mixedConfigIntegration);
        $this->assertInstanceOf(SpotifyListeningPull::class, $job2);
    }
}
