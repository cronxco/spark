<?php

namespace App\Services\Money;

use App\Integrations\Financial\FinancialPlugin;
use App\Models\EventObject;
use Illuminate\Support\Collection;

/**
 * What is safe to spend until payday: fresh current-account balances, less what the credit cards
 * owe, less the direct debits still due before payday. Amounts are integer pence.
 */
class SafeToSpend
{
    public function __construct(
        private FinancialPlugin $financial,
        private UpcomingBills $bills,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $userId, int $owedOnCards): array
    {
        $balance = $this->currentAccountBalance($userId);
        $bills = $this->bills->build($userId);

        return [
            'available' => $balance['available'],
            'owed_on_cards' => $owedOnCards,
            'upcoming_bills' => $bills['total'],
            'bills' => $bills['bills'],
            'payday' => $bills['payday'],
            'payday_known' => $bills['payday_known'],
            'stale_accounts' => $balance['stale'],
            'amount' => $balance['available'] - $owedOnCards - $bills['total'],
        ];
    }

    /**
     * Balances older than three days are left out of the total and named in `stale`.
     *
     * @return array{available: int, stale: list<string>}
     */
    private function currentAccountBalance(string $userId): array
    {
        $accounts = EventObject::where('user_id', $userId)->where('concept', 'account')
            ->whereIn('type', ['monzo_account', 'bank_account'])->get()
            ->reject(fn (EventObject $account): bool => in_array($account->metadata['account_type'] ?? null, ['credit_card', 'loan', 'savings'], true)
                || (bool) ($account->metadata['deleted'] ?? false));

        /** @var Collection<string, mixed> $balances */
        $balances = $this->financial->getLatestBalancesForAccounts($accounts);

        $available = 0;
        $stale = [];
        foreach ($accounts as $account) {
            $latest = $balances->get($account->id);
            if ($latest === null || $latest->time === null || $latest->time->lt(now()->subDays(3))) {
                $stale[] = (string) $account->title;

                continue;
            }
            $available += (int) $latest->value;
        }

        return ['available' => $available, 'stale' => $stale];
    }
}
