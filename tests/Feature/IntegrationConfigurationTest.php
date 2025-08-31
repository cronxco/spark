<?php

namespace Tests\Feature;

use App\Integrations\PluginRegistry;
use Tests\TestCase;

class IntegrationConfigurationTest extends TestCase
{
    private array $validDomains;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validDomains = PluginRegistry::getValidDomains();
    }

    /**
     * @test
     */
    public function all_plugins_have_required_configuration_methods(): void
    {
        $plugins = PluginRegistry::getAllPlugins();

        foreach ($plugins as $pluginClass) {
            $this->assertTrue(method_exists($pluginClass, 'getIcon'), "Plugin {$pluginClass} missing getIcon method");
            $this->assertTrue(method_exists($pluginClass, 'getAccentColor'), "Plugin {$pluginClass} missing getAccentColor method");
            $this->assertTrue(method_exists($pluginClass, 'getDomain'), "Plugin {$pluginClass} missing getDomain method");
            $this->assertTrue(method_exists($pluginClass, 'getActionTypes'), "Plugin {$pluginClass} missing getActionTypes method");
            $this->assertTrue(method_exists($pluginClass, 'getBlockTypes'), "Plugin {$pluginClass} missing getBlockTypes method");
            $this->assertTrue(method_exists($pluginClass, 'getObjectTypes'), "Plugin {$pluginClass} missing getObjectTypes method");
        }
    }

    /**
     * @test
     */
    public function all_plugins_return_valid_icon_strings(): void
    {
        $plugins = PluginRegistry::getAllPlugins();

        foreach ($plugins as $pluginClass) {
            $icon = $pluginClass::getIcon();
            $this->assertIsString($icon, "Plugin {$pluginClass} getIcon must return a string");
            $this->assertNotEmpty($icon, "Plugin {$pluginClass} getIcon must not be empty");
        }
    }

    /**
     * @test
     */
    public function all_plugins_return_valid_accent_colors(): void
    {
        $plugins = PluginRegistry::getAllPlugins();
        $validColors = ['primary', 'secondary', 'accent', 'neutral', 'info', 'success', 'warning', 'error'];

        foreach ($plugins as $pluginClass) {
            $color = $pluginClass::getAccentColor();
            $this->assertIsString($color, "Plugin {$pluginClass} getAccentColor must return a string");
            $this->assertContains($color, $validColors, "Plugin {$pluginClass} getAccentColor must be a valid daisyUI color");
        }
    }

    /**
     * @test
     */
    public function all_plugins_return_valid_domain(): void
    {
        $plugins = PluginRegistry::getAllPlugins();

        foreach ($plugins as $pluginClass) {
            $domain = $pluginClass::getDomain();
            $this->assertIsString($domain, "Plugin {$pluginClass} getDomain must return a string");
            $this->assertNotEmpty($domain, "Plugin {$pluginClass} getDomain must not be empty");
            $this->assertContains($domain, $this->validDomains, "Plugin {$pluginClass} getDomain must be a valid domain");
        }
    }

    /**
     * @test
     */
    public function all_plugins_return_valid_action_types(): void
    {
        $plugins = PluginRegistry::getAllPlugins();

        foreach ($plugins as $pluginClass) {
            $actionTypes = $pluginClass::getActionTypes();
            $this->assertIsArray($actionTypes, "Plugin {$pluginClass} getActionTypes must return an array");

            foreach ($actionTypes as $actionKey => $actionConfig) {
                $this->assertIsString($actionKey, 'Action key must be a string');
                $this->assertIsArray($actionConfig, 'Action config must be an array');

                // Required fields
                $this->assertArrayHasKey('icon', $actionConfig, "Action config must have 'icon' key");
                $this->assertArrayHasKey('display_name', $actionConfig, "Action config must have 'display_name' key");
                $this->assertArrayHasKey('description', $actionConfig, "Action config must have 'description' key");
                $this->assertArrayHasKey('display_with_object', $actionConfig, "Action config must have 'display_with_object' key");
                $this->assertArrayHasKey('value_unit', $actionConfig, "Action config must have 'value_unit' key");
                $this->assertArrayHasKey('hidden', $actionConfig, "Action config must have 'hidden' key");

                // Field types and validation
                $this->assertIsString($actionConfig['icon'], 'Action icon must be a string');
                $this->assertIsString($actionConfig['display_name'], 'Action display_name must be a string');
                $this->assertIsString($actionConfig['description'], 'Action description must be a string');
                $this->assertIsBool($actionConfig['display_with_object'], 'display_with_object must be a boolean');
                $this->assertIsBool($actionConfig['hidden'], 'hidden must be a boolean');
                $this->assertTrue(
                    $actionConfig['value_unit'] === null || is_string($actionConfig['value_unit']),
                    'value_unit must be null or a string'
                );

                // Content validation
                $this->assertNotEmpty($actionConfig['display_name'], 'Action display_name must not be empty');
                $this->assertNotEmpty($actionConfig['description'], 'Action description must not be empty');
            }
        }
    }

    /**
     * @test
     */
    public function all_plugins_return_valid_block_types(): void
    {
        $plugins = PluginRegistry::getAllPlugins();

        foreach ($plugins as $pluginClass) {
            $blockTypes = $pluginClass::getBlockTypes();
            $this->assertIsArray($blockTypes, "Plugin {$pluginClass} getBlockTypes must return an array");

            foreach ($blockTypes as $blockKey => $blockConfig) {
                $this->assertIsString($blockKey, 'Block key must be a string');
                $this->assertIsArray($blockConfig, 'Block config must be an array');

                // Required fields
                $this->assertArrayHasKey('icon', $blockConfig, "Block config must have 'icon' key");
                $this->assertArrayHasKey('display_name', $blockConfig, "Block config must have 'display_name' key");
                $this->assertArrayHasKey('description', $blockConfig, "Block config must have 'description' key");
                $this->assertArrayHasKey('display_with_object', $blockConfig, "Block config must have 'display_with_object' key");
                $this->assertArrayHasKey('value_unit', $blockConfig, "Block config must have 'value_unit' key");
                $this->assertArrayHasKey('hidden', $blockConfig, "Block config must have 'hidden' key");

                // Field types and validation
                $this->assertIsString($blockConfig['icon'], 'Block icon must be a string');
                $this->assertIsString($blockConfig['display_name'], 'Block display_name must be a string');
                $this->assertIsString($blockConfig['description'], 'Block description must be a string');
                $this->assertIsBool($blockConfig['display_with_object'], 'display_with_object must be a boolean');
                $this->assertIsBool($blockConfig['hidden'], 'hidden must be a boolean');
                $this->assertTrue(
                    $blockConfig['value_unit'] === null || is_string($blockConfig['value_unit']),
                    'value_unit must be null or a string'
                );

                // Content validation
                $this->assertNotEmpty($blockConfig['display_name'], 'Block display_name must not be empty');
                $this->assertNotEmpty($blockConfig['description'], 'Block description must not be empty');
            }
        }
    }

    /**
     * @test
     */
    public function all_plugins_return_valid_object_types(): void
    {
        $plugins = PluginRegistry::getAllPlugins();

        foreach ($plugins as $pluginClass) {
            $objectTypes = $pluginClass::getObjectTypes();
            $this->assertIsArray($objectTypes, "Plugin {$pluginClass} getObjectTypes must return an array");

            foreach ($objectTypes as $objectKey => $objectConfig) {
                $this->assertIsString($objectKey, 'Object key must be a string');
                $this->assertIsArray($objectConfig, 'Object config must be an array');

                // Required fields
                $this->assertArrayHasKey('icon', $objectConfig, "Object config must have 'icon' key");
                $this->assertArrayHasKey('display_name', $objectConfig, "Object config must have 'display_name' key");
                $this->assertArrayHasKey('description', $objectConfig, "Object config must have 'description' key");
                $this->assertArrayHasKey('hidden', $objectConfig, "Object config must have 'hidden' key");

                // Field types and validation
                $this->assertIsString($objectConfig['icon'], 'Object icon must be a string');
                $this->assertIsString($objectConfig['display_name'], 'Object display_name must be a string');
                $this->assertIsString($objectConfig['description'], 'Object description must be a string');
                $this->assertIsBool($objectConfig['hidden'], 'hidden must be a boolean');

                // Content validation
                $this->assertNotEmpty($objectConfig['display_name'], 'Object display_name must not be empty');
                $this->assertNotEmpty($objectConfig['description'], 'Object description must not be empty');
            }
        }
    }

    /**
     * @test
     */
    public function plugin_registry_get_plugins_with_config(): void
    {
        $pluginsWithConfig = PluginRegistry::getPluginsWithConfig();
        $this->assertGreaterThan(0, $pluginsWithConfig->count(), 'Should have at least one plugin');

        foreach ($pluginsWithConfig as $pluginConfig) {
            $this->assertArrayHasKey('identifier', $pluginConfig);
            $this->assertArrayHasKey('display_name', $pluginConfig);
            $this->assertArrayHasKey('description', $pluginConfig);
            $this->assertArrayHasKey('service_type', $pluginConfig);
            $this->assertArrayHasKey('icon', $pluginConfig);
            $this->assertArrayHasKey('accent_color', $pluginConfig);
            $this->assertArrayHasKey('domain', $pluginConfig);
            $this->assertArrayHasKey('action_types', $pluginConfig);
            $this->assertArrayHasKey('block_types', $pluginConfig);
            $this->assertArrayHasKey('object_types', $pluginConfig);
            $this->assertArrayHasKey('instance_types', $pluginConfig);
        }
    }

    /**
     * @test
     */
    public function plugin_registry_get_plugin_config(): void
    {
        $plugins = PluginRegistry::getAllPlugins();
        $firstPlugin = $plugins->first();

        if ($firstPlugin) {
            $identifier = $firstPlugin::getIdentifier();
            $pluginConfig = PluginRegistry::getPluginConfig($identifier);

            $this->assertNotNull($pluginConfig, 'Should return config for existing plugin');
            $this->assertEquals($identifier, $pluginConfig['identifier']);
            $this->assertEquals($firstPlugin::getDisplayName(), $pluginConfig['display_name']);
            $this->assertEquals($firstPlugin::getIcon(), $pluginConfig['icon']);
            $this->assertEquals($firstPlugin::getAccentColor(), $pluginConfig['accent_color']);
            $this->assertEquals($firstPlugin::getDomain(), $pluginConfig['domain']);
        }
    }

    /**
     * @test
     */
    public function block_types_are_consistent_across_plugins(): void
    {
        $plugins = PluginRegistry::getAllPlugins();

        foreach ($plugins as $pluginClass) {
            $blockTypes = $pluginClass::getBlockTypes();

            // Each plugin should have at least one block type defined (if they create blocks)
            if (count($blockTypes) > 0) {
                // Block type keys should be snake_case (allowing numbers for medical terms like 'spo2')
                foreach (array_keys($blockTypes) as $blockType) {
                    $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $blockType, "Block type '{$blockType}' should be snake_case with numbers allowed");
                }
            }
        }
    }

    /**
     * @test
     */
    public function action_types_are_consistent_across_plugins(): void
    {
        $plugins = PluginRegistry::getAllPlugins();

        foreach ($plugins as $pluginClass) {
            $actionTypes = $pluginClass::getActionTypes();

            // Each plugin should have at least one action type defined (if they create events)
            if (count($actionTypes) > 0) {
                // Action type keys should be snake_case (allowing numbers for medical terms like 'spo2')
                foreach (array_keys($actionTypes) as $actionType) {
                    $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $actionType, "Action type '{$actionType}' should be snake_case with numbers allowed");
                }
            }
        }
    }

    /**
     * @test
     */
    public function object_types_are_consistent_across_plugins(): void
    {
        $plugins = PluginRegistry::getAllPlugins();

        foreach ($plugins as $pluginClass) {
            $objectTypes = $pluginClass::getObjectTypes();

            // Each plugin should have at least one object type defined (if they create objects)
            if (count($objectTypes) > 0) {
                // Object type keys should be snake_case (allowing numbers for medical terms like 'spo2')
                // Also allow PHP variable syntax like 'oura_daily_{$kind}'
                foreach (array_keys($objectTypes) as $objectType) {
                    $this->assertMatchesRegularExpression('/^[a-z0-9_{}$]+$/', $objectType, "Object type '{$objectType}' should be snake_case with numbers and PHP variables allowed");
                }
            }
        }
    }
}
