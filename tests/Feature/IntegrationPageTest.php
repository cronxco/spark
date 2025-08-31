<?php

namespace Tests\Feature;

use App\Integrations\GitHub\GitHubPlugin;
use App\Integrations\PluginRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntegrationPageTest extends TestCase
{
    #[Test]
    public function integrations_page_requires_authentication()
    {
        $response = $this->get('/integrations');

        $response->assertRedirect('/login');
    }

    #[Test]
    public function plugin_registry_has_github_plugin()
    {
        $plugins = PluginRegistry::getAllPlugins();

        $this->assertTrue($plugins->has('github'));
        $this->assertEquals('GitHub', $plugins->get('github')::getDisplayName());
    }

    #[Test]
    public function plugin_registry_has_slack_plugin()
    {
        $plugins = PluginRegistry::getAllPlugins();

        $this->assertTrue($plugins->has('slack'));
        $this->assertEquals('Slack', $plugins->get('slack')::getDisplayName());
    }

    #[Test]
    public function initialize_route_exists()
    {
        // Test that the route exists by making a request
        $response = $this->post('/integrations/github/initialize');

        // Should get a redirect to login since we're not authenticated
        $response->assertStatus(302);
    }

    #[Test]
    public function plugin_registry_supports_multiple_instances()
    {
        $plugins = PluginRegistry::getAllPlugins();

        // Test that we can get plugin instances multiple times
        $githubPlugin1 = PluginRegistry::getPluginInstance('github');
        $githubPlugin2 = PluginRegistry::getPluginInstance('github');

        $this->assertNotNull($githubPlugin1);
        $this->assertNotNull($githubPlugin2);
        $this->assertInstanceOf(GitHubPlugin::class, $githubPlugin1);
        $this->assertInstanceOf(GitHubPlugin::class, $githubPlugin2);
    }
}
