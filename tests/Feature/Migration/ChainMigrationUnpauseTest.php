<?php

namespace Tests\Feature\Migration;

use App\Jobs\Migrations\FetchIntegrationPage;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class ChainMigrationUnpauseTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_unpauses_oura_integration_when_migration_completes()
    {
        // Arrange
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'access_token' => 'test_token',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'activity',
            'configuration' => ['paused' => true], // Start paused during migration
        ]);

        // Mock notifications to prevent actual sending
        Notification::fake();

        // Mock all HTTP requests to prevent real API calls
        Http::fake([
            '*' => Http::response([
                'data' => [],
                'items' => [],
            ], 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        // Mock all log messages (updated to match new completeMigration() method)
        Log::shouldReceive('info')
            ->with('oura migration completed - no more data', [
                'integration_id' => $integration->id,
                'service' => 'oura',
            ])
            ->once();

        Log::shouldReceive('info')
            ->with('Integration unpaused after migration completion', [
                'integration_id' => $integration->id,
                'service' => 'oura',
                'instance_type' => 'activity',
            ])
            ->once();

        Log::shouldReceive('info')
            ->with('oura migration completion notification sent', Mockery::any())
            ->once();

        // Mock any other potential log calls
        $mockChannel = Mockery::mock();
        $mockChannel->shouldReceive('log')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('info')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('error')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('debug')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('warning')->withAnyArgs()->andReturnSelf();

        Log::shouldReceive('channel')->andReturn($mockChannel);
        Log::shouldReceive('info')->withAnyArgs();
        Log::shouldReceive('error')->withAnyArgs();
        Log::shouldReceive('debug')->withAnyArgs();
        Log::shouldReceive('warning')->withAnyArgs();
        Log::shouldReceive('log')->withAnyArgs();
        Log::shouldReceive('build')->andReturn($mockChannel);

        // Act
        $job = new FetchIntegrationPage($integration, [
            'service' => 'oura',
            'instance_type' => 'activity',
            'cursor' => [
                'start_date' => now()->subDays(30)->toDateString(),
                'end_date' => now()->toDateString(),
            ],
            'window_days' => 30,
        ]);

        $job->handle();

        // Assert
        $integration->refresh();
        $this->assertFalse($integration->configuration['paused'] ?? false, 'Integration should be unpaused after migration completes');
    }

    /** @test */
    public function it_unpauses_spotify_integration_when_migration_completes()
    {
        // Arrange
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'spotify',
            'access_token' => 'test_token',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'spotify',
            'instance_type' => 'listening',
            'configuration' => ['paused' => true], // Start paused during migration
        ]);

        // Mock notifications to prevent actual sending
        Notification::fake();

        // Mock all HTTP requests
        Http::fake([
            '*' => Http::response([
                'items' => [],
                'data' => [],
            ], 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        // Mock all log messages (updated to match new completeMigration() method)
        Log::shouldReceive('info')
            ->with('spotify migration completed - no more data', [
                'integration_id' => $integration->id,
                'service' => 'spotify',
            ])
            ->once();

        Log::shouldReceive('info')
            ->with('Integration unpaused after migration completion', [
                'integration_id' => $integration->id,
                'service' => 'spotify',
                'instance_type' => 'listening',
            ])
            ->once();

        Log::shouldReceive('info')
            ->with('spotify migration completion notification sent', Mockery::any())
            ->once();

        // Mock any other potential log calls
        $mockChannel = Mockery::mock();
        $mockChannel->shouldReceive('log')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('info')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('error')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('debug')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('warning')->withAnyArgs()->andReturnSelf();

        Log::shouldReceive('channel')->andReturn($mockChannel);
        Log::shouldReceive('info')->withAnyArgs();
        Log::shouldReceive('error')->withAnyArgs();
        Log::shouldReceive('debug')->withAnyArgs();
        Log::shouldReceive('warning')->withAnyArgs();
        Log::shouldReceive('log')->withAnyArgs();
        Log::shouldReceive('build')->andReturn($mockChannel);

        // Act
        $job = new FetchIntegrationPage($integration, [
            'service' => 'spotify',
            'cursor' => [
                'before_ms' => now()->getTimestampMs(),
            ],
        ]);

        $job->handle();

        // Assert
        $integration->refresh();
        $this->assertFalse($integration->configuration['paused'] ?? false, 'Integration should be unpaused after migration completes');
    }

    /** @test */
    public function it_unpauses_github_integration_when_no_repositories_configured()
    {
        // Arrange
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'github',
            'access_token' => 'test_token',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'github',
            'instance_type' => 'activity',
            'configuration' => [
                'paused' => true, // Start paused during migration
                'repositories' => [], // No repositories configured
            ],
        ]);

        // Mock notifications to prevent actual sending
        Notification::fake();

        // Mock all HTTP requests
        Http::fake([
            '*' => Http::response([], 200),
        ]);

        // Mock all log messages (updated to match new completeMigration() method)
        Log::shouldReceive('info')
            ->with('github migration completed - no more data', [
                'integration_id' => $integration->id,
                'service' => 'github',
            ])
            ->once();

        Log::shouldReceive('info')
            ->with('Integration unpaused after migration completion', [
                'integration_id' => $integration->id,
                'service' => 'github',
                'instance_type' => 'activity',
            ])
            ->once();

        Log::shouldReceive('info')
            ->with('github migration completion notification sent', Mockery::any())
            ->once();

        // Mock any other potential log calls
        $mockChannel = Mockery::mock();
        $mockChannel->shouldReceive('log')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('info')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('error')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('debug')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('warning')->withAnyArgs()->andReturnSelf();

        Log::shouldReceive('channel')->andReturn($mockChannel);
        Log::shouldReceive('info')->withAnyArgs();
        Log::shouldReceive('error')->withAnyArgs();
        Log::shouldReceive('debug')->withAnyArgs();
        Log::shouldReceive('warning')->withAnyArgs();
        Log::shouldReceive('log')->withAnyArgs();
        Log::shouldReceive('build')->andReturn($mockChannel);

        // Act
        $job = new FetchIntegrationPage($integration, [
            'service' => 'github',
            'cursor' => [
                'repo_index' => 0,
                'page' => 1,
            ],
        ]);

        $job->handle();

        // Assert
        $integration->refresh();
        $this->assertFalse($integration->configuration['paused'] ?? false, 'Integration should be unpaused when no repositories configured');
    }

    /** @test */
    public function it_unpauses_github_integration_when_all_repositories_processed()
    {
        // Arrange
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'github',
            'access_token' => 'test_token',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'github',
            'instance_type' => 'activity',
            'configuration' => [
                'paused' => true, // Start paused during migration
                'repositories' => ['user/repo1', 'user/repo2'], // Two repositories configured
            ],
        ]);

        // Mock notifications to prevent actual sending
        Notification::fake();

        // Mock all HTTP requests
        Http::fake([
            '*' => Http::response([], 200),
        ]);

        // Mock all log messages (updated to match new completeMigration() method)
        Log::shouldReceive('info')
            ->with('github migration completed - no more data', [
                'integration_id' => $integration->id,
                'service' => 'github',
            ])
            ->once();

        Log::shouldReceive('info')
            ->with('Integration unpaused after migration completion', [
                'integration_id' => $integration->id,
                'service' => 'github',
                'instance_type' => 'activity',
            ])
            ->once();

        Log::shouldReceive('info')
            ->with('github migration completion notification sent', Mockery::any())
            ->once();

        // Mock any other potential log calls
        $mockChannel = Mockery::mock();
        $mockChannel->shouldReceive('log')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('info')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('error')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('debug')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('warning')->withAnyArgs()->andReturnSelf();

        Log::shouldReceive('channel')->andReturn($mockChannel);
        Log::shouldReceive('info')->withAnyArgs();
        Log::shouldReceive('error')->withAnyArgs();
        Log::shouldReceive('debug')->withAnyArgs();
        Log::shouldReceive('warning')->withAnyArgs();
        Log::shouldReceive('log')->withAnyArgs();
        Log::shouldReceive('build')->andReturn($mockChannel);

        // Act - simulate being past the last repository index
        $job = new FetchIntegrationPage($integration, [
            'service' => 'github',
            'cursor' => [
                'repo_index' => 2, // Past the last repo (index 1)
                'page' => 1,
            ],
        ]);

        $job->handle();

        // Assert
        $integration->refresh();
        $this->assertFalse($integration->configuration['paused'] ?? false, 'Integration should be unpaused when all repositories processed');
    }

    /** @test */
    public function it_continues_chain_when_oura_has_more_data()
    {
        // Arrange
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'access_token' => 'test_token',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'instance_type' => 'activity',
            'configuration' => ['paused' => true], // Start paused during migration
        ]);

        // Mock all HTTP requests to return data (migration continues)
        Http::fake([
            '*' => Http::response([
                'data' => [
                    [
                        'id' => '123',
                        'day' => '2024-01-01',
                        'score' => 85,
                    ],
                ],
                'items' => [
                    [
                        'id' => '123',
                        'day' => '2024-01-01',
                        'score' => 85,
                    ],
                ],
            ], 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        // Mock all log calls
        $mockChannel = Mockery::mock();
        $mockChannel->shouldReceive('log')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('info')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('error')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('debug')->withAnyArgs()->andReturnSelf();
        $mockChannel->shouldReceive('warning')->withAnyArgs()->andReturnSelf();

        Log::shouldReceive('channel')->andReturn($mockChannel);
        Log::shouldReceive('info')->withAnyArgs();
        Log::shouldReceive('error')->withAnyArgs();
        Log::shouldReceive('debug')->withAnyArgs();
        Log::shouldReceive('warning')->withAnyArgs();
        Log::shouldReceive('log')->withAnyArgs();
        Log::shouldReceive('build')->andReturn($mockChannel);

        // Mock job dispatch to prevent Redis issues
        Bus::fake();

        // Act
        $job = new FetchIntegrationPage($integration, [
            'service' => 'oura',
            'instance_type' => 'activity',
            'cursor' => [
                'start_date' => now()->subDays(30)->toDateString(),
                'end_date' => now()->toDateString(),
            ],
            'window_days' => 30,
        ]);

        $job->handle();

        // Assert - integration should still be paused since migration continues
        $integration->refresh();
        $this->assertTrue($integration->configuration['paused'] ?? false, 'Integration should remain paused while migration continues');
    }
}
