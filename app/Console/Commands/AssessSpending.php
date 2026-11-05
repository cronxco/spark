<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Money\SpendingAssessor;
use Illuminate\Console\Command;

class AssessSpending extends Command
{
    protected $signature = 'money:assess-spending {--user= : Limit to one user id} {--days=30} {--limit=200}';

    protected $description = 'Ask Jev to classify ambiguous bank transactions (shadow mode records answers without acting on them)';

    public function handle(SpendingAssessor $assessor): int
    {
        if (! config('money.jev.enabled')) {
            $this->info('Jev spending assessment is disabled (MONEY_JEV_ENABLED).');

            return self::SUCCESS;
        }

        $users = $this->option('user') ? User::whereKey($this->option('user'))->get() : User::all();
        foreach ($users as $user) {
            $events = $assessor->candidates((string) $user->id, (int) $this->option('days'), (int) $this->option('limit'));
            $this->line("{$user->id}: assessed {$assessor->assess($events)} of {$events->count()}");
        }

        return self::SUCCESS;
    }
}
