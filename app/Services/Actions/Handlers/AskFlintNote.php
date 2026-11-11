<?php

namespace App\Services\Actions\Handlers;

use App\Models\User;
use App\Services\Actions\ActionFailed;
use App\Services\Actions\ActionHandler;
use App\Services\Actions\ActionOutcome;
use App\Services\Actions\Reversible;
use App\Services\EventNoteService;

/**
 * Undo for an event note Ask Flint set or cleared. The agent's note tool
 * writes the note and records the receipt, so the registry never runs this.
 */
class AskFlintNote implements ActionHandler, Reversible
{
    public function __construct(private EventNoteService $notes) {}

    public function handle(User $user, array $target): ActionOutcome
    {
        throw new ActionFailed('Ask Flint sets this note itself.', 'not_runnable');
    }

    /** Restores the note the event had before, or clears it if it had none. */
    public function undo(User $user, array $undo): void
    {
        $this->notes->set($user, $undo['event_id'], $undo['previous_note'] ?? null);
    }
}
