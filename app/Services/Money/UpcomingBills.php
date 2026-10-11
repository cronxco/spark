<?php

namespace App\Services\Money;

use App\Models\Event;
use Carbon\CarbonImmutable;

/**
 * Read-only forecast of the direct debits still to leave the current accounts before the next payday.
 *
 * A bill is a payee whose direct debits landed in at least two different recent months. Its next
 * due date is the month after the latest one, on the same day. Payday is the next occurrence of
 * the day of month the last salary arrived on, or the last day of the month when no salary is known.
 * Amounts are integer pence.
 */
class UpcomingBills
{
    private const LOOKBACK_DAYS = 130;

    /**
     * @return array{payday: string, payday_known: bool, total: int, bills: list<array{payee: string, amount: int, due: string}>}
     */
    public function build(string $userId, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->startOfDay();
        [$payday, $paydayKnown] = $this->payday($userId, $now);

        $debits = Event::where('action', 'direct_debit_to')
            ->whereHas('integration', fn ($query) => $query->where('user_id', $userId))
            ->where('time', '>=', $now->subDays(self::LOOKBACK_DAYS))
            ->with('target')->orderBy('time')->get();

        $bills = [];
        foreach ($debits->groupBy('target_id') as $payments) {
            $months = $payments->map(fn (Event $event): string => $event->time->format('Y-m'))->unique();
            if ($months->count() < 2) {
                continue;
            }
            $latest = $payments->last();
            $due = $latest->time->toImmutable()->startOfDay()->addMonthNoOverflow();
            if ($due->lt($now) || $due->gt($payday)) {
                continue;
            }
            $bills[] = [
                'payee' => (string) ($latest->target?->title ?? 'Direct debit'),
                'amount' => abs((int) $latest->value),
                'due' => $due->toDateString(),
            ];
        }
        usort($bills, fn (array $a, array $b): int => $a['due'] <=> $b['due']);

        return [
            'payday' => $payday->toDateString(),
            'payday_known' => $paydayKnown,
            'total' => (int) array_sum(array_column($bills, 'amount')),
            'bills' => $bills,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: bool}
     */
    private function payday(string $userId, CarbonImmutable $now): array
    {
        $salary = Event::where('action', 'salary_received_from')
            ->whereHas('integration', fn ($query) => $query->where('user_id', $userId))
            ->orderByDesc('time')->first();

        if (! $salary) {
            return [$now->endOfMonth()->startOfDay(), false];
        }

        $day = $salary->time->day;
        $candidate = $now->startOfMonth()->addDays(min($day, $now->daysInMonth) - 1);
        if ($candidate->lt($now)) {
            $next = $now->startOfMonth()->addMonthNoOverflow();
            $candidate = $next->addDays(min($day, $next->daysInMonth) - 1);
        }

        return [$candidate, true];
    }
}
