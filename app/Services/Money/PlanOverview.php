<?php

namespace App\Services\Money;

use App\Integrations\Financial\FinancialPlugin;
use App\Models\Event;
use App\Models\EventObject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Query-time figures behind the Money Plan tab. Nothing here is stored.
 *
 * Amounts are integer pence.
 */
class PlanOverview
{
    /** Higher-rate Income Tax on savings interest. Joint pots are not yet split with a partner. */
    public const HIGHER_RATE = 0.40;

    /**
     * @return array{
     *     strip: array<string, mixed>,
     *     pots: list<array<string, mixed>>,
     *     ledger: list<array<string, mixed>>
     * }
     */
    public function build(string $userId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $interest = Event::forUser($userId)->where('action', 'interest_earned')->where('service', 'monzo')->get();

        $ledger = $this->ledger($interest);
        $pots = $this->pots($userId, $interest, $ledger[0]['month'] ?? null);

        return ['strip' => $this->strip($pots, $ledger, $now), 'pots' => $pots, 'ledger' => $ledger];
    }

    /**
     * @param  Collection<int, Event>  $interest
     * @return list<array<string, mixed>>
     */
    private function ledger($interest): array
    {
        return $interest->groupBy(fn ($event) => substr($event->time->toDateString(), 0, 7))
            ->map(function ($events, $month) {
                return [
                    'month' => $month,
                    'total' => (int) $events->sum('value'),
                    'pots' => $events->count(),
                    'estimated' => $events->contains(fn ($event) => (bool) ($event->event_metadata['estimated'] ?? false)),
                    'lines' => $events->map(fn ($event) => [
                        'pot' => $event->event_metadata['pot_name'] ?? 'Pot',
                        'amount' => (int) $event->value,
                        'estimated' => (bool) ($event->event_metadata['estimated'] ?? false),
                        'aer' => (float) ($event->event_metadata['implied_aer'] ?? 0),
                    ])->sortByDesc('amount')->values()->all(),
                ];
            })
            ->sortByDesc('month')->values()->all();
    }

    /**
     * @param  Collection<int, Event>  $interest
     * @return list<array<string, mixed>>
     */
    private function pots(string $userId, $interest, ?string $latestMonth): array
    {
        $pots = EventObject::where('user_id', $userId)->where('concept', 'account')->where('type', 'monzo_pot')->get();
        $balances = (new FinancialPlugin)->getLatestBalancesForAccounts($pots);
        $latestInterest = $latestMonth === null ? collect() : $interest
            ->filter(fn ($event) => substr($event->time->toDateString(), 0, 7) === $latestMonth)
            ->keyBy('actor_id');

        $rows = $pots->map(function ($pot) use ($balances, $latestInterest) {
            $balance = (int) ($balances->get($pot->id)?->value ?? 0);
            $earned = $latestInterest->get($pot->id);

            return [
                'id' => (string) $pot->id,
                'name' => $pot->title,
                'balance' => $balance,
                'goal' => isset($pot->metadata['monzo']['goal_amount']) ? (int) $pot->metadata['monzo']['goal_amount'] : null,
                'interest' => $earned ? (int) $earned->value : 0,
                'aer' => $earned ? (float) ($earned->event_metadata['implied_aer'] ?? 0) : null,
            ];
        })->filter(fn ($row) => $row['balance'] > 0)->sortByDesc('balance')->values();

        $total = max(1, $rows->sum('balance'));

        return $rows->map(fn ($row) => $row + ['share' => round($row['balance'] / $total, 4)])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $pots
     * @param  list<array<string, mixed>>  $ledger
     * @return array<string, mixed>
     */
    private function strip(array $pots, array $ledger, CarbonImmutable $now): array
    {
        $latest = $ledger[0] ?? null;
        $twelve = collect($ledger)->take(12);
        $runRate = $latest ? $latest['total'] * 12 : 0;

        return [
            'in_pots' => (int) array_sum(array_column($pots, 'balance')),
            'latest_month' => $latest['month'] ?? null,
            'latest_interest' => $latest['total'] ?? 0,
            'run_rate' => $runRate,
            'twelve_months' => (int) $twelve->sum('total'),
            'months_recorded' => $twelve->count(),
            'tax_estimate' => (int) round($runRate * self::HIGHER_RATE),
            'as_of' => $now->toDateString(),
        ];
    }
}
