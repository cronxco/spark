<?php

namespace Tests\Unit\Integrations;

use App\Integrations\PluginRegistry;
use App\Integrations\Vivino\VivinoPlugin;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VivinoPluginTest extends TestCase
{
    #[Test]
    public function is_classified_as_an_api_key_plugin_so_it_gets_polled(): void
    {
        // ManualPlugin defaults getServiceType() to 'manual', but
        // CheckIntegrationUpdates only schedules services PluginRegistry
        // classifies as 'oauth' or 'apikey' - VivinoPlugin must override
        // this or it would silently never be polled.
        $this->assertSame('apikey', VivinoPlugin::getServiceType());
    }

    #[Test]
    public function is_registered_and_discoverable_as_an_api_key_plugin(): void
    {
        PluginRegistry::register(VivinoPlugin::class);

        $this->assertArrayHasKey('vivino', PluginRegistry::getApiKeyPlugins()->toArray());
    }

    #[Test]
    public function requires_a_profile_url_in_group_configuration(): void
    {
        $schema = VivinoPlugin::getGroupConfigurationSchema();

        $this->assertArrayHasKey('vivino_profile_url', $schema);
        $this->assertTrue($schema['vivino_profile_url']['required']);
    }
}
