<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Money\AccountCleanup;
use App\Services\Money\GoCardlessTransactionDeduper;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;

class ReconcileGoCardlessTransactions extends Command
{
    protected $signature = 'gocardless:reconcile-transactions {--user= : Required owner UUID} {--apply : Merge the duplicates listed; default is read-only}';

    protected $description = 'Audit GoCardless transactions stored twice and pending authorisations that booked lines already settle';

    public function handle(AccountCleanup $cleanup, GoCardlessTransactionDeduper $deduper): int
    {
        $userId = $this->option('user');
        if (! $userId || ! User::whereKey($userId)->exists()) {
            $this->error('Provide a valid --user UUID. No records were changed.');

            return self::FAILURE;
        }

        $plan = $cleanup->plan($userId, AccountCleanup::TRANSACTION_KINDS);
        $excess = 0;
        foreach ($plan['steps'] as $step) {
            $this->line($step['label'] . ($step['blocked'] ? " [blocked: {$step['blocked']}]" : '') . '.');
        }
        foreach ($deduper->groups($userId) as $group) {
            $excess += $group['amount'] * count($group['duplicate_ids']);
        }
        if ($plan['steps'] === []) {
            $this->line('No duplicate transactions.');
        } else {
            $this->line(sprintf('%d groups, £%s counted more than once.', count($plan['steps']), number_format($excess / 100, 2)));
        }

        $overlaps = $deduper->pendingOverlaps($userId, CarbonImmutable::now());
        foreach ($overlaps as $overlap) {
            $this->line(sprintf(
                'Pending %s %s on %s: £%s authorised, £%s still pending after booked lines. Counted at query time; no change needed.',
                $overlap['account'], $overlap['merchant'], $overlap['date'],
                number_format($overlap['authorised'] / 100, 2), number_format($overlap['remaining'] / 100, 2),
            ));
        }

        if (! $this->option('apply')) {
            $this->info('Dry run only. Review this before repeating with --apply.');

            return self::SUCCESS;
        }

        try {
            $result = $cleanup->apply($userId, $plan['fingerprint'], AccountCleanup::TRANSACTION_KINDS);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage() . ' No repair was committed.');

            return self::FAILURE;
        }
        foreach ($result['skipped'] as $step) {
            $this->warn("Skipped: {$step['label']}");
        }
        $this->info(sprintf('Merged %d groups.', count($result['applied'])));

        return self::SUCCESS;
    }
}
