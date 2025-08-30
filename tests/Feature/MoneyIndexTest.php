<?php

namespace Tests\Feature;

use App\Integrations\Financial\FinancialPlugin;
use App\Livewire\FinancialAccounts;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MoneyIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * @test
     */
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

    /**
     * @test
     */
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

    /**
     * @test
     */
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

    /**
     * @test
     */
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

    /**
     * @test
     */
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

    /**
     * @test
     */
    public function money_index_requires_authentication(): void
    {
        $response = $this->get('/money');
        $response->assertRedirect('/login');
    }
}
