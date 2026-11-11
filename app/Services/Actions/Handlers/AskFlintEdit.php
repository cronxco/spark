<?php

namespace App\Services\Actions\Handlers;

use App\Models\User;
use App\Services\Actions\ActionFailed;
use App\Services\Actions\ActionHandler;
use App\Services\Actions\ActionOutcome;
use App\Services\Actions\Reversible;
use App\Services\Api\EntityMutationService;

/**
 * Undo for a field edit Ask Flint made. The edit happens in the agent's
 * update tool, which records a receipt holding the previous values, so this
 * action is never run from the registry and is not offered on any surface.
 */
class AskFlintEdit implements ActionHandler, Reversible
{
    public function __construct(private EntityMutationService $mutations) {}

    public function handle(User $user, array $target): ActionOutcome
    {
        throw new ActionFailed('Ask Flint makes this edit itself.', 'not_runnable');
    }

    /** Puts back the values the edit replaced. */
    public function undo(User $user, array $undo): void
    {
        $previous = (array) ($undo['previous'] ?? []);

        match ($undo['kind'] ?? null) {
            'event' => $this->mutations->updateEvent($user, $undo['id'], $previous),
            'object' => $this->mutations->updateObject($user, $undo['id'], $previous),
            'block' => $this->mutations->updateBlock($user, $undo['id'], $previous),
            default => null,
        };
    }
}
