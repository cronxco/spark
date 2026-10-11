<?php

namespace App\Services\Money;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Carbon\CarbonImmutable;
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
 * @phpstan-type Row array{pot_id: string, pot: string, month: string, paid_on: string, amount: int, method: string, estimated: bool, opening: int, closing: int, transfers: int, implied_aer: float, state: string}
 */
class PotInterestInference
{
    /** Anything above this annualised rate is treated as missing transfers, not interest. */
    public const MAX_ANNUAL_RATE = 0.08;

    /**
     * @return array{rows: list<array<string, mixed>>, flagged: list<array<string, mixed>>}
     */
    public function plan(string $userId, ?CarbonImmutable $since = null): array
    {
        $rows = [];
        $flagged = [];
        $pots = EventObject::where('user_id', $userId)->where('concept', 'account')
            ->whereIn('type', ['monzo_pot', 'monzo_archived_pot'])->get();

        foreach ($pots as $pot) {
            [$potRows, $potFlagged] = $this->inferPot($userId, $pot, $since);
            array_push($rows, ...$potRows);
            array_push($flagged, ...$potFlagged);
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
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function inferPot(string $userId, EventObject $pot, ?CarbonImmutable $since): array
    {
        $snapshots = Event::where('actor_id', $pot->id)->where('action', 'had_balance')
            ->orderBy('time')->get(['id', 'time', 'value', 'created_at'])
            ->groupBy(fn ($event) => $event->time->toDateString())
            ->map(fn (Collection $day) => $day->last())
            ->values();

        if ($snapshots->count() < 2) {
            return [[], []];
        }

        $transfers = $this->transfers($pot);
        $existing = Event::where('action', 'interest_earned')->where('actor_id', $pot->id)->pluck('value', 'source_id');

        $rows = [];
        $flagged = [];
        for ($i = 1; $i < $snapshots->count(); $i++) {
            $prev = $snapshots[$i - 1];
            $cur = $snapshots[$i];
            $boundaries = $this->boundaries($prev->time, $cur->time);
            if ($boundaries === [] || ($since && $cur->time->lt($since))) {
                continue;
            }

            $opening = (int) $prev->value;
            $closing = (int) $cur->value;
            $net = $this->netTransfers($transfers, $prev->created_at, $cur->created_at);
            $residual = $closing - $opening - $net;
            $label = $pot->title.' ('.$prev->time->toDateString().' to '.$cur->time->toDateString().')';

            if ($residual <= 0 || $opening <= 0) {
                if ($residual < 0 || ($residual > 0 && $opening <= 0)) {
                    $flagged[] = ['pot' => $pot->title, 'window' => $label, 'residual' => $residual, 'reason' => $residual < 0 ? 'Balance fell by more than the transfers explain' : 'Balance appeared with no opening balance'];
                }

                continue;
            }
            $months = count($boundaries);
            if ($residual > $opening * self::MAX_ANNUAL_RATE * $months / 12) {
                $flagged[] = ['pot' => $pot->title, 'window' => $label, 'residual' => $residual, 'reason' => 'Rise is too large to be interest; a transfer is probably missing'];

                continue;
            }

            foreach ($this->split($residual, $boundaries) as $paidOn => $amount) {
                $month = substr($paidOn, 0, 7);
                $sourceId = $this->sourceId($pot, $month);
                $rows[] = [
                    'pot_id' => (string) $pot->id,
                    'pot' => $pot->title,
                    'month' => $month,
                    'paid_on' => $paidOn,
                    'amount' => $amount,
                    'method' => $months === 1 ? 'balance_delta' : 'gap_prorata',
                    'estimated' => $months > 1,
                    'opening' => $opening,
                    'closing' => $closing,
                    'transfers' => $net,
                    'implied_aer' => round($amount / $opening * 12, 4),
                    'source_id' => $sourceId,
                    'state' => ($existing[$sourceId] ?? null) === $amount ? 'unchanged' : (isset($existing[$sourceId]) ? 'changed' : 'new'),
                ];
            }
        }

        return [$rows, $flagged];
    }

    /**
     * The 1st-of-month dates covered by a pair of snapshots (after the first, up to and including the second).
     *
     * @return list<CarbonImmutable>
     */
    private function boundaries(\DateTimeInterface $from, \DateTimeInterface $to): array
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
     * @return list<array{0: \DateTimeInterface, 1: int}>
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
     * @param  list<array{0: \DateTimeInterface, 1: int}>  $transfers
     */
    private function netTransfers(array $transfers, \DateTimeInterface $after, \DateTimeInterface $until): int
    {
        $net = 0;
        foreach ($transfers as [$time, $amount]) {
            if ($time > $after && $time <= $until) {
                $net += $amount;
            }
        }

        return $net;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function write(Integration $integration, array $row): void
    {
        $day = EventObject::firstOrCreate(
            ['user_id' => $integration->user_id, 'concept' => 'day', 'type' => 'day', 'title' => $row['paid_on']],
            ['time' => $row['paid_on'].' 00:00:00', 'content' => null, 'metadata' => []],
        );

        $event = Event::updateOrCreate(
            ['integration_id' => $integration->id, 'source_id' => $row['source_id']],
            [
                'time' => $row['paid_on'].' 00:00:00',
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
        return 'monzo_pot_interest_'.($pot->metadata['pot_id'] ?? $pot->id).'_'.$month;
    }

    private function integration(string $userId): Integration
    {
        return Integration::where('user_id', $userId)->where('service', 'monzo')
            ->orderByRaw("case when instance_type = 'accounts' then 0 else 1 end")->firstOrFail();
    }
}
