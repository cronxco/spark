<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Flint\FlintRunDispatcher;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Runs one Flint routine now, bypassing its daily slot.
 *
 * The debugging entry point for the skill runner, and the way to compare the
 * webhook and openai drivers on identical input.
 */
class RunFlintSkill extends Command
{
    protected $signature = 'flint:run-skill
                            {skill : Canonical skill name or deprecated routine alias}
                            {--user= : User id or email (defaults to the only user)}
                            {--date= : Local date to run for (Y-m-d, defaults to today)}
                            {--period=morning : Digest period when routine is digest}';

    protected $description = 'Run a Flint routine on demand through its configured driver';

    public function handle(FlintRunDispatcher $dispatcher): int
    {
        $user = $this->resolveUser();

        if (! $user) {
            return Command::FAILURE;
        }

        try {
            $result = $dispatcher->dispatch(
                $user,
                skill: $this->argument('skill'),
                date: $this->option('date'),
                period: $this->option('period'),
                sync: true,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->info("Completed {$result->skill} run {$result->runUuid} via {$result->driver}.");

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
