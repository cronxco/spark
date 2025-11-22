<?php

namespace Tests\Unit\Services;

use App\Services\RelationshipTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RelationshipTypeRegistryTest extends TestCase
{
    #[Test]
    public function it_returns_all_relationship_types(): void
    {
        $types = RelationshipTypeRegistry::getTypes();

        $this->assertIsArray($types);
        $this->assertNotEmpty($types);

        // Check that known types exist
        $this->assertArrayHasKey('linked_to', $types);
        $this->assertArrayHasKey('related_to', $types);
        $this->assertArrayHasKey('caused_by', $types);
        $this->assertArrayHasKey('part_of', $types);
        $this->assertArrayHasKey('similar_to', $types);
        $this->assertArrayHasKey('transferred_to', $types);
        $this->assertArrayHasKey('triggered_by', $types);
        $this->assertArrayHasKey('funded_by', $types);
        $this->assertArrayHasKey('payment_for', $types);
        $this->assertArrayHasKey('settles', $types);
        $this->assertArrayHasKey('receipt_for', $types);
    }

    #[Test]
    public function it_returns_type_configuration(): void
    {
        $type = RelationshipTypeRegistry::getType('linked_to');

        $this->assertNotNull($type);
        $this->assertArrayHasKey('display_name', $type);
        $this->assertArrayHasKey('icon', $type);
        $this->assertArrayHasKey('is_directional', $type);
        $this->assertArrayHasKey('description', $type);
        $this->assertArrayHasKey('supports_value', $type);
    }

    #[Test]
    public function it_returns_null_for_unknown_type(): void
    {
        $type = RelationshipTypeRegistry::getType('unknown_type');

        $this->assertNull($type);
    }

    #[Test]
    public function it_correctly_identifies_directional_types(): void
    {
        // Directional types
        $this->assertTrue(RelationshipTypeRegistry::isDirectional('linked_to'));
        $this->assertTrue(RelationshipTypeRegistry::isDirectional('caused_by'));
        $this->assertTrue(RelationshipTypeRegistry::isDirectional('part_of'));
        $this->assertTrue(RelationshipTypeRegistry::isDirectional('transferred_to'));
        $this->assertTrue(RelationshipTypeRegistry::isDirectional('triggered_by'));
        $this->assertTrue(RelationshipTypeRegistry::isDirectional('funded_by'));
        $this->assertTrue(RelationshipTypeRegistry::isDirectional('payment_for'));
        $this->assertTrue(RelationshipTypeRegistry::isDirectional('settles'));
        $this->assertTrue(RelationshipTypeRegistry::isDirectional('receipt_for'));

        // Non-directional types
        $this->assertFalse(RelationshipTypeRegistry::isDirectional('related_to'));
        $this->assertFalse(RelationshipTypeRegistry::isDirectional('similar_to'));
    }

    #[Test]
    public function it_defaults_to_directional_for_unknown_types(): void
    {
        $this->assertTrue(RelationshipTypeRegistry::isDirectional('unknown_type'));
    }

    #[Test]
    public function it_correctly_identifies_value_supporting_types(): void
    {
        // Types that support value
        $this->assertTrue(RelationshipTypeRegistry::supportsValue('transferred_to'));
        $this->assertTrue(RelationshipTypeRegistry::supportsValue('funded_by'));
        $this->assertTrue(RelationshipTypeRegistry::supportsValue('payment_for'));
        $this->assertTrue(RelationshipTypeRegistry::supportsValue('receipt_for'));

        // Types that don't support value
        $this->assertFalse(RelationshipTypeRegistry::supportsValue('linked_to'));
        $this->assertFalse(RelationshipTypeRegistry::supportsValue('related_to'));
        $this->assertFalse(RelationshipTypeRegistry::supportsValue('caused_by'));
        $this->assertFalse(RelationshipTypeRegistry::supportsValue('part_of'));
        $this->assertFalse(RelationshipTypeRegistry::supportsValue('similar_to'));
        $this->assertFalse(RelationshipTypeRegistry::supportsValue('triggered_by'));
        $this->assertFalse(RelationshipTypeRegistry::supportsValue('settles'));
    }

    #[Test]
    public function it_returns_false_for_unknown_type_value_support(): void
    {
        $this->assertFalse(RelationshipTypeRegistry::supportsValue('unknown_type'));
    }

    #[Test]
    public function it_returns_icons_for_types(): void
    {
        $this->assertEquals('fas.link', RelationshipTypeRegistry::getIcon('linked_to'));
        $this->assertEquals('fas.tag', RelationshipTypeRegistry::getIcon('related_to'));
        $this->assertEquals('fas.circle-arrow-right', RelationshipTypeRegistry::getIcon('caused_by'));
        $this->assertEquals('fas.grip', RelationshipTypeRegistry::getIcon('part_of'));
        $this->assertEquals('fas.right-left', RelationshipTypeRegistry::getIcon('similar_to'));
        $this->assertEquals('fas.money-bills', RelationshipTypeRegistry::getIcon('transferred_to'));
        $this->assertEquals('o-bolt', RelationshipTypeRegistry::getIcon('triggered_by'));
        $this->assertEquals('o-currency-pound', RelationshipTypeRegistry::getIcon('funded_by'));
        $this->assertEquals('o-credit-card', RelationshipTypeRegistry::getIcon('payment_for'));
        $this->assertEquals('o-check-circle', RelationshipTypeRegistry::getIcon('settles'));
        $this->assertEquals('fas.receipt', RelationshipTypeRegistry::getIcon('receipt_for'));
    }

    #[Test]
    public function it_returns_null_icon_for_unknown_type(): void
    {
        $this->assertNull(RelationshipTypeRegistry::getIcon('unknown_type'));
    }

    #[Test]
    public function it_returns_display_names(): void
    {
        $this->assertEquals('Linked To', RelationshipTypeRegistry::getDisplayName('linked_to'));
        $this->assertEquals('Related To', RelationshipTypeRegistry::getDisplayName('related_to'));
        $this->assertEquals('Caused By', RelationshipTypeRegistry::getDisplayName('caused_by'));
        $this->assertEquals('Part Of', RelationshipTypeRegistry::getDisplayName('part_of'));
        $this->assertEquals('Similar To', RelationshipTypeRegistry::getDisplayName('similar_to'));
        $this->assertEquals('Transferred To', RelationshipTypeRegistry::getDisplayName('transferred_to'));
        $this->assertEquals('Triggered By', RelationshipTypeRegistry::getDisplayName('triggered_by'));
        $this->assertEquals('Funded By', RelationshipTypeRegistry::getDisplayName('funded_by'));
        $this->assertEquals('Payment For', RelationshipTypeRegistry::getDisplayName('payment_for'));
        $this->assertEquals('Settles', RelationshipTypeRegistry::getDisplayName('settles'));
        $this->assertEquals('Receipt For', RelationshipTypeRegistry::getDisplayName('receipt_for'));
    }

    #[Test]
    public function it_returns_null_display_name_for_unknown_type(): void
    {
        $this->assertNull(RelationshipTypeRegistry::getDisplayName('unknown_type'));
    }

    #[Test]
    public function it_returns_descriptions(): void
    {
        $this->assertEquals('Source links to target', RelationshipTypeRegistry::getDescription('linked_to'));
        $this->assertEquals('General association between entities', RelationshipTypeRegistry::getDescription('related_to'));
        $this->assertEquals('Effect caused by source', RelationshipTypeRegistry::getDescription('caused_by'));
        $this->assertEquals('Entity is part of another entity', RelationshipTypeRegistry::getDescription('part_of'));
        $this->assertEquals('Entities are similar (ML-based or manual)', RelationshipTypeRegistry::getDescription('similar_to'));
        $this->assertEquals('Money or value transferred from source to target', RelationshipTypeRegistry::getDescription('transferred_to'));
    }

    #[Test]
    public function it_returns_null_description_for_unknown_type(): void
    {
        $this->assertNull(RelationshipTypeRegistry::getDescription('unknown_type'));
    }

    #[Test]
    public function it_returns_default_value_units(): void
    {
        $this->assertEquals('GBP', RelationshipTypeRegistry::getDefaultValueUnit('transferred_to'));
        $this->assertEquals('GBP', RelationshipTypeRegistry::getDefaultValueUnit('funded_by'));
        $this->assertEquals('GBP', RelationshipTypeRegistry::getDefaultValueUnit('payment_for'));
        $this->assertEquals('GBP', RelationshipTypeRegistry::getDefaultValueUnit('receipt_for'));

        // Types without default value unit
        $this->assertNull(RelationshipTypeRegistry::getDefaultValueUnit('linked_to'));
        $this->assertNull(RelationshipTypeRegistry::getDefaultValueUnit('related_to'));
    }

    #[Test]
    public function it_returns_null_default_value_unit_for_unknown_type(): void
    {
        $this->assertNull(RelationshipTypeRegistry::getDefaultValueUnit('unknown_type'));
    }

    #[Test]
    public function it_returns_all_type_keys(): void
    {
        $keys = RelationshipTypeRegistry::getTypeKeys();

        $this->assertIsArray($keys);
        $this->assertContains('linked_to', $keys);
        $this->assertContains('related_to', $keys);
        $this->assertContains('caused_by', $keys);
        $this->assertContains('part_of', $keys);
        $this->assertContains('similar_to', $keys);
        $this->assertContains('transferred_to', $keys);
        $this->assertContains('triggered_by', $keys);
        $this->assertContains('funded_by', $keys);
        $this->assertContains('payment_for', $keys);
        $this->assertContains('settles', $keys);
        $this->assertContains('receipt_for', $keys);
    }

    #[Test]
    public function it_checks_if_type_exists(): void
    {
        $this->assertTrue(RelationshipTypeRegistry::typeExists('linked_to'));
        $this->assertTrue(RelationshipTypeRegistry::typeExists('related_to'));
        $this->assertTrue(RelationshipTypeRegistry::typeExists('receipt_for'));

        $this->assertFalse(RelationshipTypeRegistry::typeExists('unknown_type'));
        $this->assertFalse(RelationshipTypeRegistry::typeExists(''));
    }

    #[Test]
    public function type_configurations_have_required_fields(): void
    {
        $types = RelationshipTypeRegistry::getTypes();

        foreach ($types as $key => $config) {
            $this->assertArrayHasKey('display_name', $config, "Type '$key' missing display_name");
            $this->assertArrayHasKey('icon', $config, "Type '$key' missing icon");
            $this->assertArrayHasKey('is_directional', $config, "Type '$key' missing is_directional");
            $this->assertArrayHasKey('description', $config, "Type '$key' missing description");
            $this->assertArrayHasKey('supports_value', $config, "Type '$key' missing supports_value");

            $this->assertIsString($config['display_name'], "Type '$key' display_name should be string");
            $this->assertIsString($config['icon'], "Type '$key' icon should be string");
            $this->assertIsBool($config['is_directional'], "Type '$key' is_directional should be bool");
            $this->assertIsString($config['description'], "Type '$key' description should be string");
            $this->assertIsBool($config['supports_value'], "Type '$key' supports_value should be bool");
        }
    }

    #[Test]
    public function value_supporting_types_have_default_value_unit(): void
    {
        $types = RelationshipTypeRegistry::getTypes();

        foreach ($types as $key => $config) {
            if ($config['supports_value']) {
                $this->assertArrayHasKey('default_value_unit', $config, "Value-supporting type '$key' should have default_value_unit");
                $this->assertIsString($config['default_value_unit'], "Type '$key' default_value_unit should be string");
            }
        }
    }
}
