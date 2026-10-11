<?php

namespace App\Services\Money;

use App\Integrations\Financial\FinancialPlugin;
use App\Models\EventObject;
use Illuminate\Support\Collection;

/**
 * The Plan tab's headline figures as one payload, shared by the mobile API and the Flint money tool
 * so both read exactly what the screen shows. All amounts are integer pence.
 */
class PlanSnapshot
{
    public function __construct(
        private PlanOverview $overview,
        private CardCover $cover,
        private BudgetService $budgets,
        private FinancialPlugin $financial,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $userId): array
    {
        $cover = $this->cover->build($userId);
        $overview = $this->overview->build($userId);
        $available = $this->currentAccountBalance($userId);

        return [
            'safe_to_spend' => [
                'available' => $available,
                'owed_on_cards' => $cover['owed'],
                'amount' => $available - $cover['owed'],
            ],
            'cover' => $cover,
            'budgets' => $this->budgets->progress($userId),
            'strip' => $overview['strip'],
            'pots' => $overview['pots'],
            'interest' => array_slice($overview['ledger'], 0, 12),
            'buffer_enabled' => (bool) config('money.buffer.enabled'),
        ];
    }

    private function currentAccountBalance(string $userId): int
    {
        $accounts = EventObject::where('user_id', $userId)->where('concept', 'account')
            ->whereIn('type', ['monzo_account', 'bank_account'])->get()
            ->reject(fn (EventObject $account): bool => in_array($account->metadata['account_type'] ?? null, ['credit_card', 'loan', 'savings'], true)
                || (bool) ($account->metadata['deleted'] ?? false));

        /** @var Collection<string, mixed> $balances */
        $balances = $this->financial->getLatestBalancesForAccounts($accounts);

        return (int) $accounts->sum(fn (EventObject $account): int => (int) ($balances->get($account->id)?->value ?? 0));
    }
}
