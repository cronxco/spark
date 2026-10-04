<?php

namespace App\Services;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Relationship;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Builds what a person's page shows: the events they appear in as actor or
 * target, and the events, objects and blocks they are connected to through
 * relationships. A person is an EventObject with the `person` concept.
 */
class PersonProfileService
{
    public const EVENT_LIMIT = 50;

    /**
     * Events where the person is the actor or the target, newest first.
     *
     * @return Collection<int, Event>
     */
    public function events(EventObject $person, int $limit = self::EVENT_LIMIT): Collection
    {
        return $this->eventsQuery($person)
            ->with(['actor', 'target', 'integration', 'tags'])
            ->orderByDesc('time')
            ->limit($limit)
            ->get();
    }

    public function eventCount(EventObject $person): int
    {
        return $this->eventsQuery($person)->count();
    }

    /**
     * The person's live relationships, grouped by relationship type. Each entry
     * carries the entity on the other side and whether the person is the source.
     *
     * @return Collection<string, Collection<int, array{relationship: Relationship, related: Model, outgoing: bool}>>
     */
    public function connections(EventObject $person): Collection
    {
        return Relationship::query()
            ->where('user_id', $person->user_id)
            ->where(function (Builder $query) use ($person): void {
                $query->where(function (Builder $from) use ($person): void {
                    $from->where('from_type', EventObject::class)->where('from_id', $person->id);
                })->orWhere(function (Builder $to) use ($person): void {
                    $to->where('to_type', EventObject::class)->where('to_id', $person->id);
                });
            })
            ->with(['from', 'to'])
            ->latest()
            ->get()
            ->map(function (Relationship $relationship) use ($person): ?array {
                $outgoing = $relationship->from_type === EventObject::class && $relationship->from_id === $person->id;
                $related = $outgoing ? $relationship->to : $relationship->from;

                if (! $this->isVisibleTo($related, $person)) {
                    return null;
                }

                return ['relationship' => $relationship, 'related' => $related, 'outgoing' => $outgoing];
            })
            ->filter()
            ->groupBy(fn (array $connection): string => $connection['relationship']->type);
    }

    /**
     * @return Builder<Event>
     */
    protected function eventsQuery(EventObject $person): Builder
    {
        return Event::query()
            ->whereHas('integration', fn (Builder $query) => $query->where('user_id', $person->user_id))
            ->where(function (Builder $query) use ($person): void {
                $query->where('actor_id', $person->id)->orWhere('target_id', $person->id);
            });
    }

    /**
     * A related entity is shown only when it still exists and belongs to the person's owner.
     */
    protected function isVisibleTo(?Model $related, EventObject $person): bool
    {
        return match (true) {
            $related instanceof EventObject => (string) $related->user_id === (string) $person->user_id,
            $related instanceof Event => (string) $related->integration?->user_id === (string) $person->user_id,
            $related instanceof Block => (string) $related->event?->integration?->user_id === (string) $person->user_id,
            default => false,
        };
    }
}
