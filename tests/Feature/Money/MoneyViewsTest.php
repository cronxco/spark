<?php

namespace Tests\Feature\Money;

use App\Livewire\FinancialAccounts;
use App\Livewire\Money\BufferPot;
use App\Livewire\Money\Now;
use App\Models\EventObject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MoneyViewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.enable_task_pipeline' => false]);
        Livewire::withoutLazyLoading();
    }

    #[Test]
    public function the_money_page_offers_each_view_and_keeps_cards_and_table(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test(FinancialAccounts::class);

        foreach (['Cards', 'Table', 'Now', 'Spending', 'Budgets', 'Pots'] as $label) {
            $component->assertSee($label);
        }
    }

    #[Test]
    public function a_view_can_be_opened_from_a_link_and_the_default_is_cards(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(FinancialAccounts::class)->assertSet('viewMode', 'cards');
        Livewire::actingAs($user)->withQueryParams(['viewMode' => 'pots'])->test(FinancialAccounts::class)->assertSet('viewMode', 'pots');
    }

    #[Test]
    public function the_money_page_has_no_data_clean_up_panel(): void
    {
        Livewire::actingAs(User::factory()->create())->test(FinancialAccounts::class)
            ->assertDontSeeLivewire('money.data-health')
            ->assertDontSee('Nothing changes until you press Apply');
    }

    #[Test]
    public function now_links_money_data_issues_to_sense_check_for_admins_only(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();
        foreach ([$admin, $member] as $user) {
            foreach (['One', 'Two'] as $title) {
                EventObject::create(['user_id' => $user->id, 'concept' => 'account', 'type' => 'bank_account',
                    'title' => $title, 'metadata' => ['account_id' => 'acc-1', 'currency' => 'GBP']]);
            }
        }

        Livewire::actingAs($admin)->test(Now::class)
            ->assertSee('Review in Sense Check')
            ->assertSee('section=money_data_health', false);
        Livewire::actingAs($member)->test(Now::class)->assertDontSee('Sense Check');
    }

    #[Test]
    public function now_shows_safe_to_spend_and_only_the_signed_in_users_figures(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        EventObject::create(['user_id' => $other->id, 'concept' => 'account', 'type' => 'monzo_pot',
            'title' => 'Secret pot', 'metadata' => []]);

        Livewire::actingAs($user)->test(Now::class)
            ->assertSee('Safe to spend')
            ->assertSee('Next bills')
            ->assertDontSee('Secret pot');
    }

    #[Test]
    public function the_buffer_pot_is_chosen_on_the_pots_view_and_only_from_own_pots(): void
    {
        $user = User::factory()->create();
        $theirs = EventObject::create(['user_id' => User::factory()->create()->id, 'concept' => 'account', 'type' => 'monzo_pot', 'title' => 'Theirs', 'metadata' => []]);

        Livewire::actingAs($user)->test(BufferPot::class)
            ->call('useAsBuffer', (string) $theirs->id)
            ->assertHasErrors('buffer');
        $this->assertArrayNotHasKey('money', $theirs->fresh()->metadata ?? []);
    }
}
