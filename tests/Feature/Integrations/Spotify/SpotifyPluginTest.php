<?php

namespace Tests\Feature\Integrations\Spotify;

use App\Integrations\Spotify\SpotifyPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpotifyPluginTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function plugin_has_correct_metadata(): void
    {
        $this->assertEquals('spotify', SpotifyPlugin::getIdentifier());
        $this->assertEquals('Spotify', SpotifyPlugin::getDisplayName());
        $this->assertEquals('oauth', SpotifyPlugin::getServiceType());
        $this->assertEquals('media', SpotifyPlugin::getDomain());

        $description = SpotifyPlugin::getDescription();
        $this->assertStringContainsString('Spotify', $description);
    }

    #[Test]
    public function plugin_has_configuration_schema(): void
    {
        $schema = SpotifyPlugin::getConfigurationSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('update_frequency_minutes', $schema);
        $this->assertArrayHasKey('auto_tag_genres', $schema);
        $this->assertArrayHasKey('auto_tag_artists', $schema);
        $this->assertArrayHasKey('include_album_art', $schema);
        $this->assertArrayHasKey('track_podcasts', $schema);
        $this->assertArrayHasKey('podcast_min_listen_minutes', $schema);
        $this->assertArrayHasKey('podcast_session_timeout_hours', $schema);

        // Check update frequency has proper config
        $this->assertEquals('integer', $schema['update_frequency_minutes']['type']);
        $this->assertEquals(15, $schema['update_frequency_minutes']['default']);
        $this->assertEquals(5, $schema['update_frequency_minutes']['min']);
    }

    #[Test]
    public function plugin_has_action_types(): void
    {
        $actionTypes = SpotifyPlugin::getActionTypes();

        $this->assertArrayHasKey('listened_to', $actionTypes);
        $this->assertEquals('Listened to Track', $actionTypes['listened_to']['display_name']);
        $this->assertEquals('fas.play', $actionTypes['listened_to']['icon']);
    }

    #[Test]
    public function plugin_has_block_types(): void
    {
        $blockTypes = SpotifyPlugin::getBlockTypes();

        $this->assertArrayHasKey('album_art', $blockTypes);
        $this->assertArrayHasKey('track_details', $blockTypes);
        $this->assertArrayHasKey('artist', $blockTypes);
        $this->assertArrayHasKey('track_info', $blockTypes);
        $this->assertArrayHasKey('episode_art', $blockTypes);
        $this->assertArrayHasKey('episode_details', $blockTypes);

        $this->assertEquals('Album Artwork', $blockTypes['album_art']['display_name']);
        $this->assertEquals('Track Details', $blockTypes['track_details']['display_name']);
        $this->assertEquals('Artist', $blockTypes['artist']['display_name']);
    }

    #[Test]
    public function plugin_has_object_types(): void
    {
        $objectTypes = SpotifyPlugin::getObjectTypes();

        $this->assertArrayHasKey('spotify_user', $objectTypes);
        $this->assertArrayHasKey('spotify_track', $objectTypes);
        $this->assertArrayHasKey('spotify_podcast_episode', $objectTypes);

        $this->assertEquals('Spotify User', $objectTypes['spotify_user']['display_name']);
        $this->assertEquals('Spotify Track', $objectTypes['spotify_track']['display_name']);
        $this->assertEquals('Podcast Episode', $objectTypes['spotify_podcast_episode']['display_name']);
    }

    #[Test]
    public function plugin_supports_migration(): void
    {
        $this->assertTrue(SpotifyPlugin::supportsMigration());
    }

    #[Test]
    public function plugin_has_instance_types(): void
    {
        $instanceTypes = SpotifyPlugin::getInstanceTypes();

        $this->assertArrayHasKey('listening', $instanceTypes);
        $this->assertEquals('Listening Activity', $instanceTypes['listening']['label']);
    }

    #[Test]
    public function plugin_has_icon(): void
    {
        $icon = SpotifyPlugin::getIcon();

        $this->assertEquals('fab.spotify', $icon);
    }

    #[Test]
    public function plugin_has_accent_color(): void
    {
        $accentColor = SpotifyPlugin::getAccentColor();

        $this->assertEquals('success', $accentColor);
    }

    #[Test]
    public function plugin_has_spotlight_commands(): void
    {
        $commands = SpotifyPlugin::getSpotlightCommands();

        $this->assertIsArray($commands);
        $this->assertArrayHasKey('spotify-sync-recent', $commands);
        $this->assertArrayHasKey('spotify-view-stats', $commands);

        $syncCommand = $commands['spotify-sync-recent'];
        $this->assertArrayHasKey('title', $syncCommand);
        $this->assertArrayHasKey('subtitle', $syncCommand);
        $this->assertArrayHasKey('icon', $syncCommand);
        $this->assertArrayHasKey('action', $syncCommand);
    }

    #[Test]
    public function plugin_encodes_integer_value_correctly(): void
    {
        $plugin = new SpotifyPlugin;

        [$value, $multiplier] = $plugin->encodeNumericValue(100);

        $this->assertEquals(100, $value);
        $this->assertEquals(1, $multiplier);
    }

    #[Test]
    public function plugin_encodes_float_value_with_multiplier(): void
    {
        $plugin = new SpotifyPlugin;

        [$value, $multiplier] = $plugin->encodeNumericValue(3.14159);

        $this->assertEquals(3142, $value);
        $this->assertEquals(1000, $multiplier);
    }

    #[Test]
    public function plugin_encodes_null_value(): void
    {
        $plugin = new SpotifyPlugin;

        [$value, $multiplier] = $plugin->encodeNumericValue(null);

        $this->assertNull($value);
        $this->assertNull($multiplier);
    }

    #[Test]
    public function plugin_encodes_empty_string_value(): void
    {
        $plugin = new SpotifyPlugin;

        [$value, $multiplier] = $plugin->encodeNumericValue('');

        $this->assertNull($value);
        $this->assertNull($multiplier);
    }

    #[Test]
    public function plugin_podcast_configuration_has_defaults(): void
    {
        $schema = SpotifyPlugin::getConfigurationSchema();

        $this->assertTrue($schema['track_podcasts']['default']);
        $this->assertEquals(5, $schema['podcast_min_listen_minutes']['default']);
        $this->assertEquals(4, $schema['podcast_session_timeout_hours']['default']);
    }

    #[Test]
    public function plugin_podcast_configuration_has_min_max(): void
    {
        $schema = SpotifyPlugin::getConfigurationSchema();

        // podcast_min_listen_minutes
        $this->assertEquals(1, $schema['podcast_min_listen_minutes']['min']);
        $this->assertEquals(60, $schema['podcast_min_listen_minutes']['max']);

        // podcast_session_timeout_hours
        $this->assertEquals(1, $schema['podcast_session_timeout_hours']['min']);
        $this->assertEquals(24, $schema['podcast_session_timeout_hours']['max']);
    }
}
