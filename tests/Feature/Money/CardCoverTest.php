<?php

namespace Tests\Feature\Money;

use App\Livewire\Money\BufferPot;
use App\Livewire\Money\CardCover as CardCoverComponent;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\Money\CardCover;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CardCoverTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    private EventObject $card;

    private EventObject $pot;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.enable_task_pipeline' => false]);
        Livewire::withoutLazyLoading();
        $this->user = User::factory()->create();
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'gocardless'])->id,
            'service' => 'gocardless', 'instance_type' => 'accounts',
        ]);
        $this->card = EventObject::create(['user_id' => $this->user->id, 'concept' => 'account', 'type' => 'bank_account', 'title' => 'Amex', 'metadata' => ['account_type' => 'credit_card']]);
        $this->pot = EventObject::create(['user_id' => $this->user->id, 'concept' => 'account', 'type' => 'monzo_pot', 'title' => 'Pot of Requirement', 'metadata' => ['pot_id' => 'p1']]);
    }

    #[Test]
    public function without_a_buffer_pot_the_cards_report_no_buffer(): void
    {
        $this->cardEvent('payment_to', 20_000, '2026-10-05 10:00:00', 'Shop');

        $cover = app(CardCover::class)->build($this->user->id, CarbonImmutable::parse('2026-10-10'));

        $this->assertSame('no_buffer', $cover['status']);
        $this->assertSame(20_000, $cover['owed']);
        $this->assertSame(20_000, $cover['shortfall']);
    }

    #[Test]
    public function it_counts_spending_since_the_last_repayment_and_compares_it_with_the_pot(): void
    {
        app(CardCover::class)->setBufferPot($this->user->id, (string) $this->pot->id);
        $this->cardEvent('payment_to', 99_000, '2026-09-20 10:00:00', 'Before repayment');
        $this->cardEvent('payment_from', 99_000, '2026-10-02 09:00:00', 'Payment', 'DIRECT DEBIT PAYMENT - THANK YOU');
        $this->cardEvent('payment_to', 30_000, '2026-10-05 10:00:00', 'Hotel');
        $this->cardEvent('payment_to', 20_000, '2026-10-06 10:00:00', 'Flights', null, ['buffer' => ['status' => 'linked']]);
        $this->balance(40_000);

        $cover = app(CardCover::class)->build($this->user->id, CarbonImmutable::parse('2026-10-10'));

        $this->assertSame(50_000, $cover['owed']);
        $this->assertSame(20_000, $cover['buffered']);
        $this->assertSame(30_000, $cover['unbuffered']);
        $this->assertSame('short', $cover['status']);
        $this->assertSame(10_000, $cover['shortfall']);
        $this->assertSame('2026-10-02', $cover['cards'][0]['since']);
    }

    #[Test]
    public function a_big_enough_pot_is_covered(): void
    {
        app(CardCover::class)->setBufferPot($this->user->id, (string) $this->pot->id);
        $this->cardEvent('payment_to', 30_000, '2026-10-05 10:00:00', 'Hotel');
        $this->balance(30_000);

        $cover = app(CardCover::class)->build($this->user->id, CarbonImmutable::parse('2026-10-10'));

        $this->assertSame('covered', $cover['status']);
        $this->assertSame(0, $cover['shortfall']);
    }

    #[Test]
    public function only_one_pot_holds_the_buffer_role_and_it_can_be_cleared(): void
    {
        $other = EventObject::create(['user_id' => $this->user->id, 'concept' => 'account', 'type' => 'monzo_pot', 'title' => 'Other', 'metadata' => ['pot_id' => 'p2']]);
        $service = app(CardCover::class);

        $service->setBufferPot($this->user->id, (string) $this->pot->id);
        $service->setBufferPot($this->user->id, (string) $other->id);

        $this->assertSame((string) $other->id, (string) $service->bufferPot($this->user->id)->id);
        $this->assertSame(['pot_id' => 'p1'], $this->pot->refresh()->metadata);

        $service->setBufferPot($this->user->id, null);
        $this->assertNull($service->bufferPot($this->user->id));
    }

    #[Test]
    public function it_refuses_a_pot_that_is_not_the_users(): void
    {
        $stranger = User::factory()->create();
        $theirs = EventObject::create(['user_id' => $stranger->id, 'concept' => 'account', 'type' => 'monzo_pot', 'title' => 'Theirs', 'metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        app(CardCover::class)->setBufferPot($this->user->id, (string) $theirs->id);
    }

    #[Test]
    public function the_cover_shows_the_status_and_the_pots_view_sets_the_pot(): void
    {
        Livewire::actingAs($this->user)->test(CardCoverComponent::class)
            ->assertSee('No buffer pot is set')
            ->assertSee('Pots view');

        Livewire::actingAs($this->user)->test(BufferPot::class)
            ->call('useAsBuffer', (string) $this->pot->id);

        $this->assertSame('card_buffer', $this->pot->refresh()->metadata['money']['role']);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function cardEvent(string $action, int $pence, string $time, string $merchant, ?string $description = null, array $extra = []): Event
    {
        $target = EventObject::firstOrCreate(
            ['user_id' => $this->user->id, 'concept' => 'counterparty', 'type' => 'monzo_counterparty', 'title' => $merchant],
            ['time' => now(), 'metadata' => []],
        );

        return Event::create([
            'integration_id' => $this->integration->id, 'source_id' => 'c-' . (++$this->counter), 'actor_id' => $this->card->id,
            'target_id' => $target->id, 'service' => 'gocardless', 'domain' => 'money', 'action' => $action, 'time' => $time,
            'value' => $pence, 'value_multiplier' => 100, 'value_unit' => 'GBP',
            'event_metadata' => ['description' => $description ?? $merchant, 'transaction_status' => 'booked'] + $extra,
        ]);
    }

    private function balance(int $pence): void
    {
        $day = EventObject::firstOrCreate(
            ['user_id' => $this->user->id, 'concept' => 'day', 'type' => 'day', 'title' => '2026-10-09'],
            ['time' => '2026-10-09 00:00:00', 'metadata' => []],
        );
        Event::create([
            'integration_id' => $this->integration->id, 'source_id' => 'bal-' . (++$this->counter), 'actor_id' => $this->pot->id,
            'target_id' => $day->id, 'service' => 'monzo', 'domain' => 'money', 'action' => 'had_balance',
            'time' => '2026-10-09 23:59:59', 'value' => $pence, 'value_multiplier' => 100, 'value_unit' => 'GBP',
        ]);
    }
}
