<?php

namespace App\Services\Actions\Handlers;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Person;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Actions\ActionFailed;
use App\Services\Actions\ActionHandler;
use App\Services\Actions\ActionOutcome;
use App\Services\Actions\Reversible;
use App\Services\Mobile\EventLookup;

/**
 * Undo for something Ask Flint logged. The agent's log tool writes the
 * events through Manual Log and records the receipt, so the registry never
 * runs this.
 */
class AskFlintLog implements ActionHandler, Reversible
{
    public function __construct(private EventLookup $events) {}

    public function handle(User $user, array $target): ActionOutcome
    {
        throw new ActionFailed('Ask Flint logs this itself.', 'not_runnable');
    }

    /**
     * Moves the logged events to the bin, with their `with` links, and
     * removes any person the log created who has no other link since. What
     * was logged (the wine, the series) stays: other events may share it.
     */
    public function undo(User $user, array $undo): void
    {
        foreach ($undo['event_ids'] ?? [] as $eventId) {
            $event = $this->events->find($user, (string) $eventId);

            if ($event === null) {
                continue;
            }

            Relationship::query()
                ->where('user_id', $user->id)
                ->where('to_type', Event::class)
                ->where('to_id', $event->id)
                ->where('type', 'with')
                ->delete();

            $event->delete();
        }

        foreach ($undo['created_person_ids'] ?? [] as $personId) {
            $person = Person::query()->where('user_id', $user->id)->whereKey($personId)->first();

            if ($person !== null && ! $this->isLinked($user, $person)) {
                $person->delete();
            }
        }
    }

    private function isLinked(User $user, Person $person): bool
    {
        return Relationship::query()
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query
                ->where(fn ($from) => $from->where('from_type', EventObject::class)->where('from_id', $person->id))
                ->orWhere(fn ($to) => $to->where('to_type', EventObject::class)->where('to_id', $person->id)))
            ->exists();
    }
}
