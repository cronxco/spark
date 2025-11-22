<?php

namespace Tests\Feature\Integrations\Oura;

use App\Integrations\Oura\OuraPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OuraPluginTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function plugin_has_correct_metadata(): void
    {
        $this->assertEquals('oura', OuraPlugin::getIdentifier());
        $this->assertEquals('Oura', OuraPlugin::getDisplayName());
        $this->assertEquals('oauth', OuraPlugin::getServiceType());
        $this->assertEquals('health', OuraPlugin::getDomain());

        $description = OuraPlugin::getDescription();
        $this->assertStringContainsString('Oura', $description);
    }

    #[Test]
    public function plugin_has_configuration_schema(): void
    {
        $schema = OuraPlugin::getConfigurationSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('update_frequency_minutes', $schema);
    }

    #[Test]
    public function plugin_has_action_types(): void
    {
        $actionTypes = OuraPlugin::getActionTypes();

        $this->assertArrayHasKey('slept_for', $actionTypes);
        $this->assertArrayHasKey('had_heart_rate', $actionTypes);
        $this->assertArrayHasKey('did_workout', $actionTypes);
        $this->assertArrayHasKey('had_mindfulness_session', $actionTypes);
        $this->assertArrayHasKey('had_oura_tag', $actionTypes);
        $this->assertArrayHasKey('had_readiness_score', $actionTypes);
        $this->assertArrayHasKey('had_sleep_score', $actionTypes);
        $this->assertArrayHasKey('had_activity_score', $actionTypes);
        $this->assertArrayHasKey('had_stress_score', $actionTypes);

        $this->assertEquals('Sleep', $actionTypes['slept_for']['display_name']);
        $this->assertEquals('Heart Rate', $actionTypes['had_heart_rate']['display_name']);
        $this->assertEquals('Workout', $actionTypes['did_workout']['display_name']);
        $this->assertEquals('Readiness Score', $actionTypes['had_readiness_score']['display_name']);
    }

    #[Test]
    public function plugin_action_types_have_correct_value_units(): void
    {
        $actionTypes = OuraPlugin::getActionTypes();

        $this->assertEquals('seconds', $actionTypes['slept_for']['value_unit']);
        $this->assertEquals('bpm', $actionTypes['had_heart_rate']['value_unit']);
        $this->assertEquals('kcal', $actionTypes['did_workout']['value_unit']);
        $this->assertEquals('percent', $actionTypes['had_readiness_score']['value_unit']);
        $this->assertEquals('percent', $actionTypes['had_sleep_score']['value_unit']);
        $this->assertEquals('percent', $actionTypes['had_activity_score']['value_unit']);
    }

    #[Test]
    public function plugin_has_object_types(): void
    {
        $objectTypes = OuraPlugin::getObjectTypes();

        $this->assertIsArray($objectTypes);
        $this->assertArrayHasKey('oura_user', $objectTypes);
        $this->assertArrayHasKey('oura_workout', $objectTypes);
        $this->assertArrayHasKey('oura_tag', $objectTypes);
    }

    #[Test]
    public function plugin_has_block_types(): void
    {
        $blockTypes = OuraPlugin::getBlockTypes();

        $this->assertIsArray($blockTypes);
        // Oura should have various block types for health data
    }

    #[Test]
    public function plugin_supports_migration(): void
    {
        $this->assertTrue(OuraPlugin::supportsMigration());
    }

    #[Test]
    public function plugin_has_instance_types(): void
    {
        $instanceTypes = OuraPlugin::getInstanceTypes();

        $this->assertIsArray($instanceTypes);
        $this->assertNotEmpty($instanceTypes);
    }

    #[Test]
    public function plugin_has_icon(): void
    {
        $icon = OuraPlugin::getIcon();

        $this->assertEquals('fas.ring', $icon);
    }

    #[Test]
    public function plugin_has_accent_color(): void
    {
        $accentColor = OuraPlugin::getAccentColor();

        $this->assertEquals('primary', $accentColor);
    }

    #[Test]
    public function plugin_implements_value_mapping(): void
    {
        // OuraPlugin implements SupportsValueMapping interface
        $this->assertInstanceOf(\App\Integrations\Contracts\SupportsValueMapping::class, new OuraPlugin);
    }

    #[Test]
    public function plugin_action_types_have_value_formatters(): void
    {
        $actionTypes = OuraPlugin::getActionTypes();

        // Check that score actions have formatters
        $this->assertArrayHasKey('value_formatter', $actionTypes['had_readiness_score']);
        $this->assertArrayHasKey('value_formatter', $actionTypes['had_sleep_score']);
        $this->assertArrayHasKey('value_formatter', $actionTypes['had_activity_score']);
        $this->assertArrayHasKey('value_formatter', $actionTypes['slept_for']);
    }

    #[Test]
    public function plugin_action_types_have_icons(): void
    {
        $actionTypes = OuraPlugin::getActionTypes();

        foreach ($actionTypes as $action => $config) {
            $this->assertArrayHasKey('icon', $config, "Action '$action' should have an icon");
            $this->assertNotEmpty($config['icon'], "Action '$action' icon should not be empty");
        }
    }

    #[Test]
    public function plugin_action_types_have_display_names(): void
    {
        $actionTypes = OuraPlugin::getActionTypes();

        foreach ($actionTypes as $action => $config) {
            $this->assertArrayHasKey('display_name', $config, "Action '$action' should have a display_name");
            $this->assertNotEmpty($config['display_name'], "Action '$action' display_name should not be empty");
        }
    }

    #[Test]
    public function plugin_action_types_have_descriptions(): void
    {
        $actionTypes = OuraPlugin::getActionTypes();

        foreach ($actionTypes as $action => $config) {
            $this->assertArrayHasKey('description', $config, "Action '$action' should have a description");
            $this->assertNotEmpty($config['description'], "Action '$action' description should not be empty");
        }
    }
}
