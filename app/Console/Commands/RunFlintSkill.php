<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Flint\FlintDryRunner;
use App\Services\Flint\FlintRunDispatcher;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Runs one Flint routine now, bypassing its daily slot.
 *
 * The debugging entry point for the skill runner, and the way to compare the
 * webhook and openai drivers on identical input. With --dry-run the skill reads
 * live data but every write is captured to a file instead of applied.
 */
class RunFlintSkill extends Command
{
    protected $signature = 'flint:run-skill
                            {skill : Canonical skill name or deprecated routine alias}
                            {--user= : User id or email (defaults to the only user)}
                            {--date= : Local date to run for (Y-m-d, defaults to today)}
                            {--period=morning : Digest period when routine is digest}
                            {--driver= : Run through this driver (webhook or openai) instead of the configured one}
                            {--dry-run : Capture the writes to storage/app/flint-dry-runs instead of applying them (openai only)}';

    protected $description = 'Run a Flint routine on demand through its configured driver';

    public function handle(FlintRunDispatcher $dispatcher): int
    {
        $user = $this->resolveUser();

        if (! $user) {
            return Command::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($user);
        }

        try {
            $result = $dispatcher->dispatch(
                $user,
                skill: $this->argument('skill'),
                date: $this->option('date'),
                period: $this->option('period'),
                sync: true,
                driverOverride: $this->option('driver'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->info("Completed {$result->skill} run {$result->runUuid} via {$result->driver}.");

        return Command::SUCCESS;
    }

    private function dryRun(User $user): int
    {
        try {
            ['path' => $path, 'record' => $record] = app(FlintDryRunner::class)->run(
                $user,
                skill: $this->argument('skill'),
                date: $this->option('date'),
                period: $this->option('period'),
                driver: $this->option('driver'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $writes = count($record['writes']);
        $this->info("Dry run of {$record['skill']} captured {$writes} write(s) to storage/app/{$path}.");

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
