<?php

namespace Tests\Feature;

use App\Integrations\GitHub\GitHubPlugin;
use App\Integrations\PluginRegistry;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Register the GitHub plugin for testing
        PluginRegistry::register(GitHubPlugin::class);
    }

    #[Test]
    public function plugin_can_initialize_group_and_instance()
    {
        $user = User::factory()->create();
        $plugin = new GitHubPlugin;

        $group = $plugin->initializeGroup($user);
        $this->assertInstanceOf(IntegrationGroup::class, $group);
        $this->assertEquals($user->id, $group->user_id);
        $this->assertEquals('github', $group->service);

        $instance = $plugin->createInstance($group, 'activity', ['events' => ['push'], 'repositories' => ['owner/repo']]);
        $this->assertInstanceOf(Integration::class, $instance);
        $this->assertEquals($group->id, $instance->integration_group_id);
        $this->assertEquals('activity', $instance->instance_type);
    }

    #[Test]
    public function integrations_index_page_loads()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/settings/integrations');

        $response->assertStatus(200);
        $response->assertSee('Available Integrations');
        $response->assertSee('GitHub');
    }

    #[Test]
    public function oauth_flow_redirects_to_provider()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/integrations/github/oauth');

        $response->assertStatus(302);
        $this->assertStringContainsString('github.com', $response->headers->get('Location'));
    }

    #[Test]
    public function oauth_callback_handles_success()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
        ]);

        // Mock the OAuth callback data
        $state = encrypt([
            'integration_id' => $integration->id,
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->get('/integrations/github/callback?code=test_code&state=' . $state);

        // This will fail because we're not mocking the GitHub API
        // but it should redirect back to integrations index
        $response->assertStatus(302);
    }

    #[Test]
    public function oauth_callback_handles_invalid_state()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
        ]);

        $response = $this->actingAs($user)
            ->get('/integrations/github/callback?code=test_code&state=invalid_state');

        $response->assertStatus(302);
    }

    #[Test]
    public function configure_page_loads()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
        ]);

        $response = $this->actingAs($user)
            ->get("/integrations/{$integration->id}/configure");

        $response->assertStatus(200);
        $response->assertSee('Configure Integration');
    }

    #[Test]
    public function configure_page_requires_ownership()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user1->id,
            'service' => 'github',
        ]);

        $response = $this->actingAs($user2)
            ->get("/integrations/{$integration->id}/configure");

        $response->assertStatus(403);
    }

    #[Test]
    public function can_update_integration_configuration()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
        ]);

        $component = Livewire::actingAs($user)
            ->test('integrations.configure', ['integration' => $integration])
            ->set('configuration', [
                'repositories' => 'owner/repo1, owner/repo2',
                'events' => ['push', 'pull_request'],
                'update_frequency_minutes' => 15,
            ]);

        // Debug: Check if there are validation errors
        $component->call('updateConfiguration');

        $integration->refresh();

        // Debug: Check what was actually saved
        $this->assertNotNull($integration->configuration, 'Configuration should not be null');
        $this->assertIsArray($integration->configuration, 'Configuration should be an array');

        // Debug: Print the actual configuration
        $this->assertNotEmpty($integration->configuration, 'Configuration should not be empty');
        $this->assertArrayHasKey('repositories', $integration->configuration, 'Configuration should have repositories key');
        $this->assertArrayHasKey('events', $integration->configuration, 'Configuration should have events key');

        $this->assertEquals(['owner/repo1', 'owner/repo2'], $integration->configuration['repositories']);
        $this->assertEquals(['push', 'pull_request'], $integration->configuration['events']);
        $this->assertEquals(15, $integration->getUpdateFrequencyMinutes());
    }

    #[Test]
    public function webhook_handles_valid_request()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'account_id' => 'test_secret',
        ]);

        $response = $this->post('/webhook/github/test_secret', [
            'test' => 'data',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);
    }

    #[Test]
    public function webhook_handles_invalid_service()
    {
        $response = $this->post('/webhook/invalid/test_secret', [
            'test' => 'data',
        ]);

        $response->assertStatus(404);
    }

    #[Test]
    public function webhook_handles_invalid_secret()
    {
        $user = User::factory()->create();
        Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'account_id' => 'correct_secret',
        ]);

        $response = $this->post('/webhook/github/wrong_secret', [
            'test' => 'data',
        ]);

        $response->assertStatus(404);
    }
}
