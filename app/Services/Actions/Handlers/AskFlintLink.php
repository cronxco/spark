<?php

namespace App\Services\Actions\Handlers;

use App\Models\User;
use App\Services\Actions\ActionFailed;
use App\Services\Actions\ActionHandler;
use App\Services\Actions\ActionOutcome;
use App\Services\Actions\Reversible;
use App\Services\Api\EntityMutationService;

/**
 * Undo for a relationship Ask Flint created. The agent's link tool creates
 * it and records the receipt, so the registry never runs this.
 */
class AskFlintLink implements ActionHandler, Reversible
{
    public function __construct(private EntityMutationService $mutations) {}

    public function handle(User $user, array $target): ActionOutcome
    {
        throw new ActionFailed('Ask Flint creates this link itself.', 'not_runnable');
    }

    public function undo(User $user, array $undo): void
    {
        $this->mutations->deleteRelationship($user, $undo['relationship_id']);
    }
}
