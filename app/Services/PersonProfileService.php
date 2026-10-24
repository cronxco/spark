<?php

namespace App\Services;

use App\Http\Resources\Compact\CompactEventResource;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Relationship;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds what a person's page shows: the events they appear in as actor or
 * target, are tagged on with a `with` relationship, or are @mentioned in a
 * note, and the events, objects and blocks they are connected to through
 * relationships. A person is an EventObject with the `person` concept.
 */
class PersonProfileService
{
    public const EVENT_LIMIT = 50;

    /**
     * The person's timeline, newest first: see `eventsQuery()` for what counts.
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
     * Event counts per local day for one month, keyed Y-m-d, for the calendar grid.
     *
     * @return array<string, int>
     */
    public function calendar(EventObject $person, Carbon $month, ?string $timezone = null): array
    {
        $timezone ??= config('app.timezone');
        $start = $month->copy()->setTimezone($timezone)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return $this->eventsQuery($person)
            ->whereBetween('time', [$start->copy()->utc(), $end->copy()->utc()])
            ->pluck('time')
            ->countBy(fn ($time): string => Carbon::parse($time)->setTimezone($timezone)->toDateString())
            ->sortKeys()
            ->all();
    }

    /**
     * Timeline events on one local day, newest first.
     *
     * @return Collection<int, Event>
     */
    public function eventsOn(EventObject $person, Carbon $day, ?string $timezone = null, int $limit = self::EVENT_LIMIT): Collection
    {
        $timezone ??= config('app.timezone');
        $start = $day->copy()->setTimezone($timezone)->startOfDay();

        return $this->eventsQuery($person)
            ->whereBetween('time', [$start->copy()->utc(), $start->copy()->endOfDay()->utc()])
            ->with(['actor', 'target', 'integration', 'tags'])
            ->orderByDesc('time')
            ->limit($limit)
            ->get();
    }

    /**
     * Events where the person is the actor or target, is tagged on with a `with`
     * relationship, or is @mentioned in the event's note. Mentions match the
     * person's full title at query time, so no extra data is stored.
     *
     * @return Builder<Event>
     */
    public function eventsQuery(EventObject $person): Builder
    {
        // PostgreSQL word boundaries prevent @Dan matching @Daniel or @Dan Smithson.
        $mention = '(^|[^[:alnum:]_])@' . preg_quote($person->title, '/') . '([^[:alnum:]_]|$)';

        return Event::query()
            ->whereHas('integration', fn (Builder $query) => $query->where('user_id', $person->user_id))
            ->where(function (Builder $query) use ($person, $mention): void {
                $query->where('actor_id', $person->id)
                    ->orWhere('target_id', $person->id)
                    ->orWhereIn('id', Relationship::query()
                        ->select('to_id')
                        ->where('user_id', $person->user_id)
                        ->where('type', 'with')
                        ->where('from_type', EventObject::class)
                        ->where('from_id', $person->id)
                        ->where('to_type', Event::class))
                    ->orWhereIn('id', Block::query()
                        ->select('event_id')
                        ->where('block_type', CompactEventResource::NOTE_BLOCK_TYPE)
                        ->whereNull('deleted_at')
                        ->whereRaw("metadata->>'content' ~* ?", [$mention]));
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
