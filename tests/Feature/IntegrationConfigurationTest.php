<?php

namespace Tests\Feature;

use App\Integrations\PluginRegistry;
use Tests\TestCase;

class IntegrationConfigurationTest extends TestCase
{
    public function test_all_plugins_have_required_configuration_methods(): void
    {
        $plugins = PluginRegistry::getAllPlugins();

        foreach ($plugins as $pluginClass) {
            $this->assertTrue(method_exists($pluginClass, 'getIcon'), "Plugin {$pluginClass} missing getIcon method");
            $this->assertTrue(method_exists($pluginClass, 'getAccentColor'), "Plugin {$pluginClass} missing getAccentColor method");
            $this->assertTrue(method_exists($pluginClass, 'getActionTypes'), "Plugin {$pluginClass} missing getActionTypes method");
            $this->assertTrue(method_exists($pluginClass, 'getBlockTypes'), "Plugin {$pluginClass} missing getBlockTypes method");
        }
    }

    public function test_all_plugins_return_valid_icon_strings(): void
    {
        $plugins = PluginRegistry::getAllPlugins();

        foreach ($plugins as $pluginClass) {
            $icon = $pluginClass::getIcon();
            $this->assertIsString($icon, "Plugin {$pluginClass} getIcon must return a string");
            $this->assertNotEmpty($icon, "Plugin {$pluginClass} getIcon must not be empty");
        }
    }

    public function test_all_plugins_return_valid_accent_colors(): void
    {
        $plugins = PluginRegistry::getAllPlugins();
        $validColors = ['primary', 'secondary', 'accent', 'neutral', 'info', 'success', 'warning', 'error'];

        foreach ($plugins as $pluginClass) {
            $color = $pluginClass::getAccentColor();
            $this->assertIsString($color, "Plugin {$pluginClass} getAccentColor must return a string");
            $this->assertContains($color, $validColors, "Plugin {$pluginClass} getAccentColor must be a valid daisyUI color");
        }
    }

    public function test_all_plugins_return_valid_action_types(): void
    {
        $plugins = PluginRegistry::getAllPlugins();

        foreach ($plugins as $pluginClass) {
            $actionTypes = $pluginClass::getActionTypes();
            $this->assertIsArray($actionTypes, "Plugin {$pluginClass} getActionTypes must return an array");

            foreach ($actionTypes as $actionKey => $actionConfig) {
                $this->assertIsString($actionKey, "Action key must be a string");
                $this->assertIsArray($actionConfig, "Action config must be an array");
                $this->assertArrayHasKey('icon', $actionConfig, "Action config must have 'icon' key");
                $this->assertArrayHasKey('display_with_object', $actionConfig, "Action config must have 'display_with_object' key");
                $this->assertArrayHasKey('value_unit', $actionConfig, "Action config must have 'value_unit' key");

                $this->assertIsString($actionConfig['icon'], "Action icon must be a string");
                $this->assertIsBool($actionConfig['display_with_object'], "display_with_object must be a boolean");
                $this->assertTrue(
                    $actionConfig['value_unit'] === null || is_string($actionConfig['value_unit']),
                    "value_unit must be null or a string"
                );
            }
        }
    }

    public function test_all_plugins_return_valid_block_types(): void
    {
        $plugins = PluginRegistry::getAllPlugins();

        foreach ($plugins as $pluginClass) {
            $blockTypes = $pluginClass::getBlockTypes();
            $this->assertIsArray($blockTypes, "Plugin {$pluginClass} getBlockTypes must return an array");

            foreach ($blockTypes as $blockKey => $blockConfig) {
                $this->assertIsString($blockKey, "Block key must be a string");
                $this->assertIsArray($blockConfig, "Block config must be an array");
                $this->assertArrayHasKey('icon', $blockConfig, "Block config must have 'icon' key");
                $this->assertArrayHasKey('display_with_object', $blockConfig, "Block config must have 'display_with_object' key");
                $this->assertArrayHasKey('value_unit', $blockConfig, "Block config must have 'value_unit' key");

                $this->assertIsString($blockConfig['icon'], "Block icon must be a string");
                $this->assertIsBool($blockConfig['display_with_object'], "display_with_object must be a boolean");
                $this->assertTrue(
                    $blockConfig['value_unit'] === null || is_string($blockConfig['value_unit']),
                    "value_unit must be null or a string"
                );
            }
        }
    }

    public function test_plugin_registry_get_plugins_with_config(): void
    {
        $pluginsWithConfig = PluginRegistry::getPluginsWithConfig();
        $this->assertGreaterThan(0, $pluginsWithConfig->count(), "Should have at least one plugin");

        foreach ($pluginsWithConfig as $pluginConfig) {
            $this->assertArrayHasKey('identifier', $pluginConfig);
            $this->assertArrayHasKey('display_name', $pluginConfig);
            $this->assertArrayHasKey('description', $pluginConfig);
            $this->assertArrayHasKey('service_type', $pluginConfig);
            $this->assertArrayHasKey('icon', $pluginConfig);
            $this->assertArrayHasKey('accent_color', $pluginConfig);
            $this->assertArrayHasKey('action_types', $pluginConfig);
            $this->assertArrayHasKey('block_types', $pluginConfig);
            $this->assertArrayHasKey('instance_types', $pluginConfig);
        }
    }

    public function test_plugin_registry_get_plugin_config(): void
    {
        $plugins = PluginRegistry::getAllPlugins();
        $firstPlugin = $plugins->first();

        if ($firstPlugin) {
            $identifier = $firstPlugin::getIdentifier();
            $pluginConfig = PluginRegistry::getPluginConfig($identifier);

            $this->assertNotNull($pluginConfig, "Should return config for existing plugin");
            $this->assertEquals($identifier, $pluginConfig['identifier']);
            $this->assertEquals($firstPlugin::getDisplayName(), $pluginConfig['display_name']);
            $this->assertEquals($firstPlugin::getIcon(), $pluginConfig['icon']);
            $this->assertEquals($firstPlugin::getAccentColor(), $pluginConfig['accent_color']);
        }
    }
}
