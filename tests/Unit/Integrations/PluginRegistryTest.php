<?php

namespace Tests\Unit\Integrations;

use App\Integrations\PluginRegistry;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PluginRegistryTest extends TestCase
{
    #[Test]
    public function get_valid_domains_returns_expected_domains(): void
    {
        $domains = PluginRegistry::getValidDomains();

        $this->assertIsArray($domains);
        $this->assertContains('health', $domains);
        $this->assertContains('money', $domains);
        $this->assertContains('media', $domains);
        $this->assertContains('knowledge', $domains);
        $this->assertContains('online', $domains);
        $this->assertCount(5, $domains);
    }

    #[Test]
    public function get_all_plugins_returns_collection(): void
    {
        $plugins = PluginRegistry::getAllPlugins();

        $this->assertInstanceOf(Collection::class, $plugins);
    }

    #[Test]
    public function get_all_plugins_contains_registered_plugins(): void
    {
        $plugins = PluginRegistry::getAllPlugins();

        // There should be at least some plugins registered
        $this->assertGreaterThan(0, $plugins->count());
    }

    #[Test]
    public function get_plugin_returns_class_for_valid_identifier(): void
    {
        $plugins = PluginRegistry::getAllPlugins();

        if ($plugins->isEmpty()) {
            $this->markTestSkipped('No plugins registered');
        }

        $identifier = $plugins->keys()->first();
        $pluginClass = PluginRegistry::getPlugin($identifier);

        $this->assertNotNull($pluginClass);
        $this->assertIsString($pluginClass);
    }

    #[Test]
    public function get_plugin_returns_null_for_invalid_identifier(): void
    {
        $pluginClass = PluginRegistry::getPlugin('non_existent_plugin_xyz');

        $this->assertNull($pluginClass);
    }

    #[Test]
    public function get_plugin_instance_returns_object_for_valid_identifier(): void
    {
        $plugins = PluginRegistry::getAllPlugins();

        if ($plugins->isEmpty()) {
            $this->markTestSkipped('No plugins registered');
        }

        $identifier = $plugins->keys()->first();
        $instance = PluginRegistry::getPluginInstance($identifier);

        $this->assertNotNull($instance);
        $this->assertIsObject($instance);
    }

    #[Test]
    public function get_plugin_instance_returns_null_for_invalid_identifier(): void
    {
        $instance = PluginRegistry::getPluginInstance('non_existent_plugin_xyz');

        $this->assertNull($instance);
    }

    #[Test]
    public function get_oauth_plugins_returns_collection(): void
    {
        $oauthPlugins = PluginRegistry::getOAuthPlugins();

        $this->assertInstanceOf(Collection::class, $oauthPlugins);

        // All returned plugins should be OAuth type
        foreach ($oauthPlugins as $pluginClass) {
            $this->assertEquals('oauth', $pluginClass::getServiceType());
        }
    }

    #[Test]
    public function get_webhook_plugins_returns_collection(): void
    {
        $webhookPlugins = PluginRegistry::getWebhookPlugins();

        $this->assertInstanceOf(Collection::class, $webhookPlugins);

        // All returned plugins should be webhook type
        foreach ($webhookPlugins as $pluginClass) {
            $this->assertEquals('webhook', $pluginClass::getServiceType());
        }
    }

    #[Test]
    public function get_manual_plugins_returns_collection(): void
    {
        $manualPlugins = PluginRegistry::getManualPlugins();

        $this->assertInstanceOf(Collection::class, $manualPlugins);

        // All returned plugins should be manual type
        foreach ($manualPlugins as $pluginClass) {
            $this->assertEquals('manual', $pluginClass::getServiceType());
        }
    }

    #[Test]
    public function get_api_key_plugins_returns_collection(): void
    {
        $apiKeyPlugins = PluginRegistry::getApiKeyPlugins();

        $this->assertInstanceOf(Collection::class, $apiKeyPlugins);

        // All returned plugins should be apikey type
        foreach ($apiKeyPlugins as $pluginClass) {
            $this->assertEquals('apikey', $pluginClass::getServiceType());
        }
    }

    #[Test]
    public function get_plugins_with_config_returns_correct_structure(): void
    {
        $configs = PluginRegistry::getPluginsWithConfig();

        $this->assertInstanceOf(Collection::class, $configs);

        if ($configs->isEmpty()) {
            $this->markTestSkipped('No plugins registered');
        }

        $firstConfig = $configs->first();

        $this->assertArrayHasKey('identifier', $firstConfig);
        $this->assertArrayHasKey('display_name', $firstConfig);
        $this->assertArrayHasKey('description', $firstConfig);
        $this->assertArrayHasKey('service_type', $firstConfig);
        $this->assertArrayHasKey('icon', $firstConfig);
        $this->assertArrayHasKey('accent_color', $firstConfig);
        $this->assertArrayHasKey('domain', $firstConfig);
        $this->assertArrayHasKey('action_types', $firstConfig);
        $this->assertArrayHasKey('block_types', $firstConfig);
        $this->assertArrayHasKey('object_types', $firstConfig);
        $this->assertArrayHasKey('instance_types', $firstConfig);
    }

    #[Test]
    public function get_plugin_config_returns_config_for_valid_identifier(): void
    {
        $plugins = PluginRegistry::getAllPlugins();

        if ($plugins->isEmpty()) {
            $this->markTestSkipped('No plugins registered');
        }

        $identifier = $plugins->keys()->first();
        $config = PluginRegistry::getPluginConfig($identifier);

        $this->assertNotNull($config);
        $this->assertIsArray($config);
        $this->assertEquals($identifier, $config['identifier']);
    }

    #[Test]
    public function get_plugin_config_returns_null_for_invalid_identifier(): void
    {
        $config = PluginRegistry::getPluginConfig('non_existent_plugin_xyz');

        $this->assertNull($config);
    }

    #[Test]
    public function get_spotlight_commands_returns_collection(): void
    {
        $commands = PluginRegistry::getSpotlightCommands();

        $this->assertInstanceOf(Collection::class, $commands);
    }

    #[Test]
    public function spotlight_commands_have_correct_structure(): void
    {
        $commands = PluginRegistry::getSpotlightCommands();

        if ($commands->isEmpty()) {
            $this->markTestSkipped('No spotlight commands available');
        }

        $firstCommand = $commands->first();

        $this->assertArrayHasKey('plugin', $firstCommand);
        $this->assertArrayHasKey('key', $firstCommand);
        $this->assertArrayHasKey('command', $firstCommand);
    }

    #[Test]
    public function all_plugins_have_valid_domains(): void
    {
        $validDomains = PluginRegistry::getValidDomains();
        $configs = PluginRegistry::getPluginsWithConfig();

        foreach ($configs as $config) {
            $this->assertContains(
                $config['domain'],
                $validDomains,
                "Plugin {$config['identifier']} has invalid domain: {$config['domain']}"
            );
        }
    }

    #[Test]
    public function all_plugins_have_valid_service_types(): void
    {
        $validServiceTypes = ['oauth', 'webhook', 'manual', 'apikey'];
        $configs = PluginRegistry::getPluginsWithConfig();

        foreach ($configs as $config) {
            $this->assertContains(
                $config['service_type'],
                $validServiceTypes,
                "Plugin {$config['identifier']} has invalid service_type: {$config['service_type']}"
            );
        }
    }

    #[Test]
    public function all_plugins_have_display_names(): void
    {
        $configs = PluginRegistry::getPluginsWithConfig();

        foreach ($configs as $config) {
            $this->assertNotEmpty(
                $config['display_name'],
                "Plugin {$config['identifier']} has empty display_name"
            );
        }
    }

    #[Test]
    public function all_plugins_have_icons(): void
    {
        $configs = PluginRegistry::getPluginsWithConfig();

        foreach ($configs as $config) {
            $this->assertNotEmpty(
                $config['icon'],
                "Plugin {$config['identifier']} has empty icon"
            );
        }
    }

    #[Test]
    public function plugin_action_types_are_arrays(): void
    {
        $configs = PluginRegistry::getPluginsWithConfig();

        foreach ($configs as $config) {
            $this->assertIsArray(
                $config['action_types'],
                "Plugin {$config['identifier']} action_types is not an array"
            );
        }
    }

    #[Test]
    public function plugin_block_types_are_arrays(): void
    {
        $configs = PluginRegistry::getPluginsWithConfig();

        foreach ($configs as $config) {
            $this->assertIsArray(
                $config['block_types'],
                "Plugin {$config['identifier']} block_types is not an array"
            );
        }
    }

    #[Test]
    public function plugin_object_types_are_arrays(): void
    {
        $configs = PluginRegistry::getPluginsWithConfig();

        foreach ($configs as $config) {
            $this->assertIsArray(
                $config['object_types'],
                "Plugin {$config['identifier']} object_types is not an array"
            );
        }
    }

    #[Test]
    public function plugin_instance_types_are_arrays(): void
    {
        $configs = PluginRegistry::getPluginsWithConfig();

        foreach ($configs as $config) {
            $this->assertIsArray(
                $config['instance_types'],
                "Plugin {$config['identifier']} instance_types is not an array"
            );
        }
    }
}
