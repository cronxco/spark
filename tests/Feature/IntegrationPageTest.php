<?php

namespace Tests\Feature;

use Tests\TestCase;

class IntegrationPageTest extends TestCase
{
    public function test_integrations_page_requires_authentication()
    {
        $response = $this->get('/integrations');
        
        $response->assertRedirect('/login');
    }

    public function test_plugin_registry_has_github_plugin()
    {
        $plugins = \App\Integrations\PluginRegistry::getAllPlugins();
        
        $this->assertTrue($plugins->has('github'));
        $this->assertEquals('GitHub', $plugins->get('github')::getDisplayName());
    }

    public function test_plugin_registry_has_slack_plugin()
    {
        $plugins = \App\Integrations\PluginRegistry::getAllPlugins();
        
        $this->assertTrue($plugins->has('slack'));
        $this->assertEquals('Slack', $plugins->get('slack')::getDisplayName());
    }

    public function test_initialize_route_exists()
    {
        // Test that the route exists by making a request
        $response = $this->post('/integrations/github/initialize');
        
        // Should get a redirect to login since we're not authenticated
        $response->assertStatus(302);
    }

    public function test_plugin_registry_supports_multiple_instances()
    {
        $plugins = \App\Integrations\PluginRegistry::getAllPlugins();
        
        // Test that we can get plugin instances multiple times
        $githubPlugin1 = \App\Integrations\PluginRegistry::getPluginInstance('github');
        $githubPlugin2 = \App\Integrations\PluginRegistry::getPluginInstance('github');
        
        $this->assertNotNull($githubPlugin1);
        $this->assertNotNull($githubPlugin2);
        $this->assertInstanceOf(\App\Integrations\GitHub\GitHubPlugin::class, $githubPlugin1);
        $this->assertInstanceOf(\App\Integrations\GitHub\GitHubPlugin::class, $githubPlugin2);
    }

} 