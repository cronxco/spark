<?php

namespace Tests\Feature\Money;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\Money\PotInterestInference;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PotInterestInferenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    private EventObject $account;

    private EventObject $pot;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.enable_task_pipeline' => false]);
        $this->user = User::factory()->create();
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'monzo'])->id,
            'service' => 'monzo',
            'instance_type' => 'accounts',
        ]);
        $this->account = $this->object('Current', 'monzo_account');
        $this->pot = $this->object('Growth Fund', 'monzo_pot', ['pot_id' => 'pot_growth']);
    }

    #[Test]
    public function the_jump_on_the_first_is_inferred_as_interest(): void
    {
        $this->snapshot('2026-09-29', 10_000_000);
        $this->snapshot('2026-09-30', 10_000_000);
        $this->snapshot('2026-10-01', 10_029_200);
        $this->snapshot('2026-10-02', 10_029_200);

        $plan = $this->inference()->plan($this->user->id);

        $this->assertCount(1, $plan['rows']);
        $row = $plan['rows'][0];
        $this->assertSame(29_200, $row['amount']);
        $this->assertSame('2026-10-01', $row['paid_on']);
        $this->assertSame('balance_delta', $row['method']);
        $this->assertFalse($row['estimated']);
        $this->assertSame('new', $row['state']);
        $this->assertEqualsWithDelta(0.035, $row['implied_aer'], 0.001);
        $this->assertSame(0, Event::where('action', 'interest_earned')->count(), 'The plan must not write anything.');
    }

    #[Test]
    public function applying_records_one_tagged_event_and_is_idempotent(): void
    {
        $this->snapshot('2026-09-30', 10_000_000);
        $this->snapshot('2026-10-01', 10_029_200);

        $first = $this->inference()->apply($this->user->id);
        $second = $this->inference()->apply($this->user->id);

        $this->assertSame(['written' => 1, 'unchanged' => 0, 'flagged' => 0], $first);
        $this->assertSame(['written' => 0, 'unchanged' => 1, 'flagged' => 0], $second);

        $event = Event::where('action', 'interest_earned')->sole();
        $this->assertSame(29_200, (int) $event->value);
        $this->assertSame(100, (int) $event->value_multiplier);
        $this->assertSame((string) $this->pot->id, (string) $event->actor_id);
        $this->assertSame('monzo_pot_interest_pot_growth_2026-10', $event->source_id);
        $this->assertTrue($event->event_metadata['inferred']);
        $this->assertSame('balance_delta', $event->event_metadata['method']);
        $this->assertSame(10_000_000, $event->event_metadata['opening_balance']);
        $this->assertSame(['inferred'], $event->tagsWithType('provenance')->pluck('name')->all());
    }

    #[Test]
    public function a_changed_figure_updates_the_same_event(): void
    {
        $this->snapshot('2026-09-30', 10_000_000);
        $closing = $this->snapshot('2026-10-01', 10_029_200);
        $this->inference()->apply($this->user->id);

        $closing->update(['value' => 10_030_000]);
        $plan = $this->inference()->plan($this->user->id);
        $this->assertSame('changed', $plan['rows'][0]['state']);

        $this->inference()->apply($this->user->id);
        $this->assertSame(30_000, (int) Event::where('action', 'interest_earned')->sole()->value);
    }

    #[Test]
    public function transfers_between_the_two_snapshots_are_not_counted_as_interest(): void
    {
        $this->snapshot('2026-09-30', 10_000_000);
        $this->snapshot('2026-10-01', 10_000_000 + 500_000 + 29_200 - 100_000);
        $this->transferIn(500_000, '2026-09-30 15:00:00');
        $this->withdraw(100_000, '2026-09-30 18:00:00');

        $row = $this->inference()->plan($this->user->id)['rows'][0];

        $this->assertSame(29_200, $row['amount']);
        $this->assertSame(400_000, $row['transfers']);
    }

    #[Test]
    public function transfers_outside_the_window_are_ignored(): void
    {
        $this->snapshot('2026-09-30', 10_000_000);
        $this->snapshot('2026-10-01', 10_029_200);
        $this->transferIn(500_000, '2026-10-01 09:00:00');
        $this->transferIn(500_000, '2026-09-29 09:00:00');

        $this->assertSame(29_200, $this->inference()->plan($this->user->id)['rows'][0]['amount']);
    }

    #[Test]
    public function a_snapshot_gap_is_split_across_the_months_and_marked_estimated(): void
    {
        $this->snapshot('2025-12-31', 10_000_000);
        $this->snapshot('2026-03-28', 10_000_000 + 87_000);

        $rows = $this->inference()->plan($this->user->id)['rows'];

        $this->assertSame(['2026-01', '2026-02', '2026-03'], array_column($rows, 'month'));
        $this->assertSame(87_000, array_sum(array_column($rows, 'amount')));
        foreach ($rows as $row) {
            $this->assertTrue($row['estimated']);
            $this->assertSame('gap_prorata', $row['method']);
        }
        $this->assertGreaterThan($rows[2]['amount'], $rows[0]['amount'], 'Feb, which March pays for, has fewer days than December.');
    }

    #[Test]
    public function an_implausibly_large_rise_is_flagged_not_recorded(): void
    {
        $this->snapshot('2026-09-30', 1_000_000);
        $this->snapshot('2026-10-01', 1_500_000);

        $plan = $this->inference()->plan($this->user->id);

        $this->assertSame([], $plan['rows']);
        $this->assertCount(1, $plan['flagged']);
        $this->assertStringContainsString('transfer is probably missing', $plan['flagged'][0]['reason']);
    }

    #[Test]
    public function a_fall_that_transfers_do_not_explain_is_flagged(): void
    {
        $this->snapshot('2026-09-30', 1_000_000);
        $this->snapshot('2026-10-01', 900_000);

        $plan = $this->inference()->plan($this->user->id);

        $this->assertSame([], $plan['rows']);
        $this->assertCount(1, $plan['flagged']);
    }

    #[Test]
    public function an_empty_pot_earns_nothing(): void
    {
        $this->snapshot('2026-09-30', 0);
        $this->snapshot('2026-10-01', 0);

        $plan = $this->inference()->plan($this->user->id);

        $this->assertSame([], $plan['rows']);
        $this->assertSame([], $plan['flagged']);
    }

    #[Test]
    public function snapshot_pairs_that_do_not_cross_a_month_start_are_ignored(): void
    {
        $this->snapshot('2026-10-02', 1_000_000);
        $this->snapshot('2026-10-03', 1_000_500);

        $this->assertSame([], $this->inference()->plan($this->user->id)['rows']);
    }

    #[Test]
    public function since_limits_the_months_considered(): void
    {
        $this->snapshot('2026-08-31', 10_000_000);
        $this->snapshot('2026-09-01', 10_029_000);
        $this->snapshot('2026-09-30', 10_029_000);
        $this->snapshot('2026-10-01', 10_058_000);

        $all = $this->inference()->plan($this->user->id)['rows'];
        $recent = $this->inference()->plan($this->user->id, CarbonImmutable::parse('2026-10-01'))['rows'];

        $this->assertCount(2, $all);
        $this->assertSame(['2026-10'], array_column($recent, 'month'));
    }

    #[Test]
    public function archived_pots_and_other_users_are_handled_separately(): void
    {
        $archived = $this->object('Old pot', 'monzo_archived_pot', ['pot_id' => 'pot_old']);
        $this->snapshot('2026-09-30', 500_000, $archived);
        $this->snapshot('2026-10-01', 501_460, $archived);

        $stranger = User::factory()->create();
        EventObject::create(['user_id' => $stranger->id, 'concept' => 'account', 'type' => 'monzo_pot', 'title' => 'Theirs', 'metadata' => ['pot_id' => 'x']]);

        $rows = $this->inference()->plan($this->user->id)['rows'];

        $this->assertSame(['Old pot'], array_column($rows, 'pot'));
        $this->assertSame([], $this->inference()->plan($stranger->id)['rows']);
    }

    #[Test]
    public function the_command_is_read_only_until_applied(): void
    {
        $this->snapshot('2026-09-30', 10_000_000);
        $this->snapshot('2026-10-01', 10_029_200);

        $this->artisan('monzo:infer-interest', ['--user' => $this->user->id])
            ->expectsOutputToContain('Total inferred: £292.00')
            ->expectsOutputToContain('Dry run only')
            ->assertSuccessful();
        $this->assertSame(0, Event::where('action', 'interest_earned')->count());

        $this->artisan('monzo:infer-interest', ['--user' => $this->user->id, '--apply' => true])
            ->expectsOutputToContain('Wrote 1 events')
            ->assertSuccessful();
        $this->assertSame(1, Event::where('action', 'interest_earned')->count());
    }

    private function inference(): PotInterestInference
    {
        return app(PotInterestInference::class);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function object(string $title, string $type, array $metadata = []): EventObject
    {
        return EventObject::create([
            'user_id' => $this->user->id, 'concept' => 'account', 'type' => $type, 'title' => $title, 'metadata' => $metadata,
        ]);
    }

    /** A daily snapshot, fetched shortly after midnight like the real sync. */
    private function snapshot(string $date, int $pence, ?EventObject $pot = null): Event
    {
        $day = EventObject::firstOrCreate(
            ['user_id' => $this->user->id, 'concept' => 'day', 'type' => 'day', 'title' => $date],
            ['time' => $date.' 00:00:00', 'metadata' => []],
        );
        $event = Event::create([
            'integration_id' => $this->integration->id, 'source_id' => 'snap-'.(++$this->counter),
            'actor_id' => ($pot ?? $this->pot)->id, 'target_id' => $day->id, 'service' => 'monzo', 'domain' => 'money',
            'action' => 'had_balance', 'time' => $date.' 23:59:59', 'value' => $pence, 'value_multiplier' => 100, 'value_unit' => 'GBP',
        ]);
        $event->forceFill(['created_at' => $date.' 00:26:00'])->saveQuietly();

        return $event;
    }

    private function transferIn(int $pence, string $time): void
    {
        $this->movement('pot_transfer_to', $this->account, $this->pot, $pence, $time);
    }

    private function withdraw(int $pence, string $time): void
    {
        $this->movement('pot_withdrawal_to', $this->pot, $this->account, $pence, $time);
    }

    private function movement(string $action, EventObject $actor, EventObject $target, int $pence, string $time): void
    {
        Event::create([
            'integration_id' => $this->integration->id, 'source_id' => 'move-'.(++$this->counter), 'actor_id' => $actor->id,
            'target_id' => $target->id, 'service' => 'monzo', 'domain' => 'money', 'action' => $action, 'time' => $time,
            'value' => $pence, 'value_multiplier' => 100, 'value_unit' => 'GBP',
        ]);
    }
}
