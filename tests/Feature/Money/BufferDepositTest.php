<?php

namespace Tests\Feature\Money;

use App\Livewire\Money\Spending as SpendingComponent;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Money\BufferDeposit;
use App\Services\Money\BufferDepositLinker;
use App\Services\Money\CardCover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class BufferDepositTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $bank;

    private Integration $monzo;

    private EventObject $card;

    private EventObject $pot;

    private EventObject $current;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.enable_task_pipeline' => false, 'money.buffer.enabled' => true]);
        Livewire::withoutLazyLoading();
        $this->user = User::factory()->create();
        $this->bank = $this->integration('gocardless');
        $this->monzo = $this->integration('monzo');
        $this->card = EventObject::create(['user_id' => $this->user->id, 'concept' => 'account', 'type' => 'bank_account', 'title' => 'Amex', 'metadata' => ['account_type' => 'credit_card']]);
        $this->current = EventObject::create(['user_id' => $this->user->id, 'concept' => 'account', 'type' => 'monzo_account', 'title' => 'Current', 'metadata' => []]);
        $this->pot = EventObject::create(['user_id' => $this->user->id, 'concept' => 'account', 'type' => 'monzo_pot', 'title' => 'Pot of Requirement', 'metadata' => [
            'pot_id' => 'pot_1', 'monzo' => ['current_account_id' => 'acc_1'],
        ]]);
        app(CardCover::class)->setBufferPot($this->user->id, (string) $this->pot->id);
    }

    #[Test]
    public function it_asks_monzo_to_deposit_the_total_with_a_stable_dedupe_id(): void
    {
        Http::fake(['api.monzo.com/*' => Http::response(['id' => 'pot_1'])]);
        $a = $this->spend(4000, 'Hotel');
        $b = $this->spend(2500, 'Flights');

        $result = app(BufferDeposit::class)->request($this->user->id, [(string) $a->id, (string) $b->id], 6500);

        $this->assertSame(6500, $result['total']);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PUT'
                && str_ends_with($request->url(), '/pots/pot_1/deposit')
                && $request['source_account_id'] === 'acc_1'
                && (int) $request['amount'] === 6500
                && str_starts_with($request['dedupe_id'], 'spark-buffer-');
        });
        $this->assertSame('requested', $a->refresh()->event_metadata['buffer']['status']);
        $this->assertSame(2500, $b->refresh()->event_metadata['buffer']['amount']);
        $this->assertSame($a->event_metadata['buffer']['dedupe_id'], $b->event_metadata['buffer']['dedupe_id']);
    }

    #[Test]
    public function it_refuses_when_switched_off_or_there_is_nothing_valid_to_move(): void
    {
        Http::fake();
        $paid = $this->spend(1000, 'Shop');

        config(['money.buffer.enabled' => false]);
        $this->assertRefused([(string) $paid->id], 'not switched on');

        config(['money.buffer.enabled' => true]);
        $this->assertRefused([], 'at least one');
        $this->assertRefused(['00000000-0000-0000-0000-000000000000'], 'could not be found');

        $refund = $this->spend(500, 'Refund', 'payment_from');
        $this->assertRefused([(string) $refund->id], 'credit-card spending');

        $monzoSpend = Event::create([
            'integration_id' => $this->monzo->id, 'source_id' => 'm1', 'actor_id' => $this->current->id, 'target_id' => $this->target('Pret')->id,
            'service' => 'monzo', 'domain' => 'money', 'action' => 'card_payment_to', 'time' => '2026-10-10 12:00:00',
            'value' => 300, 'value_multiplier' => 100, 'value_unit' => 'GBP', 'event_metadata' => [],
        ]);
        $this->assertRefused([(string) $monzoSpend->id], 'credit-card spending');

        Http::assertNothingSent();
    }

    #[Test]
    public function it_refuses_over_the_cap_a_changed_total_and_a_missing_pot_or_source(): void
    {
        Http::fake();
        $big = $this->spend(600_000, 'Sofa');
        $this->assertRefused([(string) $big->id], 'limit for one go');

        $small = $this->spend(1000, 'Shop');
        try {
            app(BufferDeposit::class)->request($this->user->id, [(string) $small->id], 999);
            $this->fail('A changed total must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('total changed', $e->getMessage());
        }

        $this->pot->update(['metadata' => ['pot_id' => 'pot_1', 'money' => ['role' => 'card_buffer']]]);
        $this->assertRefused([(string) $small->id], 'which Monzo account');

        app(CardCover::class)->setBufferPot($this->user->id, null);
        $this->assertRefused([(string) $small->id], 'Choose a buffer pot');

        Http::assertNothingSent();
    }

    #[Test]
    public function a_transaction_cannot_be_buffered_twice_and_a_refusal_is_recorded_then_retried_with_a_new_dedupe_id(): void
    {
        Http::fake(['api.monzo.com/*' => Http::sequence()->push(['message' => 'insufficient_funds'], 400)->push(['id' => 'pot_1'])]);
        $tx = $this->spend(4000, 'Hotel');

        $this->assertRefusedOnRequest([(string) $tx->id], 4000, 'insufficient_funds');
        $failed = $tx->refresh()->event_metadata['buffer'];
        $this->assertSame('failed', $failed['status']);

        app(BufferDeposit::class)->request($this->user->id, [(string) $tx->id], 4000);
        $retry = $tx->refresh()->event_metadata['buffer'];
        $this->assertSame('requested', $retry['status']);
        $this->assertNotSame($failed['dedupe_id'], $retry['dedupe_id']);

        $this->assertRefused([(string) $tx->id], 'already in the buffer');
        Http::assertSentCount(2);
    }

    #[Test]
    public function the_pot_transfer_links_the_requested_transactions_with_funded_by(): void
    {
        Http::fake(['api.monzo.com/*' => Http::response(['id' => 'pot_1'])]);
        $a = $this->spend(4000, 'Hotel');
        $b = $this->spend(2500, 'Flights');
        app(BufferDeposit::class)->request($this->user->id, [(string) $a->id, (string) $b->id], 6500);

        $wrong = $this->transfer(9999);
        $this->assertSame(0, app(BufferDepositLinker::class)->link($wrong));

        $transfer = $this->transfer(6500);
        $this->assertSame(2, app(BufferDepositLinker::class)->link($transfer));

        $this->assertSame('linked', $a->refresh()->event_metadata['buffer']['status']);
        $link = Relationship::where('type', 'funded_by')->where('from_id', $b->id)->first();
        $this->assertSame((string) $transfer->id, (string) $link->to_id);
        $this->assertSame(2500, (int) $link->value);
        $this->assertSame(0, app(BufferDepositLinker::class)->link($transfer));
    }

    #[Test]
    public function the_component_reviews_then_confirms_and_never_moves_money_without_the_confirmation(): void
    {
        Http::fake(['api.monzo.com/*' => Http::response(['id' => 'pot_1'])]);
        $tx = $this->spend(4000, 'Hotel');

        $component = Livewire::actingAs($this->user)->test(SpendingComponent::class)
            ->set('selected', [(string) $tx->id])
            ->call('reviewBuffer')
            ->assertSee('Move £40.00 into Pot of Requirement?');
        Http::assertNothingSent();

        $component->call('confirmBuffer')->assertSee('Asked Monzo to move £40.00');
        Http::assertSentCount(1);
    }

    /**
     * @param  list<string>  $ids
     */
    private function assertRefused(array $ids, string $fragment): void
    {
        try {
            app(BufferDeposit::class)->prepare($this->user->id, $ids);
            $this->fail("Expected a refusal containing '{$fragment}'.");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    /**
     * @param  list<string>  $ids
     */
    private function assertRefusedOnRequest(array $ids, int $total, string $fragment): void
    {
        try {
            app(BufferDeposit::class)->request($this->user->id, $ids, $total);
            $this->fail('Expected a refusal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    private function transfer(int $pence): Event
    {
        return Event::create([
            'integration_id' => $this->monzo->id, 'source_id' => 'tr-' . (++$this->counter), 'actor_id' => $this->current->id, 'target_id' => $this->pot->id,
            'service' => 'monzo', 'domain' => 'money', 'action' => 'pot_transfer_to', 'time' => now()->addMinutes(2),
            'value' => $pence, 'value_multiplier' => 100, 'value_unit' => 'GBP', 'event_metadata' => [],
        ]);
    }

    private function spend(int $pence, string $merchant, string $action = 'payment_to'): Event
    {
        return Event::create([
            'integration_id' => $this->bank->id, 'source_id' => 'g-' . (++$this->counter), 'actor_id' => $this->card->id, 'target_id' => $this->target($merchant)->id,
            'service' => 'gocardless', 'domain' => 'money', 'action' => $action, 'time' => now()->subDay(),
            'value' => $pence, 'value_multiplier' => 100, 'value_unit' => 'GBP',
            'event_metadata' => ['description' => $merchant, 'transaction_status' => 'booked'],
        ]);
    }

    private function target(string $title): EventObject
    {
        return EventObject::firstOrCreate(
            ['user_id' => $this->user->id, 'concept' => 'counterparty', 'type' => 'monzo_counterparty', 'title' => $title],
            ['time' => now(), 'metadata' => []],
        );
    }

    private function integration(string $service): Integration
    {
        return Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => $service])->id,
            'service' => $service, 'instance_type' => 'accounts',
        ]);
    }
}
