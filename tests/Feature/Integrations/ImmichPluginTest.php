<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Immich\ImmichPlugin;
use Tests\TestCase;

class ImmichPluginTest extends TestCase
{
    protected ImmichPlugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plugin = new ImmichPlugin;
    }

    /** @test */
    public function it_has_correct_metadata()
    {
        $this->assertEquals('immich', $this->plugin->getIdentifier());
        $this->assertEquals('Immich', $this->plugin->getDisplayName());
        $this->assertEquals('media', $this->plugin->getDomain());
        $this->assertEquals('fas.images', $this->plugin->getIcon());
    }

    /** @test */
    public function it_uses_apikey_service_type()
    {
        $this->assertEquals('apikey', $this->plugin->getServiceType());
    }

    /** @test */
    public function it_defines_group_configuration_schema()
    {
        $groupSchema = $this->plugin->getGroupConfigurationSchema();

        $this->assertArrayHasKey('server_url', $groupSchema);
        $this->assertEquals('string', $groupSchema['server_url']['type']);
        $this->assertTrue($groupSchema['server_url']['required']);

        $this->assertArrayHasKey('api_key', $groupSchema);
        $this->assertEquals('string', $groupSchema['api_key']['type']);
        $this->assertTrue($groupSchema['api_key']['required']);
        $this->assertTrue($groupSchema['api_key']['secure']);
    }

    /** @test */
    public function it_defines_configuration_schema()
    {
        $configSchema = $this->plugin->getConfigurationSchema();

        $this->assertArrayHasKey('update_frequency_minutes', $configSchema);
        $this->assertArrayHasKey('sync_mode', $configSchema);
        $this->assertArrayHasKey('sync_people', $configSchema);
        $this->assertArrayHasKey('cluster_radius_km', $configSchema);
        $this->assertArrayHasKey('cluster_window_minutes', $configSchema);
    }

    /** @test */
    public function it_defines_took_photos_action_type()
    {
        $actionTypes = $this->plugin->getActionTypes();

        $this->assertArrayHasKey('took_photos', $actionTypes);
        $this->assertEquals('Took Photos', $actionTypes['took_photos']['display_name']);
        $this->assertEquals('photos', $actionTypes['took_photos']['value_unit']);
        $this->assertFalse($actionTypes['took_photos']['hidden']);
    }

    /** @test */
    public function it_defines_immich_photo_block_type()
    {
        $blockTypes = $this->plugin->getBlockTypes();

        $this->assertArrayHasKey('immich_photo', $blockTypes);
        $this->assertEquals('Photo', $blockTypes['immich_photo']['display_name']);
    }

    /** @test */
    public function it_defines_cluster_summary_block_type()
    {
        $blockTypes = $this->plugin->getBlockTypes();

        $this->assertArrayHasKey('cluster_summary', $blockTypes);
        $this->assertEquals('Cluster Summary', $blockTypes['cluster_summary']['display_name']);
    }

    /** @test */
    public function it_defines_cluster_people_block_type()
    {
        $blockTypes = $this->plugin->getBlockTypes();

        $this->assertArrayHasKey('cluster_people', $blockTypes);
        $this->assertEquals('People in Cluster', $blockTypes['cluster_people']['display_name']);
    }

    /** @test */
    public function it_defines_immich_user_object_type()
    {
        $objectTypes = $this->plugin->getObjectTypes();

        $this->assertArrayHasKey('immich_user', $objectTypes);
        $this->assertEquals('Immich User', $objectTypes['immich_user']['display_name']);
        $this->assertTrue($objectTypes['immich_user']['hidden']);
    }

    /** @test */
    public function it_defines_immich_cluster_object_type()
    {
        $objectTypes = $this->plugin->getObjectTypes();

        $this->assertArrayHasKey('immich_cluster', $objectTypes);
        $this->assertEquals('Photo Cluster', $objectTypes['immich_cluster']['display_name']);
    }

    /** @test */
    public function it_defines_immich_person_object_type()
    {
        $objectTypes = $this->plugin->getObjectTypes();

        $this->assertArrayHasKey('immich_person', $objectTypes);
        $this->assertEquals('Person', $objectTypes['immich_person']['display_name']);
    }

    /** @test */
    public function it_defines_photos_instance_type()
    {
        $instanceTypes = $this->plugin->getInstanceTypes();

        $this->assertArrayHasKey('photos', $instanceTypes);
        $this->assertEquals('Photos', $instanceTypes['photos']['label']);
    }

    /** @test */
    public function it_has_default_configuration_values()
    {
        $configSchema = $this->plugin->getConfigurationSchema();

        $this->assertEquals(60, $configSchema['update_frequency_minutes']['default']);
        $this->assertEquals('recent', $configSchema['sync_mode']['default']);
        $this->assertTrue($configSchema['sync_people']['default']);
        $this->assertFalse($configSchema['include_archived']['default']);
        $this->assertTrue($configSchema['include_videos']['default']);
        $this->assertEquals(5, $configSchema['cluster_radius_km']['default']);
        $this->assertEquals(60, $configSchema['cluster_window_minutes']['default']);
    }

    /** @test */
    public function it_validates_sync_mode_options()
    {
        $configSchema = $this->plugin->getConfigurationSchema();

        $this->assertArrayHasKey('options', $configSchema['sync_mode']);
        $this->assertEquals(['recent', 'full'], $configSchema['sync_mode']['options']);
    }

    /** @test */
    public function it_has_primary_accent_color()
    {
        $this->assertEquals('primary', $this->plugin->getAccentColor());
    }

    /** @test */
    public function it_supports_migration()
    {
        $this->assertTrue($this->plugin->supportsMigration());
    }
}
