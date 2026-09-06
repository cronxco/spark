<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Tags\Tag;

/**
 * Tag catalogue restricted to one user.
 *
 * Spatie tags are global rows joined to models through `taggables`, so a tag
 * carries no `user_id` of its own. Ownership has to be derived by joining back
 * to the tagged records — a tag belongs to a user only insofar as that user
 * has an event or object carrying it, and its usage counts must be computed
 * over that user's records alone.
 *
 * This lived only inside the mobile TagsController while the web catalogue
 * counted taggables across every tenant. Both now share this builder.
 */
final class OwnedTagQuery
{
    /**
     * Tags the user actually uses, with per-user `events_count` and
     * `objects_count` selected.
     */
    public static function for(User $user, ?string $search = null): Builder
    {
        $integrationIds = $user->integrations()->pluck('id');

        $query = Tag::query()
            ->select('tags.*')
            ->selectSub(
                Event::query()
                    ->selectRaw('COUNT(*)')
                    ->join('taggables', function ($join) {
                        $join->on('taggables.taggable_id', '=', 'events.id')
                            ->where('taggables.taggable_type', Event::class);
                    })
                    ->whereColumn('taggables.tag_id', 'tags.id')
                    ->whereIn('events.integration_id', $integrationIds),
                'events_count',
            )
            ->selectSub(
                EventObject::query()
                    ->selectRaw('COUNT(*)')
                    ->join('taggables', function ($join) {
                        $join->on('taggables.taggable_id', '=', 'objects.id')
                            ->where('taggables.taggable_type', EventObject::class);
                    })
                    ->whereColumn('taggables.tag_id', 'tags.id')
                    ->where('objects.user_id', $user->id),
                'objects_count',
            )
            ->where(function (Builder $query) use ($user, $integrationIds) {
                $query->whereExists(function ($events) use ($integrationIds) {
                    $events->selectRaw('1')
                        ->from('taggables')
                        ->join('events', 'events.id', '=', 'taggables.taggable_id')
                        ->whereColumn('taggables.tag_id', 'tags.id')
                        ->where('taggables.taggable_type', Event::class)
                        ->whereIn('events.integration_id', $integrationIds);
                })->orWhereExists(function ($objects) use ($user) {
                    $objects->selectRaw('1')
                        ->from('taggables')
                        ->join('objects', 'objects.id', '=', 'taggables.taggable_id')
                        ->whereColumn('taggables.tag_id', 'tags.id')
                        ->where('taggables.taggable_type', EventObject::class)
                        ->where('objects.user_id', $user->id);
                });
            });

        $search = trim((string) $search);
        if ($search !== '') {
            $query->where(function (Builder $query) use ($search) {
                $query->where('name->en', 'ilike', '%' . $search . '%')
                    ->orWhere('type', 'ilike', '%' . $search . '%');
            });
        }

        return $query;
    }
}
