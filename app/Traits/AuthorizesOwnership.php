<?php

namespace App\Traits;

/**
 * Shared ownership guard for Livewire/Volt detail components.
 *
 * Event/EventObject/Block are not protected by a global scope (they are
 * queried freely by background jobs that have no authenticated user), so
 * route-model-bound detail pages must assert ownership explicitly. This trait
 * centralises that check so the pattern is consistent and hard to forget.
 */
trait AuthorizesOwnership
{
    /**
     * Abort with 403 unless the given owner id matches the authenticated user.
     *
     * Pass the resolved owning user id — for records owned through a relation
     * (e.g. an Event via its integration) resolve it at the call site, e.g.
     * `$this->authorizeOwner($event->integration?->user_id)`.
     */
    protected function authorizeOwner(int|string|null $ownerId): void
    {
        abort_if($ownerId === null || (string) $ownerId !== (string) auth()->id(), 403);
    }
}
