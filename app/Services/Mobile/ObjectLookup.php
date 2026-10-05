<?php

namespace App\Services\Mobile;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\User;
use App\Services\GoCardlessAccounts;
use Illuminate\Support\Collection;

class ObjectLookup
{
    public const EVENT_LIMIT_DEFAULT = 10;

    public const EVENT_LIMIT_MAX = 25;

    /**
     * Find a user-scoped EventObject. Returns null when absent or the UUID is malformed.
     */
    public function find(User $user, string $objectId): ?EventObject
    {
        if (! preg_match(EventLookup::UUID_REGEX, $objectId)) {
            return null;
        }

        $object = EventObject::withTrashed()
            ->where('user_id', $user->id)
            ->where('id', $objectId)
            ->with('tags')
            ->first();

        if ($object?->type === 'bank_account' && ($object->metadata['merged_into'] ?? null)) {
            return app(GoCardlessAccounts::class)->canonical($object)?->load('tags');
        }

        return $object && ! $object->trashed() ? $object : null;
    }

    /**
     * Find a soft-deleted EventObject owned by the user, for restoring it.
     */
    public function findTrashed(User $user, string $objectId): ?EventObject
    {
        if (! preg_match(EventLookup::UUID_REGEX, $objectId)) {
            return null;
        }

        return EventObject::onlyTrashed()
            ->whereNull('metadata->merged_into')
            ->where('user_id', $user->id)
            ->where('id', $objectId)
            ->first();
    }

    /**
     * Recent events where this object appears as actor or target, newest first.
     *
     * @return Collection<int, Event>
     */
    public function recentEvents(EventObject $object, User $user, int $limit = self::EVENT_LIMIT_DEFAULT): Collection
    {
        $limit = max(1, min($limit, self::EVENT_LIMIT_MAX));
        $integrationIds = $user->integrations()->pluck('id')->all();

        if (empty($integrationIds)) {
            return collect();
        }

        return Event::query()
            ->whereIn('integration_id', $integrationIds)
            ->where(function ($q) use ($object) {
                $q->where('actor_id', $object->id)
                    ->orWhere('target_id', $object->id);
            })
            ->with(['integration', 'actor', 'target', 'blocks', 'tags'])
            ->orderBy('time', 'desc')
            ->limit($limit)
            ->get();
    }
}
