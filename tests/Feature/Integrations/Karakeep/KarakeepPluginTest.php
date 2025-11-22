<?php

namespace Tests\Feature\Integrations\Karakeep;

use App\Integrations\Karakeep\KarakeepPlugin;
use App\Jobs\Data\Karakeep\KarakeepBookmarksData;
use App\Jobs\OAuth\Karakeep\KarakeepBookmarksPull;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KarakeepPluginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function plugin_has_correct_metadata(): void
    {
        $this->assertEquals('karakeep', KarakeepPlugin::getIdentifier());
        $this->assertEquals('Karakeep', KarakeepPlugin::getDisplayName());
        $this->assertEquals('apikey', KarakeepPlugin::getServiceType());
        $this->assertEquals('knowledge', KarakeepPlugin::getDomain());
        $this->assertEquals('fas.bookmark', KarakeepPlugin::getIcon());
        $this->assertEquals('warning', KarakeepPlugin::getAccentColor());
        $this->assertTrue(KarakeepPlugin::supportsMigration());
    }

    /**
     * @test
     */
    public function plugin_defines_required_action_types(): void
    {
        $actionTypes = KarakeepPlugin::getActionTypes();

        $this->assertArrayHasKey('bookmarked', $actionTypes);
        $this->assertArrayHasKey('added_to_list', $actionTypes);

        $this->assertEquals('Saved Bookmark', $actionTypes['bookmarked']['display_name']);
        $this->assertEquals('Added to List', $actionTypes['added_to_list']['display_name']);
    }

    /**
     * @test
     */
    public function plugin_defines_required_block_types(): void
    {
        $blockTypes = KarakeepPlugin::getBlockTypes();

        $this->assertArrayHasKey('bookmark_summary', $blockTypes);
        $this->assertArrayHasKey('bookmark_metadata', $blockTypes);
        $this->assertArrayHasKey('bookmark_highlight', $blockTypes);

        $this->assertEquals('AI Summary', $blockTypes['bookmark_summary']['display_name']);
    }

    /**
     * @test
     */
    public function plugin_defines_required_object_types(): void
    {
        $objectTypes = KarakeepPlugin::getObjectTypes();

        $this->assertArrayHasKey('karakeep_bookmark', $objectTypes);
        $this->assertArrayHasKey('karakeep_list', $objectTypes);
        $this->assertArrayHasKey('karakeep_user', $objectTypes);

        $this->assertFalse($objectTypes['karakeep_bookmark']['hidden']);
        $this->assertTrue($objectTypes['karakeep_user']['hidden']);
    }

    /**
     * @test
     */
    public function initialize_group_creates_group_with_metadata(): void
    {
        $user = User::factory()->create();
        $plugin = new KarakeepPlugin;

        $group = $plugin->initializeGroup($user);

        $this->assertInstanceOf(IntegrationGroup::class, $group);
        $this->assertEquals($user->id, $group->user_id);
        $this->assertEquals('karakeep', $group->service);
        $this->assertIsArray($group->auth_metadata);
        $this->assertArrayHasKey('api_url', $group->auth_metadata);
    }

    /**
     * @test
     */
    public function create_instance_stores_configuration(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'karakeep',
        ]);

        $plugin = new KarakeepPlugin;
        $integration = $plugin->createInstance($group, 'bookmarks', [
            'update_frequency_minutes' => 30,
            'fetch_limit' => 50,
            'sync_highlights' => true,
        ]);

        $this->assertInstanceOf(Integration::class, $integration);
        $this->assertEquals('karakeep', $integration->service);
        $this->assertEquals('bookmarks', $integration->instance_type);
        $this->assertEquals(30, $integration->configuration['update_frequency_minutes']);
        $this->assertEquals(50, $integration->configuration['fetch_limit']);
        $this->assertTrue($integration->configuration['sync_highlights']);
    }

    /**
     * @test
     */
    public function create_instance_with_migration_flag_starts_paused(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'karakeep',
        ]);

        $plugin = new KarakeepPlugin;
        $integration = $plugin->createInstance($group, 'bookmarks', [], true);

        $this->assertTrue($integration->configuration['paused']);
    }

    /**
     * @test
     */
    public function pull_dispatches_processing_job(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'karakeep',
            'access_token' => 'test_token',
            'auth_metadata' => [
                'api_url' => 'https://karakeep.test',
            ],
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'karakeep',
            'instance_type' => 'bookmarks',
            'configuration' => [
                'update_frequency_minutes' => 30,
                'fetch_limit' => 50,
                'sync_highlights' => true,
            ],
        ]);

        Http::fake([
            'https://karakeep.test/api/v1/users/me' => Http::response([
                'id' => 'user123',
                'email' => 'test@example.com',
                'name' => 'Test User',
            ], 200),
            'https://karakeep.test/api/v1/bookmarks*' => Http::response([
                'bookmarks' => [
                    [
                        'id' => 'bookmark1',
                        'url' => 'https://example.com',
                        'title' => 'Example',
                        'summary' => 'A test bookmark',
                        'createdAt' => '2025-01-01T00:00:00Z',
                    ],
                ],
            ], 200),
            'https://karakeep.test/api/v1/tags' => Http::response([
                'tags' => [],
            ], 200),
            'https://karakeep.test/api/v1/lists' => Http::response([
                'lists' => [],
            ], 200),
            'https://karakeep.test/api/v1/highlights' => Http::response([
                'highlights' => [],
            ], 200),
        ]);

        (new KarakeepBookmarksPull($integration))->handle();

        Bus::assertDispatched(KarakeepBookmarksData::class);
    }

    /**
     * @test
     */
    public function instance_types_include_bookmarks(): void
    {
        $instanceTypes = KarakeepPlugin::getInstanceTypes();

        $this->assertArrayHasKey('bookmarks', $instanceTypes);
        $this->assertEquals('Bookmarks', $instanceTypes['bookmarks']['label']);
        $this->assertIsArray($instanceTypes['bookmarks']['schema']);
    }

    /**
     * @test
     */
    public function configuration_schema_includes_required_fields(): void
    {
        $schema = KarakeepPlugin::getConfigurationSchema();

        $this->assertArrayHasKey('update_frequency_minutes', $schema);
        $this->assertArrayHasKey('fetch_limit', $schema);

        $this->assertEquals(30, $schema['update_frequency_minutes']['default']);
        $this->assertEquals(50, $schema['fetch_limit']['default']);
    }

    /**
     * @test
     */
    public function group_configuration_schema_includes_api_credentials(): void
    {
        $schema = KarakeepPlugin::getGroupConfigurationSchema();

        $this->assertArrayHasKey('api_url', $schema);
        $this->assertArrayHasKey('access_token', $schema);

        $this->assertTrue($schema['api_url']['required']);
        $this->assertTrue($schema['access_token']['required']);
    }
}
