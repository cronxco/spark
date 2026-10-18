<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Immich\ImmichPlugin;
use App\Models\Integration;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImmichPluginTest extends TestCase
{
    protected ImmichPlugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plugin = new ImmichPlugin;
    }

    #[Test]
    public function it_has_correct_metadata()
    {
        $this->assertEquals('immich', $this->plugin->getIdentifier());
        $this->assertEquals('Immich', $this->plugin->getDisplayName());
        $this->assertEquals('media', $this->plugin->getDomain());
        $this->assertEquals('fas.images', $this->plugin->getIcon());
    }

    #[Test]
    public function it_uses_apikey_service_type()
    {
        $this->assertEquals('apikey', $this->plugin->getServiceType());
    }

    #[Test]
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

    #[Test]
    public function it_defines_configuration_schema()
    {
        $configSchema = $this->plugin->getConfigurationSchema();

        $this->assertArrayHasKey('update_frequency_minutes', $configSchema);
        $this->assertArrayHasKey('sync_mode', $configSchema);
        $this->assertArrayHasKey('sync_people', $configSchema);
        $this->assertArrayHasKey('cluster_radius_km', $configSchema);
        $this->assertArrayHasKey('cluster_window_minutes', $configSchema);
    }

    #[Test]
    public function it_merges_group_schema_into_configuration_schema()
    {
        $configSchema = $this->plugin->getConfigurationSchema();

        // Group-level fields should be included in the configuration schema
        $this->assertArrayHasKey('server_url', $configSchema);
        $this->assertArrayHasKey('api_key', $configSchema);

        // Verify they have the correct properties
        $this->assertEquals('string', $configSchema['server_url']['type']);
        $this->assertTrue($configSchema['server_url']['required']);
        $this->assertEquals('string', $configSchema['api_key']['type']);
        $this->assertTrue($configSchema['api_key']['required']);
        $this->assertTrue($configSchema['api_key']['secure']);
    }

    #[Test]
    public function it_defines_took_photos_action_type()
    {
        $actionTypes = $this->plugin->getActionTypes();

        $this->assertArrayHasKey('took_photos', $actionTypes);
        $this->assertEquals('Took Photos', $actionTypes['took_photos']['display_name']);
        $this->assertEquals('photos', $actionTypes['took_photos']['value_unit']);
        $this->assertFalse($actionTypes['took_photos']['hidden']);
    }

    #[Test]
    public function it_defines_immich_photo_block_type()
    {
        $blockTypes = $this->plugin->getBlockTypes();

        $this->assertArrayHasKey('immich_photo', $blockTypes);
        $this->assertEquals('Photo', $blockTypes['immich_photo']['display_name']);
    }

    #[Test]
    public function it_defines_cluster_summary_block_type()
    {
        $blockTypes = $this->plugin->getBlockTypes();

        $this->assertArrayHasKey('cluster_summary', $blockTypes);
        $this->assertEquals('Cluster Summary', $blockTypes['cluster_summary']['display_name']);
    }

    #[Test]
    public function it_defines_cluster_people_block_type()
    {
        $blockTypes = $this->plugin->getBlockTypes();

        $this->assertArrayHasKey('cluster_people', $blockTypes);
        $this->assertEquals('People in Cluster', $blockTypes['cluster_people']['display_name']);
    }

    #[Test]
    public function it_defines_immich_user_object_type()
    {
        $objectTypes = $this->plugin->getObjectTypes();

        $this->assertArrayHasKey('immich_user', $objectTypes);
        $this->assertEquals('Immich User', $objectTypes['immich_user']['display_name']);
        $this->assertTrue($objectTypes['immich_user']['hidden']);
    }

    #[Test]
    public function it_defines_immich_cluster_object_type()
    {
        $objectTypes = $this->plugin->getObjectTypes();

        $this->assertArrayHasKey('immich_cluster', $objectTypes);
        $this->assertEquals('Photo Cluster', $objectTypes['immich_cluster']['display_name']);
    }

    #[Test]
    public function it_defines_immich_person_object_type()
    {
        $objectTypes = $this->plugin->getObjectTypes();

        $this->assertArrayHasKey('immich_person', $objectTypes);
        $this->assertEquals('Person', $objectTypes['immich_person']['display_name']);
    }

    #[Test]
    public function it_defines_photos_instance_type()
    {
        $instanceTypes = $this->plugin->getInstanceTypes();

        $this->assertArrayHasKey('photos', $instanceTypes);
        $this->assertEquals('Photos', $instanceTypes['photos']['label']);
    }

    #[Test]
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

    #[Test]
    public function it_validates_sync_mode_options()
    {
        $configSchema = $this->plugin->getConfigurationSchema();

        $this->assertArrayHasKey('options', $configSchema['sync_mode']);
        $this->assertEquals(['recent', 'full'], $configSchema['sync_mode']['options']);
    }

    #[Test]
    public function it_has_primary_accent_color()
    {
        $this->assertEquals('primary', $this->plugin->getAccentColor());
    }

    #[Test]
    public function it_supports_migration()
    {
        $this->assertTrue($this->plugin->supportsMigration());
    }

    #[Test]
    public function it_pulls_photos_via_metadata_search_and_follows_pages()
    {
        Http::fake([
            'immich.test/api/search/metadata' => Http::sequence()
                ->push(['assets' => ['items' => [['id' => 'a1'], ['id' => 'a2']], 'nextPage' => '2']])
                ->push(['assets' => ['items' => [['id' => 'a3']], 'nextPage' => null]]),
        ]);

        $integration = new Integration(['id' => 'test-integration']);
        $result = $this->plugin->pullPhotoData($integration, 'https://immich.test', 'key', '2026-10-01T00:00:00+00:00');

        $this->assertEquals(['a1', 'a2', 'a3'], array_column($result['assets'], 'id'));

        $requests = Http::recorded();
        $this->assertCount(2, $requests);

        [$first] = $requests[0];
        $this->assertInstanceOf(Request::class, $first);
        $this->assertEquals('POST', $first->method());
        $this->assertEquals('2026-10-01T00:00:00+00:00', $first['updatedAfter']);
        $this->assertTrue($first['withExif']);
        $this->assertTrue($first['withPeople']);
        $this->assertEquals(1, $first['page']);
        $this->assertEquals(2, $requests[1][0]['page']);
    }

    #[Test]
    public function it_follows_cursor_pagination_for_photos()
    {
        Http::fake([
            'immich.test/api/search/metadata' => Http::sequence()
                ->push(['assets' => ['items' => [['id' => 'a1']], 'nextCursor' => 'abc', 'nextPage' => null]])
                ->push(['assets' => ['items' => [['id' => 'a2']], 'nextCursor' => null, 'nextPage' => null]]),
        ]);

        $integration = new Integration(['id' => 'test-integration']);
        $result = $this->plugin->pullPhotoData($integration, 'https://immich.test', 'key');

        $this->assertEquals(['a1', 'a2'], array_column($result['assets'], 'id'));

        $second = Http::recorded()[1][0];
        $this->assertEquals('abc', $second['cursor']);
        $this->assertArrayNotHasKey('page', $second->data());
    }

    #[Test]
    public function it_sends_with_hidden_as_string_when_pulling_people()
    {
        Http::fake([
            'immich.test/api/people*' => Http::sequence()
                ->push(['people' => [['id' => 'p1']], 'hasNextPage' => true, 'total' => 2, 'hidden' => 0])
                ->push(['people' => [['id' => 'p2']], 'hasNextPage' => false, 'total' => 2, 'hidden' => 0]),
        ]);

        $integration = new Integration(['id' => 'test-integration']);
        $result = $this->plugin->pullPeopleData($integration, 'https://immich.test', 'key');

        $this->assertEquals(['p1', 'p2'], array_column($result['people'], 'id'));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'withHidden=true')
            && str_contains($request->url(), 'page=1'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'page=2'));
    }
}
