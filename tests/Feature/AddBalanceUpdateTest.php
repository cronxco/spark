<?php

namespace Tests\Feature;

use App\Integrations\Financial\FinancialPlugin;
use App\Livewire\AddBalanceUpdate;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AddBalanceUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    #[Test]
    public function can_mount_component_without_account_parameter(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(AddBalanceUpdate::class)
            ->assertSet('accountId', '')
            ->assertSet('isAccountPreselected', false)
            ->assertSet('date', now()->format('Y-m-d'))
            ->assertSee('Add Balance Update');
    }

    #[Test]
    public function can_mount_component_with_account_parameter(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Create integration and account
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'manual_account',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'manual_account',
            'name' => 'Test Account',
        ]);

        $plugin = new FinancialPlugin;
        $account = $plugin->upsertAccountObject($integration, [
            'name' => 'Test Account',
            'account_type' => 'current_account',
            'provider' => 'Test Bank',
        ]);

        Livewire::test(AddBalanceUpdate::class, ['account' => $account->id])
            ->assertSet('accountId', $account->id)
            ->assertSet('isAccountPreselected', true)
            ->assertSee('Add Balance Update for Test Account');
    }

    #[Test]
    public function can_add_balance_update_for_manual_account(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Create integration and account
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'manual_account',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'manual_account',
            'name' => 'Test Account',
        ]);

        $plugin = new FinancialPlugin;
        $account = $plugin->upsertAccountObject($integration, [
            'name' => 'Test Account',
            'account_type' => 'current_account',
            'provider' => 'Test Bank',
        ]);

        Livewire::test(AddBalanceUpdate::class)
            ->set('accountId', $account->id)
            ->set('balance', 1500.75)
            ->set('date', '2025-01-27')
            ->set('notes', 'Monthly salary received')
            ->call('save')
            ->assertDispatched('balance-updated')
            ->assertSet('balance', null)
            ->assertSet('notes', null);

        // Verify the event was created
        $this->assertDatabaseHas('events', [
            'integration_id' => $integration->id,
            'actor_id' => $account->id,
            'service' => 'manual_account',
            'domain' => 'money',
            'action' => 'had_balance',
            'value' => 150075, // 1500.75 * 100
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
        ]);

        // Verify the event metadata
        $event = Event::where('actor_id', $account->id)->first();
        $this->assertEquals(1500.75, $event->event_metadata['balance']);
        $this->assertEquals('Monthly salary received', $event->event_metadata['notes']);
        $this->assertEquals('Test Account', $event->event_metadata['account_name']);
    }

    #[Test]
    public function cannot_add_balance_update_for_non_manual_account(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Create a Monzo account (not manual)
        $monzoAccount = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'account',
            'type' => 'monzo_account',
            'title' => 'Monzo Account',
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(AddBalanceUpdate::class)
            ->set('accountId', $monzoAccount->id)
            ->set('balance', 1500.75)
            ->set('date', '2025-01-27')
            ->call('save');
    }

    #[Test]
    public function cannot_add_balance_update_for_other_users_account(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->actingAs($user1);

        // Create integration and account for user2
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user2->id,
            'service' => 'manual_account',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $user2->id,
            'integration_group_id' => $group->id,
            'service' => 'manual_account',
            'name' => 'Test Account',
        ]);

        $plugin = new FinancialPlugin;
        $account = $plugin->upsertAccountObject($integration, [
            'name' => 'Test Account',
            'account_type' => 'current_account',
            'provider' => 'Test Bank',
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(AddBalanceUpdate::class)
            ->set('accountId', $account->id)
            ->set('balance', 1500.75)
            ->set('date', '2025-01-27')
            ->call('save');
    }

    #[Test]
    public function validation_requires_all_required_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(AddBalanceUpdate::class)
            ->set('accountId', '')
            ->set('balance', null)
            ->set('date', '')
            ->call('save')
            ->assertHasErrors(['accountId', 'balance', 'date']);
    }

    #[Test]
    public function can_change_preselected_account(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Create integration and account
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'manual_account',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'manual_account',
            'name' => 'Test Account',
        ]);

        $plugin = new FinancialPlugin;
        $account = $plugin->upsertAccountObject($integration, [
            'name' => 'Test Account',
            'account_type' => 'current_account',
            'provider' => 'Test Bank',
        ]);

        Livewire::test(AddBalanceUpdate::class, ['account' => $account->id])
            ->assertSet('isAccountPreselected', true)
            ->call('$set', 'isAccountPreselected', false)
            ->assertSet('isAccountPreselected', false);
    }

    #[Test]
    public function only_manual_accounts_are_available_in_dropdown(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Create integration and manual account
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'manual_account',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'manual_account',
            'name' => 'Test Account',
        ]);

        $plugin = new FinancialPlugin;
        $manualAccount = $plugin->upsertAccountObject($integration, [
            'name' => 'Manual Account',
            'account_type' => 'current_account',
            'provider' => 'Test Bank',
        ]);

        // Create a Monzo account (not available for balance updates)
        $monzoAccount = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'account',
            'type' => 'monzo_account',
            'title' => 'Monzo Account',
        ]);

        Livewire::test(AddBalanceUpdate::class)
            ->assertSee('Manual Account - Test Bank')
            ->assertDontSee('Monzo Account');
    }
}
