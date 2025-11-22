<?php

namespace Tests\Feature\Integrations\GitHub;

use App\Integrations\GitHub\GitHubPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GitHubPluginTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function plugin_has_correct_metadata(): void
    {
        $this->assertEquals('github', GitHubPlugin::getIdentifier());
        $this->assertEquals('GitHub', GitHubPlugin::getDisplayName());
        $this->assertEquals('oauth', GitHubPlugin::getServiceType());
        $this->assertEquals('online', GitHubPlugin::getDomain());

        $description = GitHubPlugin::getDescription();
        $this->assertStringContainsString('GitHub', $description);
    }

    #[Test]
    public function plugin_has_configuration_schema(): void
    {
        $schema = GitHubPlugin::getConfigurationSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('repositories', $schema);
        $this->assertArrayHasKey('events', $schema);
        $this->assertArrayHasKey('update_frequency_minutes', $schema);

        // Check update frequency has proper config
        $this->assertEquals('integer', $schema['update_frequency_minutes']['type']);
        $this->assertEquals(15, $schema['update_frequency_minutes']['default']);
    }

    #[Test]
    public function plugin_has_action_types(): void
    {
        $actionTypes = GitHubPlugin::getActionTypes();

        $this->assertArrayHasKey('push', $actionTypes);
        $this->assertEquals('Push', $actionTypes['push']['display_name']);
        $this->assertEquals('fas.code-commit', $actionTypes['push']['icon']);
    }

    #[Test]
    public function plugin_has_object_types(): void
    {
        $objectTypes = GitHubPlugin::getObjectTypes();

        $this->assertArrayHasKey('github_user', $objectTypes);
        $this->assertArrayHasKey('github_repo', $objectTypes);
        $this->assertArrayHasKey('github_pr', $objectTypes);
        $this->assertArrayHasKey('github_issue', $objectTypes);

        $this->assertEquals('GitHub User', $objectTypes['github_user']['display_name']);
        $this->assertEquals('GitHub Repository', $objectTypes['github_repo']['display_name']);
        $this->assertEquals('GitHub Pull Request', $objectTypes['github_pr']['display_name']);
        $this->assertEquals('GitHub Issue', $objectTypes['github_issue']['display_name']);
    }

    #[Test]
    public function plugin_has_block_types(): void
    {
        $blockTypes = GitHubPlugin::getBlockTypes();

        $this->assertIsArray($blockTypes);
        // GitHub plugin may have empty block types
    }

    #[Test]
    public function plugin_supports_migration(): void
    {
        $this->assertTrue(GitHubPlugin::supportsMigration());
    }

    #[Test]
    public function plugin_has_instance_types(): void
    {
        $instanceTypes = GitHubPlugin::getInstanceTypes();

        $this->assertArrayHasKey('activity', $instanceTypes);
        $this->assertEquals('Activity', $instanceTypes['activity']['label']);
    }

    #[Test]
    public function plugin_has_icon(): void
    {
        $icon = GitHubPlugin::getIcon();

        $this->assertEquals('fab.github', $icon);
    }

    #[Test]
    public function plugin_has_accent_color(): void
    {
        $accentColor = GitHubPlugin::getAccentColor();

        $this->assertEquals('neutral', $accentColor);
    }

    #[Test]
    public function plugin_events_configuration_has_options(): void
    {
        $schema = GitHubPlugin::getConfigurationSchema();

        $this->assertArrayHasKey('options', $schema['events']);
        $this->assertArrayHasKey('push', $schema['events']['options']);
        $this->assertArrayHasKey('pull_request', $schema['events']['options']);
        $this->assertArrayHasKey('issue', $schema['events']['options']);
        $this->assertArrayHasKey('commit_comment', $schema['events']['options']);
    }

    #[Test]
    public function plugin_normalizes_repositories_from_string(): void
    {
        $plugin = new GitHubPlugin;

        $repositories = $plugin->normalizeRepositories('owner/repo1,owner/repo2');

        $this->assertCount(2, $repositories);
        $this->assertContains('owner/repo1', $repositories);
        $this->assertContains('owner/repo2', $repositories);
    }

    #[Test]
    public function plugin_normalizes_repositories_from_json(): void
    {
        $plugin = new GitHubPlugin;

        $repositories = $plugin->normalizeRepositories('["owner/repo1", "owner/repo2"]');

        $this->assertCount(2, $repositories);
        $this->assertContains('owner/repo1', $repositories);
        $this->assertContains('owner/repo2', $repositories);
    }

    #[Test]
    public function plugin_normalizes_repositories_from_array(): void
    {
        $plugin = new GitHubPlugin;

        $repositories = $plugin->normalizeRepositories(['owner/repo1', 'owner/repo2']);

        $this->assertCount(2, $repositories);
        $this->assertContains('owner/repo1', $repositories);
        $this->assertContains('owner/repo2', $repositories);
    }

    #[Test]
    public function plugin_normalizes_events_from_string(): void
    {
        $plugin = new GitHubPlugin;

        $events = $plugin->normalizeEvents('push,pull_request');

        $this->assertCount(2, $events);
        $this->assertContains('push', $events);
        $this->assertContains('pull_request', $events);
    }

    #[Test]
    public function plugin_normalizes_events_from_array(): void
    {
        $plugin = new GitHubPlugin;

        $events = $plugin->normalizeEvents(['push', 'pull_request', 'issue']);

        $this->assertCount(3, $events);
        $this->assertContains('push', $events);
        $this->assertContains('pull_request', $events);
        $this->assertContains('issue', $events);
    }

    #[Test]
    public function plugin_returns_default_events_when_empty(): void
    {
        $plugin = new GitHubPlugin;

        $events = $plugin->normalizeEvents([]);

        $this->assertContains('push', $events);
        $this->assertContains('pull_request', $events);
    }
}
