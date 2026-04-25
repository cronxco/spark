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
}
