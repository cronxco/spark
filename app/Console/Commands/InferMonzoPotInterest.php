<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\User;
use App\Services\Money\PotInterestInference;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class InferMonzoPotInterest extends Command
{
    protected $signature = 'monzo:infer-interest
        {--user= : Owner UUID; defaults to every user with Monzo}
        {--since= : Only consider snapshots on or after this date, e.g. "-45 days" or 2025-10-12}
        {--apply : Write the interest_earned events; default is a read-only plan}';

    protected $description = 'Infer Monzo pot interest from daily balance snapshots (read-only unless --apply)';

    public function handle(PotInterestInference $inference): int
    {
        $since = $this->option('since') ? CarbonImmutable::parse($this->option('since'))->startOfDay() : null;
        $userIds = $this->option('user')
            ? [$this->option('user')]
            : Integration::where('service', 'monzo')->distinct()->pluck('user_id')->all();

        foreach ($userIds as $userId) {
            if (! User::whereKey($userId)->exists()) {
                $this->error("Unknown user {$userId}. No records were changed.");

                return self::FAILURE;
            }
            $this->handleUser($inference, (string) $userId, $since);
        }

        return self::SUCCESS;
    }

    private function handleUser(PotInterestInference $inference, string $userId, ?CarbonImmutable $since): void
    {
        $plan = $inference->plan($userId, $since);
        $this->info("User {$userId}");

        $months = collect($plan['rows'])->groupBy('month')->map(fn ($rows) => [
            $rows->first()['month'],
            $rows->count(),
            number_format($rows->sum('amount') / 100, 2),
            $rows->where('estimated', true)->count(),
            $rows->whereIn('state', ['new', 'changed'])->count(),
        ])->values()->all();
        $this->table(['Month', 'Pots', 'Interest £', 'Estimated', 'To write'], $months);

        $total = collect($plan['rows'])->sum('amount') / 100;
        $this->line('Total inferred: £'.number_format($total, 2));
        foreach ($plan['flagged'] as $flag) {
            $this->warn("Flagged, not recorded: {$flag['window']} — {$flag['reason']} (residual £".number_format($flag['residual'] / 100, 2).')');
        }

        if (! $this->option('apply')) {
            $this->comment('Dry run only. Repeat with --apply to record these events.');

            return;
        }
        $result = $inference->apply($userId, $since);
        $this->info("Wrote {$result['written']} events, {$result['unchanged']} already up to date, {$result['flagged']} flagged.");
    }
}
