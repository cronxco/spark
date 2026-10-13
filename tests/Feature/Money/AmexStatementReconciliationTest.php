<?php

namespace Tests\Feature\Money;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Money\CardCover;
use App\Services\Money\PlanSnapshot;
use App\Services\Money\SpendingScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The 7 Oct 2026 British Airways Premium Plus statement: £9,621.74 from Amex, shown as £16,919.44.
 * Fixtures use the dates, amounts and statuses seen in the data.
 */
class AmexStatementReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    private EventObject $card;

    private int $counter = 0;

    /**
     * @var array{0: Event, 1: Event}
     */
    private array $qantas;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.enable_task_pipeline' => false]);
        $this->user = User::factory()->create();
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'gocardless'])->id,
            'service' => 'gocardless', 'instance_type' => 'transactions',
        ]);
        $this->card = EventObject::create([
            'user_id' => $this->user->id, 'concept' => 'account', 'type' => 'bank_account', 'title' => 'British Airways Premium Plus',
            'metadata' => ['account_type' => 'credit_card', 'money' => ['billing' => ['statement_day' => 7, 'payment_day' => 19]]],
        ]);
    }

    #[Test]
    public function the_october_statement_is_the_issuers_9621_74_and_new_spending_is_321_26(): void
    {
        $this->seedStatement();

        $card = $this->cover()['cards'][0];

        $this->assertSame(962_174, $card['statement_owed']);
        $this->assertSame('estimate', $card['statement_basis']);
        $this->assertSame('2026-10-07', $card['statement_closed']);
        $this->assertSame(32_126, $card['spent_since_statement']);
        $this->assertSame(994_300, $card['owed']);
    }

    #[Test]
    public function the_previous_statement_repayment_does_not_reduce_the_new_statement(): void
    {
        $this->seedStatement();

        $card = $this->cover()['cards'][0];

        $this->assertSame(962_174, $card['statement_owed']);
        $this->assertNull($card['payment_due']);
    }

    #[Test]
    public function the_direct_debit_date_is_not_called_a_due_date(): void
    {
        $this->seedStatement();

        $card = $this->cover()['cards'][0];

        $this->assertSame('2026-10-19', $card['direct_debit']);
        $this->assertNull($card['payment_due']);
    }

    #[Test]
    public function purchases_processed_after_the_cut_off_belong_to_the_next_statement_even_when_dated_on_the_closing_day(): void
    {
        $this->line('payment_to', 395, '2026-10-07 11:42:51', 'Sainsburys', ['booking_date' => '2026-10-07', 'value_date' => '2026-10-08']);
        $this->line('payment_to', 1_646, '2026-10-07 16:13:13', 'Flight Club', ['booking_date' => '2026-10-07', 'value_date' => '2026-10-08']);
        $this->line('payment_to', 30_000, '2026-10-06 09:00:00', 'Membership fee', ['booking_date' => '2026-10-06', 'value_date' => '2026-10-07']);

        $card = $this->cover()['cards'][0];

        $this->assertSame(30_000, $card['statement_owed']);
        $this->assertSame(2_041, $card['spent_since_statement']);
    }

    #[Test]
    public function a_recorded_issuer_statement_replaces_the_estimate_and_stays_put(): void
    {
        $this->seedStatement();
        app(CardCover::class)->recordStatement(
            $this->user->id, (string) $this->card->id, CarbonImmutable::parse('2026-10-07'), 962_174,
            CarbonImmutable::parse('2026-10-19'), CarbonImmutable::parse('2026-11-01'),
        );
        $this->line('payment_to', 5_000, '2026-10-05 10:00:00', 'Late import', ['booking_date' => '2026-10-05', 'value_date' => '2026-10-06']);

        $card = $this->cover()['cards'][0];

        $this->assertSame('confirmed', $card['statement_basis']);
        $this->assertSame(962_174, $card['statement_owed']);
        $this->assertSame('2026-11-01', $card['payment_due']);
        $this->assertSame('2026-10-19', $card['direct_debit']);
    }

    #[Test]
    public function a_pending_authorisation_settling_as_two_purchases_counts_once(): void
    {
        $this->line('payment_to', 59_900, '2026-09-25 00:01:25', 'British Airways', ['transaction_status' => 'pending', 'booking_date' => '2026-09-25']);
        $this->line('payment_to', 29_950, '2026-09-25 00:00:00', 'British Airways', ['booking_date' => '2026-09-25', 'value_date' => '2026-09-27']);
        $this->line('payment_to', 29_950, '2026-09-26 00:00:00', 'British Airways', ['booking_date' => '2026-09-26', 'value_date' => '2026-09-28']);

        $this->assertSame(59_900, $this->spend()->sum('amount'));
        $this->assertCount(2, $this->spend());
    }

    #[Test]
    public function a_partly_settled_authorisation_counts_only_what_is_left(): void
    {
        $this->line('payment_to', 59_900, '2026-09-25 00:01:25', 'British Airways', ['transaction_status' => 'pending', 'booking_date' => '2026-09-25']);
        $this->line('payment_to', 29_950, '2026-09-25 00:00:00', 'British Airways', ['booking_date' => '2026-09-25', 'value_date' => '2026-09-27']);

        $entries = $this->spend();

        $this->assertSame(59_900, $entries->sum('amount'));
        $this->assertSame(29_950, $entries->firstWhere('pending', true)['amount']);
        $this->assertSame(59_900, $entries->firstWhere('pending', true)['pending_authorised']);
    }

    #[Test]
    public function an_unrelated_purchase_from_another_merchant_does_not_settle_an_authorisation(): void
    {
        $this->line('payment_to', 59_900, '2026-09-25 00:01:25', 'British Airways', ['transaction_status' => 'pending', 'booking_date' => '2026-09-25']);
        $this->line('payment_to', 29_950, '2026-09-25 10:00:00', 'Hotel Chocolat', ['booking_date' => '2026-09-25', 'value_date' => '2026-09-27']);

        $this->assertSame(89_850, $this->spend()->sum('amount'));
    }

    #[Test]
    public function a_booked_line_settles_only_one_authorisation(): void
    {
        $this->line('payment_to', 29_950, '2026-09-25 00:01:25', 'British Airways', ['transaction_status' => 'pending', 'booking_date' => '2026-09-25']);
        $this->line('payment_to', 29_950, '2026-09-25 00:02:25', 'British Airways', ['transaction_status' => 'pending', 'booking_date' => '2026-09-25']);
        $this->line('payment_to', 29_950, '2026-09-26 00:00:00', 'British Airways', ['booking_date' => '2026-09-26', 'value_date' => '2026-09-28']);

        $this->assertSame(59_900, $this->spend()->sum('amount'));
    }

    #[Test]
    public function an_authorisation_that_never_settles_stops_counting_after_the_expiry(): void
    {
        $this->line('payment_to', 12_000, '2026-08-01 10:00:00', 'Hotel', ['transaction_status' => 'pending', 'booking_date' => '2026-08-01']);
        $this->line('payment_to', 3_000, '2026-10-01 10:00:00', 'Cafe', ['transaction_status' => 'pending', 'booking_date' => '2026-10-01']);

        $entries = app(SpendingScope::class)->entries($this->user->id, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-10-13'));

        $this->assertSame(3_000, $entries->sum('amount'));
    }

    #[Test]
    public function two_separate_purchases_of_the_same_amount_at_the_same_shop_are_both_kept(): void
    {
        $this->line('payment_to', 395, '2026-09-28 11:08:45', 'Sainsburys', ['booking_date' => '2026-09-28', 'value_date' => '2026-09-29'], ['transactionId' => 'AT1']);
        $this->line('payment_to', 395, '2026-09-28 18:30:00', 'Sainsburys', ['booking_date' => '2026-09-28', 'value_date' => '2026-09-29'], ['transactionId' => 'AT2']);

        $this->artisan('gocardless:reconcile-transactions', ['--user' => $this->user->id, '--apply' => true])->assertSuccessful();

        $this->assertSame(790, $this->spend()->sum('amount'));
    }

    #[Test]
    public function the_duplicate_qantas_booking_merges_to_one_and_keeps_its_links(): void
    {
        $this->seedStatement(false);
        [$first, $second] = $this->qantas;
        $link = Relationship::create([
            'user_id' => $this->user->id, 'from_type' => Event::class, 'from_id' => $second->id,
            'to_type' => EventObject::class, 'to_id' => $this->card->id, 'type' => 'part_of',
        ]);

        $this->artisan('gocardless:reconcile-transactions', ['--user' => $this->user->id])->assertSuccessful();
        $this->assertNotSoftDeleted($first);
        $this->assertNotSoftDeleted($second);

        $this->artisan('gocardless:reconcile-transactions', ['--user' => $this->user->id, '--apply' => true])->assertSuccessful();

        // The copy other records lean on survives, the other is retired with a pointer to it
        $this->assertNotSoftDeleted($second);
        $this->assertSoftDeleted($first);
        $this->assertSame((string) $second->id, Event::withTrashed()->find($first->id)->event_metadata['merged_into']);
        $this->assertSame((string) $second->id, (string) $link->fresh()->from_id);
        $this->assertSame(962_174, $this->cover()['cards'][0]['statement_owed']);

        // Repeating changes nothing
        $this->artisan('gocardless:reconcile-transactions', ['--user' => $this->user->id, '--apply' => true])->assertSuccessful();
        $this->assertSame(1, Event::where('event_metadata->raw->transactionId', 'AT262530048000010959713---0005---20260908')->count());
        $this->assertSame(962_174, $this->cover()['cards'][0]['statement_owed']);
    }

    #[Test]
    public function the_duplicate_qantas_booking_overstates_the_statement_until_it_is_merged(): void
    {
        $this->seedStatement(false);

        $this->assertSame(962_174 + 697_779, $this->cover()['cards'][0]['statement_owed']);
    }

    #[Test]
    public function the_same_bank_id_on_two_different_accounts_is_not_merged(): void
    {
        $other = EventObject::create(['user_id' => $this->user->id, 'concept' => 'account', 'type' => 'bank_account', 'title' => 'Other card', 'metadata' => ['account_type' => 'credit_card']]);
        $this->line('payment_to', 500, '2026-09-20 10:00:00', 'Shop', [], ['transactionId' => 'SAME']);
        $copy = $this->line('payment_to', 500, '2026-09-20 10:00:00', 'Shop', [], ['transactionId' => 'SAME']);
        $copy->update(['actor_id' => $other->id]);

        $this->artisan('gocardless:reconcile-transactions', ['--user' => $this->user->id, '--apply' => true])->assertSuccessful();

        $this->assertSame(2, Event::where('event_metadata->raw->transactionId', 'SAME')->count());
    }

    #[Test]
    public function the_plan_snapshot_that_feeds_mobile_and_flint_carries_the_reconciled_figures(): void
    {
        $this->seedStatement();

        $snapshot = app(PlanSnapshot::class)->build($this->user->id);

        $this->assertSame(962_174, $snapshot['cover']['cards'][0]['statement_owed']);
        $this->assertSame(994_300, $snapshot['cover']['owed']);
        $this->assertSame(994_300, $snapshot['safe_to_spend']['owed_on_cards']);
    }

    private function seedStatement(bool $merge = true): void
    {
        // 19 Sep: repayment of the previous statement, which must not touch the new one
        $this->line('payment_from', 206_768, '2026-09-19 10:00:00', 'Payment', ['booking_date' => '2026-09-19', 'value_date' => '2026-09-19'], [], 'DIRECT DEBIT PAYMENT - THANK YOU');
        // Qantas, stored twice with the same bank ids (different creditor formatting)
        $qantasIds = ['transactionId' => 'AT262530048000010959713---0005---20260908', 'internalTransactionId' => '4d2ed423c119ca12080e8d5f3226dcd8'];
        $this->qantas = [
            $this->line('payment_to', 697_779, '2026-09-08 00:00:00', 'QANTAS', ['booking_date' => '2026-09-08', 'value_date' => '2026-09-10'], $qantasIds + ['creditorName' => 'QANTAS AIRWAYS LIMITED MAIL/TEL SALES']),
            $this->line('payment_to', 697_779, '2026-09-08 19:53:03', 'QANTAS', ['booking_date' => '2026-09-08', 'value_date' => '2026-09-10'], $qantasIds + ['creditorName' => 'QANTAS AIRWAYS LIMITED MAILTEL SALES']),
        ];
        // British Airways: one ticket booked, the second known only as part of a £599 authorisation
        $this->line('payment_to', 29_950, '2026-09-25 00:00:00', 'British Airways', ['booking_date' => '2026-09-25', 'value_date' => '2026-09-27']);
        $this->line('payment_to', 59_900, '2026-09-25 00:01:25', 'British Airways', ['transaction_status' => 'pending', 'booking_date' => '2026-09-25']);
        $this->line('payment_to', 30_000, '2026-10-06 09:00:00', 'Annual fee', ['booking_date' => '2026-10-06', 'value_date' => '2026-10-07']);
        $this->line('payment_to', 174_495, '2026-09-15 12:00:00', 'Other purchases', ['booking_date' => '2026-09-15', 'value_date' => '2026-09-16']);
        // After the cut-off
        $this->line('payment_to', 395, '2026-10-07 11:42:51', 'Sainsburys', ['booking_date' => '2026-10-07', 'value_date' => '2026-10-08']);
        $this->line('payment_to', 1_646, '2026-10-07 16:13:13', 'Flight Club', ['booking_date' => '2026-10-07', 'value_date' => '2026-10-08']);
        $this->line('payment_to', 30_085, '2026-10-12 12:00:00', 'Later', ['booking_date' => '2026-10-12', 'value_date' => '2026-10-13']);

        if ($merge) {
            $this->artisan('gocardless:reconcile-transactions', ['--user' => $this->user->id, '--apply' => true])->assertSuccessful();
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $raw
     */
    private function line(string $action, int $pence, string $time, string $merchant, array $meta = [], array $raw = [], ?string $description = null): Event
    {
        $target = EventObject::firstOrCreate(
            ['user_id' => $this->user->id, 'concept' => 'counterparty', 'type' => 'monzo_counterparty', 'title' => $merchant],
            ['time' => now(), 'metadata' => []],
        );

        return Event::create([
            'integration_id' => $this->integration->id, 'source_id' => 'a-' . (++$this->counter),
            'actor_id' => $this->card->id, 'target_id' => $target->id, 'service' => 'gocardless', 'domain' => 'money',
            'action' => $action, 'time' => $time, 'value' => $pence, 'value_multiplier' => 100, 'value_unit' => 'GBP',
            'event_metadata' => [...['description' => $description ?? $merchant, 'transaction_status' => 'booked', 'raw' => ['creditorName' => $merchant, ...$raw]], ...$meta],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function cover(): array
    {
        return app(CardCover::class)->build($this->user->id, CarbonImmutable::parse('2026-10-13 12:00:00'));
    }

    private function spend()
    {
        return app(SpendingScope::class)->entries($this->user->id, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-13'));
    }
}
