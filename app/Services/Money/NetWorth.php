<?php

namespace App\Services\Money;

use App\Integrations\Financial\FinancialPlugin;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\User;
use Carbon\Carbon;

/**
 * Net worth and its change over a window, shared by the web Worth view and the mobile API.
 *
 * Only accounts in the primary currency (GBP) are summed. `total` counts every such account with a
 * balance. The comparison only uses accounts whose history reaches back to the window start, so a
 * brand-new account is not read as growth; the rest are counted in `excluded_accounts`.
 */
class NetWorth
{
    public const WINDOWS = [
        '1week' => 7,
        '1month' => 30,
        '3months' => 90,
        '6months' => 180,
        '1year' => 365,
    ];

    public function __construct(private FinancialPlugin $financial) {}

    /**
     * @return array{
     *     total: float,
     *     currency: string,
     *     comparison: array{window: string, then: float, change: float, change_pct: float|null},
     *     excluded_accounts: int,
     *     accounts: list<array{id: string, name: string, type: string|null, balance: float, debt: bool}>
     * }
     */
    public function build(User $user, string $window = '1month'): array
    {
        $window = array_key_exists($window, self::WINDOWS) ? $window : '1month';
        $primaryCurrency = 'GBP';

        $accounts = $this->financial->getFinancialAccounts($user);
        $latestBalances = $this->financial->getLatestBalancesForAccounts($accounts);

        $sameCurrency = $accounts->filter(
            fn (EventObject $account) => ($account->metadata['currency'] ?? 'GBP') === $primaryCurrency
        );
        $excludedForCurrency = $accounts->count() - $sameCurrency->count();

        $compareAt = Carbon::now()->subDays(self::WINDOWS[$window]);

        $total = 0.0;
        $comparableTotal = 0.0;
        $then = 0.0;
        $excludedForHistory = 0;
        $rows = [];

        foreach ($sameCurrency as $account) {
            $latest = $latestBalances->get($account->id);

            if (! $latest) {
                continue;
            }

            $latestSigned = $this->signedBalance($account, $latest);
            $total += $latestSigned;
            $rows[] = [
                'id' => (string) $account->id,
                'name' => (string) $account->title,
                'type' => $account->metadata['account_type'] ?? null,
                'balance' => round($latestSigned, 2),
                'debt' => $latestSigned < 0,
            ];

            $historical = $this->balanceAsOf($account, $compareAt);

            if (! $historical) {
                $excludedForHistory++;

                continue;
            }

            $comparableTotal += $latestSigned;
            $then += $this->signedBalance($account, $historical);
        }

        $change = round($comparableTotal - $then, 2);
        usort($rows, fn (array $a, array $b): int => $b['balance'] <=> $a['balance']);

        return [
            'total' => round($total, 2),
            'currency' => $primaryCurrency,
            'comparison' => [
                'window' => $window,
                'then' => round($then, 2),
                'change' => $change,
                'change_pct' => $then != 0.0 ? round(($change / abs($then)) * 100, 2) : null,
            ],
            'excluded_accounts' => $excludedForCurrency + $excludedForHistory,
            'accounts' => $rows,
        ];
    }

    /**
     * The most recent balance event for this account at or before `$at`, or null when the account's
     * history doesn't reach back that far.
     */
    private function balanceAsOf(EventObject $account, Carbon $at): ?Event
    {
        return $this->financial->getBalanceEventsQuery($account)
            ->where('time', '<=', $at)
            ->orderByDesc('time')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * A balance event's value, signed so debt accounts (credit cards, loans, mortgages) subtract from
     * the total rather than add to it.
     */
    private function signedBalance(EventObject $account, Event $event): float
    {
        $balance = $this->resolveBalance($event);

        return (bool) ($account->metadata['is_negative_balance'] ?? false) ? -abs($balance) : $balance;
    }

    private function resolveBalance(Event $event): float
    {
        if (isset($event->event_metadata['balance'])) {
            return (float) $event->event_metadata['balance'];
        }

        if ($event->value !== null && $event->value_multiplier && $event->value_multiplier > 1) {
            return $event->value / $event->value_multiplier;
        }

        return (float) ($event->value ?? 0);
    }
}
