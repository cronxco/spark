<?php

namespace App\Services;

use App\Http\Resources\Compact\CompactEventResource;
use App\Models\Event;
use App\Models\User;
use App\Services\Mobile\EventLookup;

class EventNoteService
{
    public function __construct(protected EventLookup $lookup) {}

    /**
     * Set or clear the user-authored note for an owned event.
     */
    public function set(User $user, string $eventId, ?string $note): ?Event
    {
        $event = $this->lookup->find($user, $eventId);
        if (! $event) {
            return null;
        }

        $note = $note === null ? null : trim($note);
        $existing = $event->blocks->first(
            fn ($block) => $block->block_type === CompactEventResource::NOTE_BLOCK_TYPE && $block->deleted_at === null,
        );

        if ($note === null || $note === '') {
            $existing?->delete();
        } else {
            $attributes = [
                'title' => 'Note',
                'block_type' => CompactEventResource::NOTE_BLOCK_TYPE,
                'time' => $event->time,
                'metadata' => ['content' => $note],
            ];

            if ($existing) {
                $existing->update($attributes);
            } else {
                $event->createBlock($attributes);
            }
        }

        // A note is part of the event's editable representation. Touching the
        // parent advances its strong ETag even though the persisted child is a
        // block, so a stale note edit cannot overwrite a newer one.
        $event->touch();

        return $this->lookup->find($user, $eventId);
    }
}
