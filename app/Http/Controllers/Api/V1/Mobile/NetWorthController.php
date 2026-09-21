<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Integrations\Financial\FinancialPlugin;
use App\Models\Event;
use App\Models\EventObject;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NetWorthController extends Controller
{
    private const WINDOWS = [
        '1week' => 7,
        '1month' => 30,
        '3months' => 90,
        '6months' => 180,
        '1year' => 365,
    ];

    public function __construct(protected FinancialPlugin $financial) {}

    /**
     * GET /api/v1/mobile/money/net-worth?compare=1month
     *
     * Net worth and its change over a window in one request. Without this,
     * the client fetches every account, then every account's balance
     * history, and reconciles both by hand — N+1 requests for two numbers,
     * with the reconciliation logic duplicated between the app and here.
     *
     * Only accounts in the primary currency (GBP) are summed; accounts in
     * another currency, and accounts whose balance history doesn't reach
     * back to the comparison window, are excluded from both sides of the
     * comparison and counted in `excluded_accounts`.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'compare' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::WINDOWS))],
        ]);

        $window = $validated['compare'] ?? '1month';
        $user = $request->user();
        $primaryCurrency = 'GBP';

        $accounts = $this->financial->getFinancialAccounts($user);
        $latestBalances = $this->financial->getLatestBalancesForAccounts($accounts);

        $sameCurrency = $accounts->filter(
            fn (EventObject $account) => ($account->metadata['currency'] ?? 'GBP') === $primaryCurrency
        );
        $excludedForCurrency = $accounts->count() - $sameCurrency->count();

        $compareAt = Carbon::now()->subDays(self::WINDOWS[$window]);

        // `total` is the real current net worth — every same-currency account
        // with a balance counts, new or old. The window comparison is a
        // narrower figure: only accounts whose history reaches back to
        // `compareAt` can say anything about *change*, so a brand-new account
        // (nothing then, something now) is excluded from the comparison
        // rather than read as growth.
        $total = 0.0;
        $comparableTotal = 0.0;
        $then = 0.0;
        $excludedForHistory = 0;

        foreach ($sameCurrency as $account) {
            $latest = $latestBalances->get($account->id);

            if (! $latest) {
                // No balance ever recorded for this account — nothing to sum
                // on either side, and not a "missing history" case since it
                // was never eligible to begin with.
                continue;
            }

            $latestSigned = $this->signedBalance($account, $latest);
            $total += $latestSigned;

            $historical = $this->balanceAsOf($account, $compareAt);

            if (! $historical) {
                $excludedForHistory++;

                continue;
            }

            $comparableTotal += $latestSigned;
            $then += $this->signedBalance($account, $historical);
        }

        $change = round($comparableTotal - $then, 2);
        $changePct = $then != 0.0 ? round(($change / abs($then)) * 100, 2) : null;

        return response()->json([
            'data' => [
                'total' => round($total, 2),
                'currency' => $primaryCurrency,
                'comparison' => [
                    'window' => $window,
                    'then' => round($then, 2),
                    'change' => $change,
                    'change_pct' => $changePct,
                ],
                'excluded_accounts' => $excludedForCurrency + $excludedForHistory,
                'as_of' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * The most recent balance event for this account at or before `$at`, or
     * null when the account's history doesn't reach back that far.
     */
    private function balanceAsOf(EventObject $account, Carbon $at): ?Event
    {
        return Event::where('actor_id', $account->id)
            ->whereIn('service', ['manual_account', 'monzo', 'gocardless'])
            ->where('action', 'had_balance')
            ->where('time', '<=', $at)
            ->orderByDesc('time')
            ->first();
    }

    /**
     * A balance event's value, signed so debt accounts (credit cards, loans,
     * mortgages) subtract from the total rather than add to it — mirrors
     * MoneyAccountResource::resolveBalance()'s value resolution.
     */
    private function signedBalance(EventObject $account, Event $event): float
    {
        $balance = $this->resolveBalance($event);
        $isNegativeBalance = (bool) ($account->metadata['is_negative_balance'] ?? false);

        return $isNegativeBalance ? -abs($balance) : $balance;
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
