<?php

namespace App\Console\Commands;

use App\Jobs\Flint\TriggerFlintDigestRoutineJob;
use App\Jobs\Flint\TriggerFlintRoutineJob;
use App\Models\User;
use App\Services\EffectiveTimezoneResolver;
use App\Services\Flint\RoutineConfig;
use App\Services\Flint\Routines\RoutineDriverManager;
use Illuminate\Console\Command;

/**
 * Runs one Flint routine now, bypassing its daily slot.
 *
 * The debugging entry point for the skill runner, and the way to compare the
 * webhook and openai drivers on identical input.
 */
class RunFlintSkill extends Command
{
    protected $signature = 'flint:run-skill
                            {routine : digest, topics, reading_list or news_roundup}
                            {--user= : User id or email (defaults to the only user)}
                            {--date= : Local date to run for (Y-m-d, defaults to today)}
                            {--period=morning : Digest period when routine is digest}';

    protected $description = 'Run a Flint routine on demand through its configured driver';

    public function handle(EffectiveTimezoneResolver $timezones, RoutineDriverManager $drivers): int
    {
        $routine = $this->argument('routine');

        if (! RoutineConfig::isKnown($routine)) {
            $this->error("Unknown routine '{$routine}'. Known: " . implode(', ', RoutineConfig::ROUTINES));

            return Command::FAILURE;
        }

        $user = $this->resolveUser();

        if (! $user) {
            return Command::FAILURE;
        }

        $timezone = $timezones->timezoneFor($user);
        $date = $this->option('date') ?: $timezones->today($user)->toDateString();

        $this->info("Running {$routine} for {$user->email} on {$date} via the {$drivers->driverName($routine)} driver...");

        $job = $routine === 'digest'
            ? new TriggerFlintDigestRoutineJob($user, $this->option('period'), $date, $timezone, 'manual', null, true)
            : new TriggerFlintRoutineJob($user, $routine, $date, $timezone, true);

        dispatch_sync($job);

        $this->info('Done. Check /admin/task-pipeline for the recorded execution.');

        return Command::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $identifier = $this->option('user');

        if ($identifier) {
            $user = User::query()
                ->when(
                    preg_match('/^[0-9a-f-]{36}$/i', $identifier),
                    fn ($query) => $query->where('id', $identifier),
                    fn ($query) => $query->where('email', $identifier),
                )
                ->first();

            if (! $user) {
                $this->error("No user matches '{$identifier}'.");
            }

            return $user;
        }

        if (User::query()->count() === 1) {
            return User::query()->first();
        }

        $this->error('More than one user exists; pass --user.');

        return null;
    }
}
