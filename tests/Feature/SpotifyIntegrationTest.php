<?php

namespace Tests\Feature;

use App\Integrations\Spotify\SpotifyPlugin;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class SpotifyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock Spotify API responses
        $this->mockSpotifyApi();
    }

    /**
     * @test
     */
    public function spotify_plugin_can_be_initialized()
    {
        // Note: when SpotifyPlugin migrates fully to IntegrationGroup, update this test
        // to assert group creation and onboarding redirect.
        $user = User::factory()->create();
        $plugin = new SpotifyPlugin;

        $integration = $plugin->initialize($user);

        $this->assertEquals('spotify', $integration->service);
        $this->assertEquals('Spotify', $integration->name);
        $this->assertEquals($user->id, $integration->user_id);
    }

    /**
     * @test
     */
    public function spotify_plugin_has_correct_metadata()
    {
        $this->assertEquals('spotify', SpotifyPlugin::getIdentifier());
        $this->assertEquals('Spotify', SpotifyPlugin::getDisplayName());
        $this->assertEquals('oauth', SpotifyPlugin::getServiceType());

        $description = SpotifyPlugin::getDescription();
        $this->assertStringContainsString('Spotify', $description);
        $this->assertStringContainsString('listening', $description);
    }

    /**
     * @test
     */
    public function spotify_plugin_has_configuration_schema()
    {
        $schema = SpotifyPlugin::getConfigurationSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('update_frequency_minutes', $schema);
        $this->assertArrayHasKey('auto_tag_artists', $schema);
        $this->assertArrayHasKey('include_album_art', $schema);
    }

    /**
     * @test
     */
    public function spotify_plugin_requires_correct_scopes()
    {
        $plugin = new SpotifyPlugin;
        $reflection = new ReflectionClass($plugin);
        $method = $reflection->getMethod('getRequiredScopes');
        $method->setAccessible(true);
        $scopes = $method->invoke($plugin);

        $this->assertStringContainsString('user-read-currently-playing', $scopes);
        $this->assertStringContainsString('user-read-recently-played', $scopes);
        $this->assertStringContainsString('user-read-email', $scopes);
        $this->assertStringContainsString('user-read-private', $scopes);
    }

    /**
     * @test
     */
    public function spotify_plugin_can_process_track_play()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'spotify',
            'account_id' => 'test_user_123',
            'name' => 'Test Spotify User',
        ]);

        $plugin = new SpotifyPlugin;

        // Mock track data
        $trackData = [
            'track' => [
                'id' => 'track_123',
                'name' => 'Test Track',
                'duration_ms' => 180000,
                'popularity' => 85,
                'explicit' => false,
                'track_number' => 1,
                'disc_number' => 1,
                'external_urls' => [
                    'spotify' => 'https://open.spotify.com/track/track_123',
                ],
                'artists' => [
                    [
                        'id' => 'artist_123',
                        'name' => 'Test Artist',
                        'external_urls' => [
                            'spotify' => 'https://open.spotify.com/artist/artist_123',
                        ],
                    ],
                ],
                'album' => [
                    'id' => 'album_123',
                    'name' => 'Test Album',
                    'release_date' => '2023-01-01',
                    'images' => [
                        [
                            'url' => 'https://example.com/album.jpg',
                            'width' => 300,
                            'height' => 300,
                        ],
                    ],
                ],
            ],
            'played_at' => now()->subMinutes(5),
            'progress_ms' => 90000,
        ];

        // Process the track play using reflection
        $reflection = new ReflectionClass($plugin);
        $method = $reflection->getMethod('processTrackPlay');
        $method->setAccessible(true);
        $method->invoke($plugin, $integration, $trackData, 'recently_played');

        // Verify event was created
        $event = Event::where('integration_id', $integration->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('spotify', $event->service);
        $this->assertEquals('music', $event->domain);
        $this->assertEquals('played', $event->action);
        $this->assertEquals(180000, $event->value);
        $this->assertEquals('milliseconds', $event->value_unit);

        // Verify actor (user) was created
        $actor = $event->actor;
        $this->assertNotNull($actor);
        $this->assertEquals('user', $actor->concept);
        $this->assertEquals('spotify_user', $actor->type);
        $this->assertEquals('Test Spotify User', $actor->title);

        // Verify target (track) was created
        $target = $event->target;
        $this->assertNotNull($target);
        $this->assertEquals('track', $target->concept);
        $this->assertEquals('spotify_track', $target->type);
        $this->assertEquals('Test Track', $target->title);
        $this->assertStringContainsString('Test Artist', $target->content);
        $this->assertStringContainsString('Test Album', $target->content);

        // Verify blocks were created
        $blocks = $event->blocks;
        $this->assertGreaterThan(0, $blocks->count());

        $albumArtBlock = $blocks->where('title', 'Album Art')->first();
        $this->assertNotNull($albumArtBlock);
        $this->assertEquals('https://example.com/album.jpg', $albumArtBlock->media_url);

        $trackDetailsBlock = $blocks->where('title', 'Track Details')->first();
        $this->assertNotNull($trackDetailsBlock);
        $this->assertStringContainsString('Test Track', $trackDetailsBlock->content);
        $this->assertStringContainsString('Test Artist', $trackDetailsBlock->content);

        // Verify tags were attached
        $tags = $event->tags;
        $this->assertGreaterThan(0, $tags->count());

        $artistTag = $tags->where('name', 'Test Artist')->first();
        $this->assertNotNull($artistTag);

        $albumTag = $tags->where('name', 'Test Album')->first();
        $this->assertNotNull($albumTag);

        $yearTag = $tags->where('name', '2023')->first();
        $this->assertNotNull($yearTag);

        $decadeTag = $tags->where('name', '2020s')->first();
        $this->assertNotNull($decadeTag);

        $popularityTag = $tags->where('name', 'very-popular')->first();
        $this->assertNotNull($popularityTag);
    }

    /**
     * @test
     */
    public function spotify_plugin_prevents_duplicate_events()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'spotify',
            'account_id' => 'test_user_123',
            'name' => 'Test Spotify User',
        ]);

        $plugin = new SpotifyPlugin;

        $trackData = [
            'track' => [
                'id' => 'track_123',
                'name' => 'Test Track',
                'duration_ms' => 180000,
                'popularity' => 85,
                'explicit' => false,
                'external_urls' => ['spotify' => 'https://open.spotify.com/track/track_123'],
                'artists' => [
                    [
                        'id' => 'artist_123',
                        'name' => 'Test Artist',
                        'external_urls' => ['spotify' => 'https://open.spotify.com/artist/artist_123'],
                    ],
                ],
                'album' => [
                    'id' => 'album_123',
                    'name' => 'Test Album',
                    'release_date' => '2023-01-01',
                    'images' => [['url' => 'https://example.com/album.jpg', 'width' => 300, 'height' => 300]],
                ],
            ],
            'played_at' => now()->subMinutes(5),
            'progress_ms' => 90000,
        ];

        // Process the same track play twice using reflection
        $reflection = new ReflectionClass($plugin);
        $method = $reflection->getMethod('processTrackPlay');
        $method->setAccessible(true);
        $method->invoke($plugin, $integration, $trackData, 'recently_played');
        $method->invoke($plugin, $integration, $trackData, 'recently_played');

        // Should only have one event
        $events = Event::where('integration_id', $integration->id)->get();
        $this->assertEquals(1, $events->count());
    }

    protected function mockSpotifyApi(): void
    {
        // This would typically use Http::fake() to mock API responses
        // For now, we'll test the plugin logic directly
    }
}
