<?php

namespace App\Services\Money;

use App\Models\Event;
use App\Models\EventObject;
use App\Support\MerchantName;
use Carbon\CarbonImmutable;

/**
 * Read-only forecast of the direct debits still to leave the current accounts before the next payday.
 *
 * A bill is a payee whose direct debits landed in at least two different recent months. Its next
 * due date is the month after the latest one, on the same day. Payday is the next occurrence of
 * the day of month the last salary arrived on, or the last day of the month when no salary is known.
 * A direct debit that pays off one of the user's credit cards is left out of the bills and listed in
 * `card_payments`: card cover already counts what the card owes, so counting the payment as well
 * would take the same money off twice. Amounts are integer pence.
 */
class UpcomingBills
{
    private const LOOKBACK_DAYS = 130;

    private static function fold(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($name));
    }

    /**
     * @return array{payday: string, payday_known: bool, total: int, bills: list<array{payee: string, amount: int, due: string}>, card_payments: list<array{payee: string, amount: int, due: string}>}
     */
    public function build(string $userId, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->startOfDay();
        [$payday, $paydayKnown] = $this->payday($userId, $now);

        $debits = Event::where('action', 'direct_debit_to')
            ->whereHas('integration', fn ($query) => $query->where('user_id', $userId))
            ->where('time', '>=', $now->subDays(self::LOOKBACK_DAYS))
            ->with('target')->orderBy('time')->get();

        $cardNames = $this->cardNames($userId);
        $bills = [];
        $cardPayments = [];
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
            $bill = [
                'payee' => MerchantName::display((string) ($latest->target?->title ?? 'Direct debit')),
                'amount' => abs((int) $latest->value),
                'due' => $due->toDateString(),
            ];
            if ($this->paysCard($bill['payee'], $cardNames)) {
                $cardPayments[] = $bill;
            } else {
                $bills[] = $bill;
            }
        }
        $byDue = fn (array $a, array $b): int => $a['due'] <=> $b['due'];
        usort($bills, $byDue);
        usort($cardPayments, $byDue);

        return [
            'payday' => $payday->toDateString(),
            'payday_known' => $paydayKnown,
            'total' => (int) array_sum(array_column($bills, 'amount')),
            'bills' => $bills,
            'card_payments' => $cardPayments,
        ];
    }

    /**
     * The issuer and name of every credit card, folded for comparison.
     *
     * @return list<string>
     */
    private function cardNames(string $userId): array
    {
        return EventObject::where('user_id', $userId)->where('concept', 'account')->where('type', 'bank_account')->get()
            ->filter(fn (EventObject $account): bool => ($account->metadata['account_type'] ?? null) === 'credit_card')
            ->flatMap(fn (EventObject $card): array => [$card->metadata['provider'] ?? null, $card->title])
            ->map(fn (mixed $name): string => self::fold((string) $name))
            ->filter(fn (string $name): bool => strlen($name) >= 4)
            ->unique()->values()->all();
    }

    /**
     * @param  list<string>  $cardNames
     */
    private function paysCard(string $payee, array $cardNames): bool
    {
        $payee = self::fold($payee);
        if ($payee === '') {
            return false;
        }
        foreach ($cardNames as $name) {
            if (str_contains($payee, $name) || str_contains($name, $payee)) {
                return true;
            }
        }

        return false;
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
