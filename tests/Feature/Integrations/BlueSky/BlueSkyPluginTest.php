<?php

namespace Tests\Feature\Integrations\BlueSky;

use App\Integrations\BlueSky\BlueSkyPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlueSkyPluginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function plugin_has_correct_metadata(): void
    {
        $this->assertEquals('bluesky', BlueSkyPlugin::getIdentifier());
        $this->assertEquals('BlueSky', BlueSkyPlugin::getDisplayName());
        $this->assertEquals('oauth', BlueSkyPlugin::getServiceType());
        $this->assertEquals('online', BlueSkyPlugin::getDomain());

        $description = BlueSkyPlugin::getDescription();
        $this->assertStringContainsString('BlueSky', $description);
        $this->assertStringContainsString('bookmarks', $description);
    }

    /**
     * @test
     */
    public function plugin_has_configuration_schema(): void
    {
        $schema = BlueSkyPlugin::getConfigurationSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('update_frequency_minutes', $schema);
        $this->assertArrayHasKey('track_bookmarks', $schema);
        $this->assertArrayHasKey('track_likes', $schema);
        $this->assertArrayHasKey('track_reposts', $schema);
    }

    /**
     * @test
     */
    public function plugin_has_action_types(): void
    {
        $actionTypes = BlueSkyPlugin::getActionTypes();

        $this->assertArrayHasKey('bookmarked_post', $actionTypes);
        $this->assertArrayHasKey('liked_post', $actionTypes);
        $this->assertArrayHasKey('reposted', $actionTypes);

        $this->assertEquals('Bookmarked Post', $actionTypes['bookmarked_post']['display_name']);
        $this->assertEquals('Liked Post', $actionTypes['liked_post']['display_name']);
        $this->assertEquals('Reposted', $actionTypes['reposted']['display_name']);
    }

    /**
     * @test
     */
    public function plugin_has_block_types(): void
    {
        $blockTypes = BlueSkyPlugin::getBlockTypes();

        $this->assertArrayHasKey('post_content', $blockTypes);
        $this->assertArrayHasKey('post_media', $blockTypes);
        $this->assertArrayHasKey('quoted_post_content', $blockTypes);
        $this->assertArrayHasKey('thread_parent', $blockTypes);
        $this->assertArrayHasKey('post_metrics', $blockTypes);
        $this->assertArrayHasKey('link_preview', $blockTypes);
    }

    /**
     * @test
     */
    public function plugin_has_object_types(): void
    {
        $objectTypes = BlueSkyPlugin::getObjectTypes();

        $this->assertArrayHasKey('bluesky_user', $objectTypes);
        $this->assertArrayHasKey('bluesky_post', $objectTypes);
    }

    /**
     * @test
     */
    public function plugin_supports_migration(): void
    {
        $this->assertTrue(BlueSkyPlugin::supportsMigration());
    }

    /**
     * @test
     */
    public function plugin_has_instance_types(): void
    {
        $instanceTypes = BlueSkyPlugin::getInstanceTypes();

        $this->assertArrayHasKey('activity', $instanceTypes);
        $this->assertEquals('Activity Tracking', $instanceTypes['activity']['label']);
    }
}
