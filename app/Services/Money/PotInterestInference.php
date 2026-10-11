<?php

namespace App\Services\Money;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Works out the interest Monzo pays into savings pots from the daily balance snapshots.
 *
 * Monzo never reports pot interest as a transaction. It lands just after midnight on the 1st, so the
 * snapshot taken that morning is higher than the previous day's by the interest plus any pot transfers
 * made in between. Everything left over once those transfers are removed is interest.
 *
 * Nothing is written unless `apply` is true; the default is a read-only plan.
 *
 * @phpstan-type Row array{pot_id: string, pot: string, month: string, paid_on: string, amount: int, method: string, estimated: bool, opening: int, closing: int, transfers: int, first_day_transfers: int, implied_aer: float, state: string}
 */
class PotInterestInference
{
    /** Anything above this annualised rate is treated as missing transfers, not interest. */
    public const MAX_ANNUAL_RATE = 0.08;

    /** Used to pick between two readings of a 1st-of-month top-up when nothing better is known. */
    public const FALLBACK_ANNUAL_RATE = 0.035;

    /**
     * @return array{rows: list<array<string, mixed>>, flagged: list<array<string, mixed>>}
     */
    public function plan(string $userId, ?CarbonImmutable $since = null): array
    {
        $rows = [];
        $flagged = [];
        $pending = [];
        $pots = EventObject::where('user_id', $userId)->where('concept', 'account')
            ->whereIn('type', ['monzo_pot', 'monzo_archived_pot'])->get();

        foreach ($pots as $pot) {
            [$potRows, $potFlagged, $potPending] = $this->inferPot($pot, $since);
            array_push($rows, ...$potRows);
            array_push($flagged, ...$potFlagged);
            array_push($pending, ...$potPending);
        }

        $global = $this->median(array_column(array_filter($rows, fn ($row) => ! $row['estimated']), 'implied_aer')) ?? self::FALLBACK_ANNUAL_RATE;
        $potRates = collect($rows)->where('estimated', false)->groupBy('pot_id')
            ->map(fn ($potRows) => $this->median($potRows->pluck('implied_aer')->all()));

        foreach ($pending as $item) {
            $result = $this->resolve($item, $potRates->get($item['pot']->id) ?? $global);
            array_push($rows, ...($result['rows'] ?? []));
            if (isset($result['flag'])) {
                $flagged[] = $result['flag'];
            }
        }

        usort($rows, fn ($a, $b) => [$a['paid_on'], $a['pot']] <=> [$b['paid_on'], $b['pot']]);

        return ['rows' => $rows, 'flagged' => $flagged];
    }

    /**
     * Writes one `interest_earned` event per pot per month. Safe to repeat.
     *
     * @return array{written: int, unchanged: int, flagged: int}
     */
    public function apply(string $userId, ?CarbonImmutable $since = null): array
    {
        $integration = $this->integration($userId);
        $plan = $this->plan($userId, $since);
        $written = 0;
        $unchanged = 0;

        foreach ($plan['rows'] as $row) {
            if ($row['state'] === 'unchanged') {
                $unchanged++;

                continue;
            }
            $this->write($integration, $row);
            $written++;
        }

        return ['written' => $written, 'unchanged' => $unchanged, 'flagged' => count($plan['flagged'])];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function inferPot(EventObject $pot, ?CarbonImmutable $since): array
    {
        $snapshots = Event::where('actor_id', $pot->id)->where('action', 'had_balance')
            ->orderBy('time')->get(['id', 'time', 'value'])
            ->groupBy(fn ($event) => $event->time->toDateString())
            ->map(fn (Collection $day) => $day->last())
            ->values();

        if ($snapshots->count() < 2) {
            return [[], [], []];
        }

        $transfers = $this->transfers($pot);
        $existing = Event::where('action', 'interest_earned')->where('actor_id', $pot->id)->pluck('value', 'source_id')->all();

        $rows = [];
        $flagged = [];
        $pending = [];
        for ($i = 1; $i < $snapshots->count(); $i++) {
            $prev = $snapshots[$i - 1];
            $cur = $snapshots[$i];
            $boundaries = $this->boundaries($prev->time, $cur->time);
            if ($boundaries === [] || ($since && $cur->time->lt($since))) {
                continue;
            }

            $window = [
                'pot' => $pot,
                'existing' => $existing,
                'boundaries' => $boundaries,
                'opening' => (int) $prev->value,
                'closing' => (int) $cur->value,
                'net' => $this->netTransfers($transfers, $prev->time, $cur->time),
                'label' => $pot->title . ' (' . $prev->time->toDateString() . ' to ' . $cur->time->toDateString() . ')',
                'first_day' => 0,
            ];

            $firstDay = $this->firstDayTransfers($transfers, $cur->time);
            if (count($boundaries) === 1 && $boundaries[0]->toDateString() === $cur->time->toDateString() && $firstDay !== 0) {
                $pending[] = $window + ['first_day_net' => $firstDay];

                continue;
            }

            $result = $this->evaluate($window);
            array_push($rows, ...($result['rows'] ?? []));
            if (isset($result['flag'])) {
                $flagged[] = $result['flag'];
            }
        }

        return [$rows, $flagged, $pending];
    }

    /**
     * Turns one snapshot window into a row, a flag, or nothing (no interest to record).
     *
     * @param  array<string, mixed>  $window
     * @return array{rows?: list<array<string, mixed>>, flag?: array<string, mixed>}
     */
    private function evaluate(array $window): array
    {
        $pot = $window['pot'];
        $opening = $window['opening'];
        $closing = $window['closing'];
        $net = $window['net'];
        $boundaries = $window['boundaries'];
        $label = $window['label'];
        $residual = $closing - $opening - $net;

        if ($residual <= 0 || $opening <= 0) {
            if ($residual < 0 || ($residual > 0 && $opening <= 0)) {
                return ['flag' => ['pot' => $pot->title, 'window' => $label, 'residual' => $residual, 'reason' => $residual < 0 ? 'Balance fell by more than the transfers explain' : 'Balance appeared with no opening balance']];
            }

            return [];
        }
        $months = count($boundaries);
        if ($residual > $opening * self::MAX_ANNUAL_RATE * $months / 12) {
            return ['flag' => ['pot' => $pot->title, 'window' => $label, 'residual' => $residual, 'reason' => 'Rise is too large to be interest; a transfer is probably missing']];
        }

        $rows = [];
        foreach ($this->split($residual, $boundaries) as $paidOn => $amount) {
            $month = $this->interestFor($paidOn);
            $sourceId = $this->sourceId($pot, $month);
            $rows[] = [
                'pot_id' => (string) $pot->id,
                'pot' => $pot->title,
                'month' => $month,
                'paid_on' => $paidOn,
                'amount' => $amount,
                'method' => $window['first_day'] !== 0 ? 'balance_delta_first_day' : ($months === 1 ? 'balance_delta' : 'gap_prorata'),
                'estimated' => $months > 1,
                'opening' => $opening,
                'closing' => $closing,
                'transfers' => $net,
                'first_day_transfers' => $window['first_day'],
                'implied_aer' => round($amount / $opening * 12, 4),
                'source_id' => $sourceId,
                'state' => ($window['existing'][$sourceId] ?? null) === $amount ? 'unchanged' : (isset($window['existing'][$sourceId]) ? 'changed' : 'new'),
            ];
        }

        return ['rows' => $rows];
    }

    /**
     * A top-up dated on the 1st may or may not already be in that morning's snapshot: the sync runs just
     * after midnight, so a standing order that lands at 00:00 is in it, one that lands later is not. Both
     * readings are tried and the one that leaves a plausible interest figure closest to the pot's usual
     * rate wins. If neither is plausible the original (top-up not in the snapshot) reading is flagged.
     *
     * @param  array<string, mixed>  $item
     * @return array{rows?: list<array<string, mixed>>, flag?: array<string, mixed>}
     */
    private function resolve(array $item, float $reference): array
    {
        $later = $item;
        $included = $item;
        $included['net'] += $item['first_day_net'];
        $included['first_day'] = $item['first_day_net'];

        $candidates = array_values(array_filter([$later, $included], fn ($window) => $this->plausible($window)));
        if ($candidates === []) {
            return $this->evaluate($later);
        }

        usort($candidates, fn ($a, $b) => abs($this->aer($a) - $reference) <=> abs($this->aer($b) - $reference));

        return $this->evaluate($candidates[0]);
    }

    /**
     * @param  array<string, mixed>  $window
     */
    private function plausible(array $window): bool
    {
        $residual = $window['closing'] - $window['opening'] - $window['net'];

        return $window['opening'] > 0 && $residual >= 0 && $residual <= $window['opening'] * self::MAX_ANNUAL_RATE / 12;
    }

    /**
     * @param  array<string, mixed>  $window
     */
    private function aer(array $window): float
    {
        return ($window['closing'] - $window['opening'] - $window['net']) / $window['opening'] * 12;
    }

    /**
     * @param  list<float|int>  $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /** Monzo names a payment on the 1st after the month it is for: 1 October pays for September. */
    private function interestFor(string $paidOn): string
    {
        return CarbonImmutable::parse($paidOn)->subMonthNoOverflow()->format('Y-m');
    }

    /**
     * The 1st-of-month dates covered by a pair of snapshots (after the first, up to and including the second).
     *
     * @return list<CarbonImmutable>
     */
    private function boundaries(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        $dates = [];
        for ($date = $start->startOfMonth()->addMonth(); $date->lte($end); $date = $date->addMonth()) {
            $dates[] = $date;
        }

        return $dates;
    }

    /**
     * Splits a residual across month boundaries in proportion to the days each boundary's month covers.
     *
     * @param  list<CarbonImmutable>  $boundaries
     * @return array<string, int>
     */
    private function split(int $residual, array $boundaries): array
    {
        if (count($boundaries) === 1) {
            return [$boundaries[0]->toDateString() => $residual];
        }

        $weights = array_map(fn ($date) => $date->subMonth()->daysInMonth, $boundaries);
        $total = array_sum($weights);
        $out = [];
        $given = 0;
        foreach ($boundaries as $index => $date) {
            $share = $index === count($boundaries) - 1 ? $residual - $given : (int) round($residual * $weights[$index] / $total);
            $out[$date->toDateString()] = $share;
            $given += $share;
        }

        return $out;
    }

    /**
     * Pot movements as [time, signed pence from the pot's point of view].
     *
     * @return list<array{0: DateTimeInterface, 1: int}>
     */
    private function transfers(EventObject $pot): array
    {
        $in = Event::where('action', 'pot_transfer_to')->where('target_id', $pot->id)->get(['time', 'value']);
        $out = Event::where('action', 'pot_withdrawal_to')->where('actor_id', $pot->id)->get(['time', 'value']);

        return array_merge(
            $in->map(fn ($event) => [$event->time, (int) $event->value])->all(),
            $out->map(fn ($event) => [$event->time, -(int) $event->value])->all(),
        );
    }

    /**
     * A snapshot dated D is the balance from the daily sync, which runs shortly after midnight. Its
     * `time` is only the day label (23:59:59), and Spark's own `created_at` says when it was stored,
     * so neither gives the real moment. The interval therefore works in calendar days from the
     * source dates alone: a transfer belongs to it when its day is on or after the earlier
     * snapshot's day and before the later one's. Backfilled or late-imported snapshots give the
     * same answer as live ones.
     *
     * @param  list<array{0: DateTimeInterface, 1: int}>  $transfers
     */
    private function netTransfers(array $transfers, DateTimeInterface $from, DateTimeInterface $to): int
    {
        $fromDay = $from->format('Y-m-d');
        $toDay = $to->format('Y-m-d');
        $net = 0;
        foreach ($transfers as [$time, $amount]) {
            $day = $time->format('Y-m-d');
            if ($day >= $fromDay && $day < $toDay) {
                $net += $amount;
            }
        }

        return $net;
    }

    /**
     * Net pot movements dated on the later snapshot's own day: not in the window, but possibly in the balance.
     *
     * @param  list<array{0: DateTimeInterface, 1: int}>  $transfers
     */
    private function firstDayTransfers(array $transfers, DateTimeInterface $to): int
    {
        $day = $to->format('Y-m-d');

        return array_sum(array_map(fn ($transfer) => $transfer[0]->format('Y-m-d') === $day ? $transfer[1] : 0, $transfers));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function write(Integration $integration, array $row): void
    {
        $day = EventObject::firstOrCreate(
            ['user_id' => $integration->user_id, 'concept' => 'day', 'type' => 'day', 'title' => $row['paid_on']],
            ['time' => $row['paid_on'] . ' 00:00:00', 'content' => null, 'metadata' => []],
        );

        $event = Event::updateOrCreate(
            ['integration_id' => $integration->id, 'source_id' => $row['source_id']],
            [
                'time' => $row['paid_on'] . ' 00:00:00',
                'actor_id' => $row['pot_id'],
                'target_id' => $day->id,
                'service' => 'monzo',
                'domain' => 'money',
                'action' => 'interest_earned',
                'value' => $row['amount'],
                'value_multiplier' => 100,
                'value_unit' => 'GBP',
                'event_metadata' => [
                    'inferred' => true,
                    'method' => $row['method'],
                    'estimated' => $row['estimated'],
                    'month' => $row['month'],
                    'paid_on' => $row['paid_on'],
                    'first_day_transfers' => $row['first_day_transfers'],
                    'opening_balance' => $row['opening'],
                    'closing_balance' => $row['closing'],
                    'net_transfers' => $row['transfers'],
                    'implied_aer' => $row['implied_aer'],
                    'pot_name' => $row['pot'],
                ],
            ],
        );
        $event->syncTagsWithType(['inferred'], 'provenance');
    }

    private function sourceId(EventObject $pot, string $month): string
    {
        return 'monzo_pot_interest_' . ($pot->metadata['pot_id'] ?? $pot->id) . '_' . $month;
    }

    private function integration(string $userId): Integration
    {
        return Integration::where('user_id', $userId)->where('service', 'monzo')
            ->orderByRaw("case when instance_type = 'accounts' then 0 else 1 end")->firstOrFail();
    }
}
