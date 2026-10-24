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
 * Undo for a quick log reflection. The write happens in `POST /log`, which
 * records a receipt for it, so this action is never run from the registry
 * and is not offered on any surface.
 */
class LogReflection implements ActionHandler, Reversible
{
    public function __construct(private EventLookup $events) {}

    public function handle(User $user, array $target): ActionOutcome
    {
        throw new ActionFailed('A reflection is logged with the log endpoint.', 'not_runnable');
    }

    /**
     * Removes the reflection, its `with` links and any person it created. A
     * person who has other links since stays.
     */
    public function undo(User $user, array $undo): void
    {
        $event = $this->events->find($user, $undo['event_id']);

        if ($event === null) {
            return;
        }

        Relationship::query()
            ->where('user_id', $user->id)
            ->where('to_type', Event::class)
            ->where('to_id', $event->id)
            ->where('type', 'with')
            ->delete();

        $event->delete();

        EventObject::query()->where('user_id', $user->id)->whereKey($undo['object_id'])->delete();

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
