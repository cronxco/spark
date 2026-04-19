<?php

namespace App\Services\Mobile;

use App\Models\Event;
use App\Models\User;

/**
 * Single source of truth for authorized event retrieval.
 *
 * Used by both the MCP `get-event-tool` and the `/api/v1/mobile/events/{id}`
 * endpoint — keeping the auth and eager-load shape identical across surfaces
 * is the whole reason this lives outside the controllers.
 */
class EventLookup
{
    public const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Find an event owned by the user (scoped through integrations).
     *
     * Returns null when the event does not exist, belongs to a different user,
     * or the caller's UUID is malformed.
     */
    public function find(User $user, string $eventId): ?Event
    {
        if (! preg_match(self::UUID_REGEX, $eventId)) {
            return null;
        }

        $integrationIds = $user->integrations()->pluck('id')->all();
        if (empty($integrationIds)) {
            return null;
        }

        return Event::query()
            ->whereIn('integration_id', $integrationIds)
            ->where('id', $eventId)
            ->with(['integration', 'actor', 'target', 'blocks', 'tags'])
            ->first();
    }
}
