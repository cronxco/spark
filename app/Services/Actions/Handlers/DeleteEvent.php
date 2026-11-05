<?php

namespace App\Services\Actions\Handlers;

use App\Models\User;
use App\Services\Actions\ActionFailed;
use App\Services\Actions\ActionHandler;
use App\Services\Actions\ActionOutcome;
use App\Services\Actions\Reversible;
use App\Services\Mobile\EventLookup;

class DeleteEvent implements ActionHandler, Reversible
{
    public function __construct(private EventLookup $events) {}

    public function handle(User $user, array $target): ActionOutcome
    {
        $event = $this->events->find($user, $target['event_id']) ?? throw ActionFailed::notFound('Event');
        $event->delete();

        $result = ['event_id' => $event->id];

        return ActionOutcome::succeeded('Event deleted', $result, $result);
    }

    public function undo(User $user, array $undo): void
    {
        $this->events->findTrashed($user, $undo['event_id'])?->restore();
    }
}
