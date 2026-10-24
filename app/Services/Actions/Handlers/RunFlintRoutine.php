<?php

namespace App\Services\Actions\Handlers;

use App\Models\User;
use App\Services\Actions\ActionFailed;
use App\Services\Actions\ActionHandler;
use App\Services\Actions\ActionOutcome;
use App\Services\Flint\FlintRunDispatcher;
use InvalidArgumentException;

class RunFlintRoutine implements ActionHandler
{
    public function __construct(private FlintRunDispatcher $dispatcher) {}

    public function handle(User $user, array $target): ActionOutcome
    {
        try {
            $run = $this->dispatcher->dispatch($user, skill: $target['skill']);
        } catch (InvalidArgumentException $e) {
            throw new ActionFailed($e->getMessage(), 'invalid');
        }

        return ActionOutcome::accepted('Flint run queued', $run->toArray());
    }
}
