<?php

namespace Tests\Feature;

use App\Integrations\PluginRegistry;
use App\Integrations\GitHub\GitHubPlugin;
use Tests\TestCase;

class IntegrationPluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Register the GitHub plugin for testing
        PluginRegistry::register(GitHubPlugin::class);
    }

    public function test_plugin_registry_can_register_and_retrieve_plugins()
    {
        $plugins = PluginRegistry::getAllPlugins();
        
        $this->assertTrue($plugins->has('github'));
        $this->assertEquals(GitHubPlugin::class, $plugins->get('github'));
    }

    public function test_plugin_registry_can_filter_by_service_type()
    {
        $oauthPlugins = PluginRegistry::getOAuthPlugins();
        $webhookPlugins = PluginRegistry::getWebhookPlugins();
        
        $this->assertTrue($oauthPlugins->has('github'));
        $this->assertFalse($webhookPlugins->has('github'));
    }

    public function test_plugin_registry_can_get_plugin_instance()
    {
        $plugin = PluginRegistry::getPluginInstance('github');
        
        $this->assertInstanceOf(GitHubPlugin::class, $plugin);
    }

    public function test_plugin_registry_returns_null_for_invalid_plugin()
    {
        $plugin = PluginRegistry::getPluginInstance('invalid');
        
        $this->assertNull($plugin);
    }

    public function test_github_plugin_has_correct_metadata()
    {
        $this->assertEquals('github', GitHubPlugin::getIdentifier());
        $this->assertEquals('GitHub', GitHubPlugin::getDisplayName());
        $this->assertEquals('Connect your GitHub account to track repository activity', GitHubPlugin::getDescription());
        $this->assertEquals('oauth', GitHubPlugin::getServiceType());
    }

    public function test_github_plugin_has_configuration_schema()
    {
        $schema = GitHubPlugin::getConfigurationSchema();
        
        $this->assertArrayHasKey('repositories', $schema);
        $this->assertArrayHasKey('events', $schema);
        $this->assertEquals('array', $schema['repositories']['type']);
        $this->assertEquals('array', $schema['events']['type']);
    }

    public function test_github_plugin_has_required_scopes()
    {
        $plugin = new GitHubPlugin();
        
        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($plugin);
        $method = $reflection->getMethod('getRequiredScopes');
        $method->setAccessible(true);
        
        $scopes = $method->invoke($plugin);
        
        $this->assertEquals('repo read:user', $scopes);
    }
} 