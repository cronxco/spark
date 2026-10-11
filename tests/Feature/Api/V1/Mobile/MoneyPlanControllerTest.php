<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Support\SparkAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MoneyPlanControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $bank;

    private EventObject $card;

    private EventObject $pot;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true, 'app.enable_task_pipeline' => false]);
        $this->user = User::factory()->create();
        $this->bank = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'gocardless'])->id,
            'service' => 'gocardless', 'instance_type' => 'accounts',
        ]);
        Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'monzo'])->id,
            'service' => 'monzo', 'instance_type' => 'accounts',
        ]);
        $this->card = EventObject::create(['user_id' => $this->user->id, 'concept' => 'account', 'type' => 'bank_account', 'title' => 'Amex', 'metadata' => ['account_type' => 'credit_card']]);
        $this->pot = EventObject::create(['user_id' => $this->user->id, 'concept' => 'account', 'type' => 'monzo_pot', 'title' => 'Buffer', 'metadata' => ['pot_id' => 'pot_1', 'monzo' => ['current_account_id' => 'acc_1']]]);
    }

    #[Test]
    public function plan_returns_the_cover_budgets_and_safe_to_spend(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_READ);
        $this->spend(12_000, 'Hotel');

        $this->getJson('/api/v1/mobile/money/plan')
            ->assertOk()
            ->assertJsonPath('data.cover.status', 'no_buffer')
            ->assertJsonPath('data.cover.owed', 12_000)
            ->assertJsonPath('data.safe_to_spend.amount', -12_000)
            ->assertJsonPath('data.buffer_enabled', false)
            ->assertJsonStructure(['data' => ['strip', 'pots', 'interest', 'budgets']]);
    }

    #[Test]
    public function spending_lists_the_months_transactions_and_validates_the_month(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_READ);
        $this->spend(12_000, 'Hotel');

        $this->getJson('/api/v1/mobile/money/spending?month=' . now()->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('data.total', 12_000)
            ->assertJsonPath('data.transactions.0.merchant', 'Hotel');
        $this->getJson('/api/v1/mobile/money/spending?month=2020-01')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/v1/mobile/money/spending?month=nope')->assertStatus(422);
    }

    #[Test]
    public function budgets_can_be_created_updated_and_archived(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $id = $this->postJson('/api/v1/mobile/money/budgets', ['name' => 'Eating out', 'amount' => 25_000, 'categories' => ['eating_out']])
            ->assertCreated()->assertJsonPath('data.limit', 25_000)->json('data.id');
        $this->postJson('/api/v1/mobile/money/budgets', ['name' => 'Eating out', 'amount' => 100])->assertStatus(422);
        $this->postJson('/api/v1/mobile/money/budgets', ['name' => 'Bad', 'amount' => 0])->assertStatus(422);

        $this->patchJson("/api/v1/mobile/money/budgets/{$id}", ['amount' => 30_000])->assertOk()->assertJsonPath('data.limit', 30_000);
        $this->getJson('/api/v1/mobile/money/budgets')->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/mobile/money/budgets/{$id}")->assertNoContent();
        $this->getJson('/api/v1/mobile/money/budgets')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function writes_need_the_finance_write_ability(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_READ);

        $this->postJson('/api/v1/mobile/money/budgets', ['name' => 'X', 'amount' => 100])->assertForbidden();
        $this->postJson('/api/v1/mobile/money/buffer', ['event_ids' => ['x'], 'confirmed_total' => 1])->assertForbidden();
    }

    #[Test]
    public function the_buffer_pot_can_be_set_and_cleared(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->patchJson("/api/v1/mobile/money/pots/{$this->pot->id}", ['card_buffer' => true])
            ->assertOk()->assertJsonPath('data.card_buffer_pot_id', (string) $this->pot->id);
        $this->patchJson("/api/v1/mobile/money/pots/{$this->pot->id}", ['card_buffer' => false])
            ->assertOk()->assertJsonPath('data.card_buffer_pot_id', null);
        $this->patchJson('/api/v1/mobile/money/pots/' . $this->card->id, ['card_buffer' => true])->assertNotFound();
    }

    #[Test]
    public function buffer_previews_and_moves_money_only_when_enabled_and_confirmed_with_idempotency(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        Http::fake(['api.monzo.com/*' => Http::response(['id' => 'pot_1'])]);
        $tx = $this->spend(4_000, 'Hotel');
        $this->patchJson("/api/v1/mobile/money/pots/{$this->pot->id}", ['card_buffer' => true])->assertOk();
        $body = ['event_ids' => [(string) $tx->id], 'confirmed_total' => 4_000];

        $this->postJson('/api/v1/mobile/money/buffer/preview', $body)->assertStatus(422)->assertJsonPath('message', 'Add to buffer is not switched on yet.');

        config(['money.buffer.enabled' => true]);
        $this->postJson('/api/v1/mobile/money/buffer/preview', $body)->assertOk()->assertJsonPath('data.total', 4_000);
        Http::assertNothingSent();

        $this->postJson('/api/v1/mobile/money/buffer', ['confirmed_total' => 1] + $body)->assertStatus(422);
        Http::assertNothingSent();

        $headers = ['Idempotency-Key' => 'abc'];
        $this->postJson('/api/v1/mobile/money/buffer', $body, $headers)->assertStatus(202)->assertJsonPath('data.total', 4_000);
        $this->postJson('/api/v1/mobile/money/buffer', $body, $headers)->assertStatus(202);
        Http::assertSentCount(1);
    }

    private function spend(int $pence, string $merchant): Event
    {
        $target = EventObject::firstOrCreate(
            ['user_id' => $this->user->id, 'concept' => 'counterparty', 'type' => 'monzo_counterparty', 'title' => $merchant],
            ['time' => now(), 'metadata' => []],
        );

        return Event::create([
            'integration_id' => $this->bank->id, 'source_id' => 'g-' . uniqid(), 'actor_id' => $this->card->id, 'target_id' => $target->id,
            'service' => 'gocardless', 'domain' => 'money', 'action' => 'payment_to', 'time' => now()->subHour(),
            'value' => $pence, 'value_multiplier' => 100, 'value_unit' => 'GBP',
            'event_metadata' => ['description' => $merchant, 'transaction_status' => 'booked'],
        ]);
    }
}
