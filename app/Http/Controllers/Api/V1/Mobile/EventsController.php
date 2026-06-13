<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\CompactEventResource;
use App\Services\Mobile\EventLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventsController extends Controller
{
    public function __construct(protected EventLookup $lookup) {}

    /**
     * GET /api/v1/mobile/events/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $event = $this->lookup->find($request->user(), $id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $response = response()->json(
            (new CompactEventResource($event))->resolve($request),
        );

        if ($event->updated_at) {
            $response->header('Last-Modified', $event->updated_at->toRfc7231String());
        }

        return $response;
    }

    /**
     * PATCH /api/v1/mobile/events/{id}/note
     *
     * Sets or clears the user-authored note for an event. The note is stored
     * as a dedicated `note` block (see CompactEventResource::NOTE_BLOCK_TYPE);
     * a null/empty body clears it. Returns the full event detail shape so the
     * client can re-render without a follow-up fetch.
     */
    public function updateNote(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $event = $this->lookup->find($user, $id);

        if (! $event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $validated = $request->validate([
            'note' => ['present', 'nullable', 'string', 'max:10000'],
        ]);

        $note = is_string($validated['note'] ?? null) ? trim($validated['note']) : null;

        // blocks() is withTrashed(); only act on the live note block.
        $existing = $event->blocks->first(
            fn ($block) => $block->block_type === CompactEventResource::NOTE_BLOCK_TYPE && $block->deleted_at === null,
        );

        if ($note === null || $note === '') {
            $existing?->delete();
        } else {
            $event->createBlock([
                'title' => 'Note',
                'block_type' => CompactEventResource::NOTE_BLOCK_TYPE,
                'time' => $event->time,
                'metadata' => ['content' => $note],
            ]);
        }

        // Reload so the resource reflects the freshly written/cleared note block.
        $event = $this->lookup->find($user, $id);

        return response()->json(
            (new CompactEventResource($event))->resolve($request),
        );
    }
}
