<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Money\AccountCleanup;
use Illuminate\Console\Command;
use RuntimeException;

class ReconcileGoCardlessAccounts extends Command
{
    protected $signature = 'gocardless:reconcile-accounts {--user= : Required owner UUID} {--apply : Apply the reviewed plan; default is read-only}';

    protected $description = 'Consolidate verified GoCardless account duplicates while preserving history and retired aliases';

    public function handle(AccountCleanup $cleanup): int
    {
        $userId = $this->option('user');
        if (! $userId || ! User::whereKey($userId)->exists()) {
            $this->error('Provide a valid --user UUID. No records were changed.');

            return self::FAILURE;
        }

        $plan = $cleanup->plan($userId, AccountCleanup::GOCARDLESS_KINDS);
        foreach ($plan['steps'] as $step) {
            $this->line($step['label'] . ($step['blocked'] ? " [blocked: {$step['blocked']}]" : '') . '.');
        }
        if ($plan['steps'] === []) {
            $this->line('Nothing to reconcile.');
        }

        $blocked = collect($plan['steps'])->first(fn ($step) => $step['blocked'] !== null);
        if ($blocked && $this->option('apply')) {
            $this->error("{$blocked['blocked']}; resolve before merging. No repair was committed.");

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->info('Dry run only. Review this plan before repeating with --apply.');

            return self::SUCCESS;
        }

        try {
            $result = $cleanup->apply($userId, $plan['fingerprint'], AccountCleanup::GOCARDLESS_KINDS);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage() . ' No repair was committed.');

            return self::FAILURE;
        }
        foreach ($result['skipped'] as $step) {
            $this->warn("Skipped: {$step['label']}");
        }
        $this->info('Reconciliation committed.');

        return self::SUCCESS;
    }
}
