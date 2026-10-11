<?php

namespace App\Services\Money;

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
        private SafeToSpend $safeToSpend,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $userId): array
    {
        $cover = $this->cover->build($userId);
        $overview = $this->overview->build($userId);

        return [
            'safe_to_spend' => $this->safeToSpend->build($userId, $cover['owed']),
            'cover' => $cover,
            'budgets' => $this->budgets->progress($userId),
            'strip' => $overview['strip'],
            'pots' => $overview['pots'],
            'interest' => array_slice($overview['ledger'], 0, 12),
            'buffer_enabled' => (bool) config('money.buffer.enabled'),
        ];
    }
}
