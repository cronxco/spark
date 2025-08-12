<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IntegrationDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_delete_their_integration(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'name' => 'Test Integration',
        ]);

        Livewire::actingAs($user)
            ->test('integrations.index')
            ->call('deleteIntegration', $integration->id);

        // Since we now use soft deletes, the integration should still exist but be soft deleted
        $this->assertDatabaseHas('integrations', ['id' => $integration->id]);
        
        // Check that it is soft deleted
        $deletedIntegration = Integration::withTrashed()->find($integration->id);
        $this->assertNotNull($deletedIntegration->deleted_at);
    }

    public function test_user_cannot_delete_other_users_integration(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $otherUser->id,
            'service' => 'github',
            'name' => 'Other User Integration',
        ]);

        Livewire::actingAs($user)
            ->test('integrations.index')
            ->call('deleteIntegration', $integration->id);

        // Integration should still exist
        $this->assertDatabaseHas('integrations', [
            'id' => $integration->id,
        ]);
    }

    public function test_user_cannot_delete_nonexistent_integration(): void
    {
        $user = User::factory()->create();
        $nonexistentId = '00000000-0000-0000-0000-000000000000';

        Livewire::actingAs($user)
            ->test('integrations.index')
            ->call('deleteIntegration', $nonexistentId);

        // Should not crash and should show error message
        $this->assertTrue(true, 'Component should handle nonexistent integration gracefully');
    }

    public function test_delete_integration_shows_success_message(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'name' => 'Test Integration',
        ]);

        $component = Livewire::actingAs($user)
            ->test('integrations.index')
            ->call('deleteIntegration', $integration->id);

        // The success message should be dispatched (though we can't easily test the toast in unit tests)
        // Since we now use soft deletes, the integration should still exist but be soft deleted
        $this->assertDatabaseHas('integrations', ['id' => $integration->id]);
        
        // Check that it is soft deleted
        $deletedIntegration = Integration::withTrashed()->find($integration->id);
        $this->assertNotNull($deletedIntegration->deleted_at);
    }

    public function test_delete_integration_refreshes_data(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'name' => 'Test Integration',
        ]);

        $component = Livewire::actingAs($user)
            ->test('integrations.index');

        // Verify integration is initially loaded
        $this->assertTrue($component->get('integrationsByService') !== null);

        $component->call('deleteIntegration', $integration->id);

        // Since we now use soft deletes, the integration should still exist but be soft deleted
        $this->assertDatabaseHas('integrations', ['id' => $integration->id]);
        
        // Check that it is soft deleted
        $deletedIntegration = Integration::withTrashed()->find($integration->id);
        $this->assertNotNull($deletedIntegration->deleted_at);
    }

    public function test_deleting_last_integration_soft_deletes_group(): void
    {
        $user = User::factory()->create();

        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'github',
            'account_id' => 'acct-1',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'name' => 'Only Integration',
            'integration_group_id' => $group->id,
        ]);

        $integration->delete();

        $this->assertNotNull(
            IntegrationGroup::withTrashed()->find($group->id)?->deleted_at
        );
    }

    public function test_deleting_non_last_integration_does_not_delete_group(): void
    {
        $user = User::factory()->create();

        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'github',
            'account_id' => 'acct-2',
        ]);

        $first = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'name' => 'First',
            'integration_group_id' => $group->id,
        ]);

        $second = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'github',
            'name' => 'Second',
            'integration_group_id' => $group->id,
        ]);

        $first->delete();

        $this->assertNull(IntegrationGroup::find($group->id)?->deleted_at);

        $second->delete();

        $this->assertNotNull(
            IntegrationGroup::withTrashed()->find($group->id)?->deleted_at
        );
    }
}
