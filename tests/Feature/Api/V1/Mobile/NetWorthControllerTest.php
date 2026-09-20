<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Integrations\Financial\FinancialPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NetWorthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
    }

    #[Test]
    public function requires_authentication(): void
    {
        $this->getJson('/api/v1/mobile/money/net-worth')->assertStatus(401);
    }

    #[Test]
    public function sums_current_balances_and_compares_against_the_window(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['ios:read']);

        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'manual_account']);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'manual_account',
        ]);

        $plugin = new FinancialPlugin;
        $current = $plugin->upsertAccountObject($integration, [
            'name' => 'Current Account',
            'account_type' => 'current_account',
            'provider' => 'Test Bank',
            'currency' => 'GBP',
        ]);
        $creditCard = $plugin->upsertAccountObject($integration, [
            'name' => 'Credit Card',
            'account_type' => 'credit_card',
            'provider' => 'Test Bank',
            'currency' => 'GBP',
            'is_negative_balance' => true,
        ]);

        // Balances a month ago.
        $plugin->createBalanceEvent($integration, $current, ['balance' => 1000, 'date' => Carbon::now()->subDays(35)->toDateString()]);
        $plugin->createBalanceEvent($integration, $creditCard, ['balance' => 200, 'date' => Carbon::now()->subDays(35)->toDateString()]);

        // Balances today.
        $plugin->createBalanceEvent($integration, $current, ['balance' => 1500, 'date' => Carbon::now()->toDateString()]);
        $plugin->createBalanceEvent($integration, $creditCard, ['balance' => 300, 'date' => Carbon::now()->toDateString()]);

        $response = $this->getJson('/api/v1/mobile/money/net-worth?compare=1month')->assertOk();

        // 1500 - 300 = 1200 today; 1000 - 200 = 800 a month ago.
        $response->assertJsonPath('data.total', 1200)
            ->assertJsonPath('data.comparison.then', 800)
            ->assertJsonPath('data.comparison.change', 400)
            ->assertJsonPath('data.comparison.window', '1month')
            ->assertJsonPath('data.currency', 'GBP')
            ->assertJsonPath('data.excluded_accounts', 0);
    }

    #[Test]
    public function excludes_accounts_without_history_spanning_the_window_from_both_sides(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['ios:read']);

        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'manual_account']);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'manual_account',
        ]);

        $plugin = new FinancialPlugin;

        $established = $plugin->upsertAccountObject($integration, [
            'name' => 'Old Account', 'account_type' => 'current_account', 'provider' => 'Bank', 'currency' => 'GBP',
        ]);
        $plugin->createBalanceEvent($integration, $established, ['balance' => 500, 'date' => Carbon::now()->subDays(35)->toDateString()]);
        $plugin->createBalanceEvent($integration, $established, ['balance' => 600, 'date' => Carbon::now()->toDateString()]);

        $newAccount = $plugin->upsertAccountObject($integration, [
            'name' => 'New Account', 'account_type' => 'current_account', 'provider' => 'Bank', 'currency' => 'GBP',
        ]);
        $plugin->createBalanceEvent($integration, $newAccount, ['balance' => 50, 'date' => Carbon::now()->toDateString()]);

        $response = $this->getJson('/api/v1/mobile/money/net-worth?compare=1month')->assertOk();

        // The new account (no month-old history) still counts toward the
        // current total (600 + 50), but is excluded from the comparison
        // entirely — its balance would otherwise read as pure growth.
        $response->assertJsonPath('data.total', 650)
            ->assertJsonPath('data.comparison.then', 500)
            ->assertJsonPath('data.excluded_accounts', 1);
    }

    #[Test]
    public function excludes_accounts_in_a_different_currency(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['ios:read']);

        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'manual_account']);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'manual_account',
        ]);

        $plugin = new FinancialPlugin;

        $gbp = $plugin->upsertAccountObject($integration, [
            'name' => 'GBP Account', 'account_type' => 'current_account', 'provider' => 'Bank', 'currency' => 'GBP',
        ]);
        $plugin->createBalanceEvent($integration, $gbp, ['balance' => 90, 'date' => Carbon::now()->subDays(35)->toDateString()]);
        $plugin->createBalanceEvent($integration, $gbp, ['balance' => 100, 'date' => Carbon::now()->toDateString()]);

        $usd = $plugin->upsertAccountObject($integration, [
            'name' => 'USD Account', 'account_type' => 'current_account', 'provider' => 'Bank', 'currency' => 'USD',
        ]);
        $plugin->createBalanceEvent($integration, $usd, ['balance' => 999, 'date' => Carbon::now()->toDateString()]);

        $response = $this->getJson('/api/v1/mobile/money/net-worth')->assertOk();

        $response->assertJsonPath('data.total', 100)
            ->assertJsonPath('data.excluded_accounts', 1);
    }

    #[Test]
    public function rejects_an_unknown_compare_window(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read']);

        $this->getJson('/api/v1/mobile/money/net-worth?compare=2days')->assertStatus(422);
    }
}
