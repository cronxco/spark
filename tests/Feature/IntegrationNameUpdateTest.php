<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntegrationNameUpdateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_can_update_integration_name_from_index_page(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'name' => 'Original Name',
        ]);

        $component = Livewire::actingAs($user)
            ->test('integrations.index');

        // Debug: Check if the component loaded correctly
        $this->assertNotNull($component);

        $component->call('updateIntegrationNameFromIndex', $integration->id, 'New Custom Name');

        $this->assertDatabaseHas('integrations', [
            'id' => $integration->id,
            'name' => 'New Custom Name',
        ]);
    }

    #[Test]
    public function user_can_update_integration_name_from_configure_page(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'name' => 'Original Name',
        ]);

        $this->actingAs($user);

        Livewire::test('integrations.configure', ['integration' => $integration])
            ->set('name', 'New Custom Name')
            ->call('updateName');

        $this->assertDatabaseHas('integrations', [
            'id' => $integration->id,
            'name' => 'New Custom Name',
        ]);
    }

    #[Test]
    public function user_cannot_update_other_users_integration_name_from_index_page(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $otherUser->id,
            'service' => 'github',
            'name' => 'Original Name',
        ]);

        $this->actingAs($user);

        Livewire::test('integrations.index')
            ->call('updateIntegrationNameFromIndex', $integration->id, 'New Custom Name');

        $this->assertDatabaseHas('integrations', [
            'id' => $integration->id,
            'name' => 'Original Name', // Name should not have changed
        ]);
    }

    #[Test]
    public function user_cannot_update_other_users_integration_name_from_configure_page(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $otherUser->id,
            'service' => 'github',
            'name' => 'Original Name',
        ]);

        $this->actingAs($user);

        // This should return a 403 status code
        $response = $this->get("/integrations/{$integration->id}/configure");
        $response->assertStatus(403);
    }

    #[Test]
    public function user_cannot_set_empty_integration_name_from_index_page(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'name' => 'Original Name',
        ]);

        $this->actingAs($user);

        Livewire::test('integrations.index')
            ->call('updateIntegrationNameFromIndex', $integration->id, '');

        $this->assertDatabaseHas('integrations', [
            'id' => $integration->id,
            'name' => 'Original Name', // Name should not have changed
        ]);
    }

    #[Test]
    public function user_cannot_set_empty_integration_name_from_configure_page(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'name' => 'Original Name',
        ]);

        $this->actingAs($user);

        Livewire::test('integrations.configure', ['integration' => $integration])
            ->set('name', '')
            ->call('updateName');

        $this->assertDatabaseHas('integrations', [
            'id' => $integration->id,
            'name' => 'Original Name', // Name should not have changed
        ]);
    }

    #[Test]
    public function configure_page_loads_with_correct_initial_name(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'name' => 'Custom GitHub Integration',
        ]);

        $this->actingAs($user);

        Livewire::test('integrations.configure', ['integration' => $integration])
            ->assertSet('name', 'Custom GitHub Integration');
    }

    #[Test]
    public function configure_page_uses_service_name_when_no_custom_name(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'name' => null,
        ]);

        $this->actingAs($user);

        Livewire::test('integrations.configure', ['integration' => $integration])
            ->assertSet('name', 'github');
    }

    #[Test]
    public function name_update_trims_whitespace(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'name' => 'Original Name',
        ]);

        Livewire::actingAs($user)
            ->test('integrations.index')
            ->call('updateIntegrationNameFromIndex', $integration->id, '  Trimmed Name  ');

        $this->assertDatabaseHas('integrations', [
            'id' => $integration->id,
            'name' => 'Trimmed Name',
        ]);
    }

    #[Test]
    public function configure_page_name_update_trims_whitespace(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'name' => 'Original Name',
        ]);

        $this->actingAs($user);

        Livewire::test('integrations.configure', ['integration' => $integration])
            ->set('name', '  Trimmed Name  ')
            ->call('updateName');

        $this->assertDatabaseHas('integrations', [
            'id' => $integration->id,
            'name' => 'Trimmed Name',
        ]);
    }
}
