<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\CompactEventResource;
use App\Http\Resources\Compact\CompactObjectResource;
use App\Services\Mobile\ObjectLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ObjectsController extends Controller
{
    public function __construct(protected ObjectLookup $lookup) {}

    /**
     * GET /api/v1/mobile/objects/{id}
     *
     * `include_events` (bool, default true) — attach recent events.
     * `event_limit` (int, 1..25) — cap on recent events.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $object = $this->lookup->find($user, $id);

        if (! $object) {
            return response()->json(['message' => 'Object not found.'], 404);
        }

        $payload = (new CompactObjectResource($object))->resolve($request);

        if ($request->boolean('include_events', true)) {
            $limit = (int) $request->query('event_limit', ObjectLookup::EVENT_LIMIT_DEFAULT);
            $events = $this->lookup->recentEvents($object, $user, $limit);
            $payload['recent_events'] = CompactEventResource::collection($events)->resolve($request);
        }

        $response = response()->json($payload);

        if ($object->updated_at) {
            $response->header('Last-Modified', $object->updated_at->toRfc7231String());
        }

        return $response;
    }
}
