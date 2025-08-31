<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PluginAndIntegrationViewsTest extends TestCase
{
    #[Test]
    public function plugin_show_page_loads_for_valid_service(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/plugins/github');

        $response->assertStatus(200);
        $response->assertSee('GitHub');
        $response->assertSee('online');
    }

    #[Test]
    public function plugin_show_page_returns_404_for_invalid_service(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/plugins/invalid-service');

        $response->assertStatus(404);
    }

    #[Test]
    public function integration_details_page_loads_for_owned_integration(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
        ]);

        $integration = Integration::factory()->create([
            'integration_group_id' => $group->id,
            'user_id' => $user->id,
            'service' => 'github',
            'name' => 'Test GitHub Integration',
        ]);

        $response = $this->get("/integrations/{$integration->id}/details");

        $response->assertStatus(200);
        $response->assertSee('Test GitHub Integration');
        $response->assertSee('github');
    }

    #[Test]
    public function integration_details_page_returns_403_for_other_users_integration(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->actingAs($user);

        $group = IntegrationGroup::factory()->create([
            'user_id' => $otherUser->id,
            'service' => 'github',
        ]);

        $integration = Integration::factory()->create([
            'integration_group_id' => $group->id,
            'user_id' => $otherUser->id,
            'service' => 'github',
        ]);

        $response = $this->get("/integrations/{$integration->id}/details");

        $response->assertStatus(403);
    }

    #[Test]
    public function plugin_show_page_displays_action_types(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/plugins/github');

        $response->assertStatus(200);
        $response->assertSee('Action Types');
        $response->assertSee('Push');
        $response->assertSee('Pull Request');
    }

    #[Test]
    public function plugin_show_page_displays_object_types(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/plugins/github');

        $response->assertStatus(200);
        $response->assertSee('Object Types');
        $response->assertSee('Repository');
        $response->assertSee('User');
    }

    #[Test]
    public function plugin_show_page_displays_block_types(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/plugins/github');

        $response->assertStatus(200);
        $response->assertSee('Block Types');
        // GitHub plugin has no block types, so we just verify the section exists
    }

    #[Test]
    public function integration_details_page_displays_action_types(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
        ]);

        $integration = Integration::factory()->create([
            'integration_group_id' => $group->id,
            'user_id' => $user->id,
            'service' => 'github',
        ]);

        $response = $this->get("/integrations/{$integration->id}/details");

        $response->assertStatus(200);
        $response->assertSee('Action Types');
        $response->assertSee('Push');
        $response->assertSee('Pull Request');
    }

    #[Test]
    public function integration_details_page_displays_object_types(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
        ]);

        $integration = Integration::factory()->create([
            'integration_group_id' => $group->id,
            'user_id' => $user->id,
            'service' => 'github',
        ]);

        $response = $this->get("/integrations/{$integration->id}/details");

        $response->assertStatus(200);
        $response->assertSee('Object Types');
        $response->assertSee('Repository');
        $response->assertSee('User');
    }

    #[Test]
    public function integration_details_page_displays_block_types(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
        ]);

        $integration = Integration::factory()->create([
            'integration_group_id' => $group->id,
            'user_id' => $user->id,
            'service' => 'github',
        ]);

        $response = $this->get("/integrations/{$integration->id}/details");

        $response->assertStatus(200);
        $response->assertSee('Block Types');
        // GitHub plugin has no block types, so we just verify the section exists
    }
}
