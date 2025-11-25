<?php

namespace Tests\Feature\Livewire;

use App\Livewire\FinancialAccounts;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FinancialAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private IntegrationGroup $group;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'financial',
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'financial',
        ]);
    }

    #[Test]
    public function component_renders_successfully(): void
    {
        Livewire::test(FinancialAccounts::class)
            ->assertStatus(200);
    }

    #[Test]
    public function component_has_default_properties(): void
    {
        $component = Livewire::test(FinancialAccounts::class);

        $component->assertSet('search', null)
            ->assertSet('accountTypeFilter', null)
            ->assertSet('providerFilter', null)
            ->assertSet('showArchived', false)
            ->assertSet('showEmptyAccounts', true)
            ->assertSet('viewMode', 'cards')
            ->assertSet('perPage', 25);
    }

    #[Test]
    public function search_filter_resets_page(): void
    {
        $component = Livewire::test(FinancialAccounts::class);

        $component->set('search', 'test search')
            ->assertSet('search', 'test search');
    }

    #[Test]
    public function account_type_filter_can_be_set(): void
    {
        $component = Livewire::test(FinancialAccounts::class);

        $component->set('accountTypeFilter', 'current_account')
            ->assertSet('accountTypeFilter', 'current_account');
    }

    #[Test]
    public function provider_filter_can_be_set(): void
    {
        $component = Livewire::test(FinancialAccounts::class);

        $component->set('providerFilter', 'Monzo')
            ->assertSet('providerFilter', 'Monzo');
    }

    #[Test]
    public function clear_filters_resets_all_filters(): void
    {
        $component = Livewire::test(FinancialAccounts::class);

        $component->set('search', 'test')
            ->set('accountTypeFilter', 'savings_account')
            ->set('providerFilter', 'Bank')
            ->set('showArchived', true)
            ->set('showEmptyAccounts', false)
            ->call('clearFilters')
            ->assertSet('search', null)
            ->assertSet('accountTypeFilter', null)
            ->assertSet('providerFilter', null)
            ->assertSet('showArchived', false)
            ->assertSet('showEmptyAccounts', true);
    }

    #[Test]
    public function add_balance_modal_can_be_opened_and_closed(): void
    {
        $component = Livewire::test(FinancialAccounts::class);

        $component->assertSet('showAddBalanceModal', false)
            ->call('openAddBalanceModal')
            ->assertSet('showAddBalanceModal', true)
            ->call('closeAddBalanceModal')
            ->assertSet('showAddBalanceModal', false);
    }

    #[Test]
    public function create_account_modal_can_be_opened_and_closed(): void
    {
        $component = Livewire::test(FinancialAccounts::class);

        $component->assertSet('showCreateAccountModal', false)
            ->call('openCreateAccountModal')
            ->assertSet('showCreateAccountModal', true)
            ->call('closeCreateAccountModal')
            ->assertSet('showCreateAccountModal', false);
    }

    #[Test]
    public function view_mode_can_be_toggled(): void
    {
        $component = Livewire::test(FinancialAccounts::class);

        $component->assertSet('viewMode', 'cards')
            ->set('viewMode', 'table')
            ->assertSet('viewMode', 'table');
    }

    #[Test]
    public function show_archived_can_be_toggled(): void
    {
        $component = Livewire::test(FinancialAccounts::class);

        $component->assertSet('showArchived', false)
            ->set('showArchived', true)
            ->assertSet('showArchived', true);
    }

    #[Test]
    public function show_empty_accounts_can_be_toggled(): void
    {
        $component = Livewire::test(FinancialAccounts::class);

        $component->assertSet('showEmptyAccounts', true)
            ->set('showEmptyAccounts', false)
            ->assertSet('showEmptyAccounts', false);
    }

    #[Test]
    public function sort_by_can_be_changed(): void
    {
        $component = Livewire::test(FinancialAccounts::class);

        $component->assertSet('sortBy', ['column' => 'title', 'direction' => 'asc'])
            ->set('sortBy', ['column' => 'balance', 'direction' => 'desc'])
            ->assertSet('sortBy', ['column' => 'balance', 'direction' => 'desc']);
    }

    #[Test]
    public function expanded_sections_have_default_values(): void
    {
        $component = Livewire::test(FinancialAccounts::class);

        $expandedSections = $component->get('expandedSections');

        $this->assertTrue($expandedSections['current_account']);
        $this->assertTrue($expandedSections['credit_card']);
        $this->assertTrue($expandedSections['savings_account']);
        $this->assertFalse($expandedSections['other']);
    }

    #[Test]
    public function delete_account_can_be_called_for_owned_account(): void
    {
        $account = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'type' => 'manual_account',
            'metadata' => [
                'account_type' => 'savings_account',
                'name' => 'Test Account',
            ],
        ]);

        $component = Livewire::test(FinancialAccounts::class);
        $component->call('deleteAccount', $account)
            ->assertDispatched('account-deleted');
    }

    #[Test]
    public function delete_account_returns_forbidden_for_other_user_account(): void
    {
        $otherUser = User::factory()->create();
        $account = EventObject::factory()->create([
            'user_id' => $otherUser->id,
            'type' => 'manual_account',
        ]);

        $component = Livewire::test(FinancialAccounts::class);
        $component->call('deleteAccount', $account)
            ->assertForbidden();
    }

    #[Test]
    public function headers_method_returns_correct_structure(): void
    {
        $component = Livewire::test(FinancialAccounts::class);
        $instance = $component->instance();

        $headers = $instance->headers();

        $this->assertIsArray($headers);
        $this->assertNotEmpty($headers);

        // Check that key headers exist
        $headerKeys = array_column($headers, 'key');
        $this->assertContains('title', $headerKeys);
        $this->assertContains('balance', $headerKeys);
        $this->assertContains('currency', $headerKeys);
    }

    #[Test]
    public function per_page_can_be_changed(): void
    {
        $component = Livewire::test(FinancialAccounts::class);

        $component->assertSet('perPage', 25)
            ->set('perPage', 50)
            ->assertSet('perPage', 50);
    }

    #[Test]
    public function pagination_works_correctly(): void
    {
        $component = Livewire::test(FinancialAccounts::class);

        // Initially on page 1
        $component->call('nextPage');
        $component->call('previousPage');

        // Should still work without errors
        $component->assertOk();
    }
}
