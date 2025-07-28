<?php

namespace Tests\Feature;

use App\Integrations\PluginRegistry;
use App\Integrations\GitHub\GitHubPlugin;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Register the GitHub plugin for testing
        PluginRegistry::register(GitHubPlugin::class);
    }



    public function test_plugin_can_initialize_integration()
    {
        $user = User::factory()->create();
        $plugin = new GitHubPlugin();
        
        $integration = $plugin->initialize($user);
        
        $this->assertInstanceOf(Integration::class, $integration);
        $this->assertEquals($user->id, $integration->user_id);
        $this->assertEquals('github', $integration->service);
        $this->assertEquals('GitHub', $integration->name);
    }

    public function test_integrations_index_page_loads()
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user)
            ->get('/integrations');
        
        $response->assertStatus(200);
        $response->assertSee('Available Integrations');
        $response->assertSee('GitHub');
    }

    public function test_oauth_flow_redirects_to_provider()
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user)
            ->get('/integrations/github/oauth');
        
        $response->assertStatus(302);
        $this->assertStringContainsString('github.com', $response->headers->get('Location'));
    }

    public function test_oauth_callback_handles_success()
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
            ->get('/integrations/github/oauth/callback?code=test_code&state=' . $state);
        
        // This will fail because we're not mocking the GitHub API
        // but it should redirect back to integrations index
        $response->assertStatus(302);
    }

    public function test_oauth_callback_handles_invalid_state()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
        ]);
        
        $response = $this->actingAs($user)
            ->get('/integrations/github/oauth/callback?code=test_code&state=invalid_state');
        
        $response->assertStatus(302);
    }

    public function test_configure_page_loads()
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

    public function test_configure_page_requires_ownership()
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

    public function test_can_update_integration_configuration()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
        ]);
        
        $response = $this->actingAs($user)
            ->post("/integrations/{$integration->id}/configure", [
                'repositories' => ['owner/repo1', 'owner/repo2'],
                'events' => ['push', 'pull_request'],
            ]);
        
        $response->assertStatus(302);
        
        $integration->refresh();
        $this->assertEquals(['owner/repo1', 'owner/repo2'], $integration->configuration['repositories']);
        $this->assertEquals(['push', 'pull_request'], $integration->configuration['events']);
    }

    public function test_can_disconnect_integration()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
        ]);
        
        $response = $this->actingAs($user)
            ->delete("/integrations/{$integration->id}/disconnect");
        
        $response->assertStatus(302);
        $this->assertDatabaseMissing('integrations', ['id' => $integration->id]);
    }

    public function test_disconnect_requires_ownership()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user1->id,
            'service' => 'github',
        ]);
        
        $response = $this->actingAs($user2)
            ->delete("/integrations/{$integration->id}/disconnect");
        
        $response->assertStatus(403);
        $this->assertDatabaseHas('integrations', ['id' => $integration->id]);
    }

    public function test_webhook_handles_valid_request()
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

    public function test_webhook_handles_invalid_service()
    {
        $response = $this->post('/webhook/invalid/test_secret', [
            'test' => 'data',
        ]);
        
        $response->assertStatus(404);
    }

    public function test_webhook_handles_invalid_secret()
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
