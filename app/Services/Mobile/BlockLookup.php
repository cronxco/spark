<?php

namespace App\Services\Mobile;

use App\Models\Block;
use App\Models\User;

class BlockLookup
{
    /**
     * Find a block owned (indirectly, via its parent event's integration) by the user.
     */
    public function find(User $user, string $blockId): ?Block
    {
        if (! preg_match(EventLookup::UUID_REGEX, $blockId)) {
            return null;
        }

        $integrationIds = $user->integrations()->pluck('id')->all();
        if (empty($integrationIds)) {
            return null;
        }

        return Block::query()
            ->where('id', $blockId)
            ->whereHas('event', fn ($q) => $q->whereIn('integration_id', $integrationIds))
            ->with(['event.integration', 'event.actor', 'event.target'])
            ->first();
    }
}
