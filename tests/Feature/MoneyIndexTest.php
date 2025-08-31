<?php

namespace Tests\Feature;

use App\Integrations\Financial\FinancialPlugin;
use App\Livewire\FinancialAccounts;
use App\Livewire\FinancialAccountShow;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MoneyIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    #[Test]
    public function money_index_displays_service_column(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Create at least one account so the table is rendered
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
        $plugin->upsertAccountObject($integration, [
            'name' => 'Test Account',
            'account_type' => 'current_account',
            'provider' => 'Test Bank',
        ]);

        Livewire::test(FinancialAccounts::class)
            ->assertSee('Service')
            ->assertSee('Account')
            ->assertSee('Type')
            ->assertSee('Provider');
    }

    #[Test]
    public function money_index_shows_manual_accounts_with_correct_service(): void
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

        Livewire::test(FinancialAccounts::class)
            ->assertSee('Manual Account')
            ->assertSee('Test Bank')
            ->assertSee('Manual') // Service column should show "Manual"
            ->assertSee('current_account');
    }

    #[Test]
    public function money_index_shows_monzo_accounts_with_correct_service(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Create a mock Monzo account
        $monzoAccount = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'account',
            'type' => 'monzo_account',
            'title' => 'Monzo Account',
            'metadata' => [
                'name' => 'Monzo Account',
                'provider' => 'Monzo',
                'account_type' => 'current_account',
            ],
        ]);

        Livewire::test(FinancialAccounts::class)
            ->assertSee('Monzo Account')
            ->assertSee('Monzo')
            ->assertSee('Monzo') // Service column should show "Monzo"
            ->assertSee('current_account');
    }

    #[Test]
    public function money_index_shows_gocardless_accounts_with_correct_service(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Create a mock GoCardless account
        $gocardlessAccount = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'account',
            'type' => 'bank_account',
            'title' => 'GoCardless Account',
            'metadata' => [
                'name' => 'GoCardless Account',
                'provider' => 'GoCardless',
                'account_type' => 'current_account',
                'details' => 'Bank Account',
            ],
        ]);

        Livewire::test(FinancialAccounts::class)
            ->assertSee('GoCardless Account')
            ->assertSee('GoCardless') // Service column should show "GoCardless"
            ->assertSee('current_account');
    }

    #[Test]
    public function money_index_shows_all_account_types(): void
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

        // Create a mock Monzo account
        $monzoAccount = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'account',
            'type' => 'monzo_account',
            'title' => 'Monzo Account',
            'metadata' => [
                'name' => 'Monzo Account',
                'provider' => 'Monzo',
                'account_type' => 'current_account',
            ],
        ]);

        // Create a mock GoCardless account
        $gocardlessAccount = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'account',
            'type' => 'bank_account',
            'title' => 'GoCardless Account',
            'metadata' => [
                'name' => 'GoCardless Account',
                'provider' => 'GoCardless',
                'account_type' => 'current_account',
                'details' => 'Bank Account',
            ],
        ]);

        Livewire::test(FinancialAccounts::class)
            ->assertSee('Manual Account')
            ->assertSee('Monzo Account')
            ->assertSee('GoCardless Account')
            ->assertSee('Manual')
            ->assertSee('Monzo')
            ->assertSee('GoCardless');
    }

    #[Test]
    public function money_index_requires_authentication(): void
    {
        $response = $this->get('/money');
        $response->assertRedirect('/login');
    }

    #[Test]
    public function money_show_view_displays_account_details_correctly(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Create a mock Monzo account with title
        $monzoAccount = new EventObject([
            'user_id' => $user->id,
            'time' => now(),
            'concept' => 'account',
            'type' => 'monzo_account',
            'title' => 'Current Account',
            'metadata' => [
                'name' => 'Monzo Account',
                'provider' => 'Monzo',
                'account_type' => 'current_account',
                'currency' => 'GBP',
            ],
        ]);
        $monzoAccount->save();

        // Test that the show view uses the title field for Monzo accounts
        // Test the Livewire component directly instead of the HTTP route
        Livewire::test(FinancialAccountShow::class, ['account' => $monzoAccount])
            ->assertSee('Current Account') // Should use title field
            ->assertSee('Monzo') // Provider
            ->assertSee('Current Account'); // Account type
    }

    #[Test]
    public function money_show_view_displays_monzo_pot_with_title(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Create a mock Monzo pot with title
        $monzoPot = new EventObject([
            'user_id' => $user->id,
            'time' => now(),
            'concept' => 'account',
            'type' => 'monzo_pot',
            'title' => 'Rainy Day Pot',
            'content' => '500.00',
            'metadata' => [
                'name' => 'Pot',
                'provider' => 'Monzo',
                'account_type' => 'savings_account',
                'currency' => 'GBP',
            ],
        ]);
        $monzoPot->save();

        // Test that the show view uses the title field for Monzo pots
        // Test the Livewire component directly instead of the HTTP route
        Livewire::test(FinancialAccountShow::class, ['account' => $monzoPot])
            ->assertSee('Rainy Day Pot') // Should use title field
            ->assertSee('Monzo') // Provider
            ->assertSee('Savings Account'); // Account type
    }

    #[Test]
    public function money_show_view_displays_manual_account_with_metadata_name(): void
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

        // Test that the show view uses metadata name for manual accounts
        // Test the Livewire component directly instead of the HTTP route
        Livewire::test(FinancialAccountShow::class, ['account' => $manualAccount])
            ->assertSee('Manual Account') // Should use metadata name
            ->assertSee('Test Bank') // Provider
            ->assertSee('Current Account'); // Account type
    }

    #[Test]
    public function money_show_view_requires_authentication(): void
    {
        // Create a mock account
        $user = User::factory()->create();
        $account = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'account',
            'type' => 'monzo_account',
            'title' => 'Test Account',
        ]);

        // Test that unauthenticated users are redirected
        $response = $this->get("/money/{$account->id}");
        $response->assertRedirect('/login');
    }

    #[Test]
    public function money_show_view_prevents_access_to_other_users_accounts(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // Create an account owned by user2
        $account = EventObject::factory()->create([
            'user_id' => $user2->id,
            'concept' => 'account',
            'type' => 'monzo_account',
            'title' => 'Other User Account',
        ]);

        // Test that user1 cannot access user2's account
        $this->actingAs($user1);
        $response = $this->get("/money/{$account->id}");
        $response->assertStatus(403); // Forbidden
    }
}
