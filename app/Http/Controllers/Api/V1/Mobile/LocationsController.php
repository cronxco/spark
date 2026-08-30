<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\CompactEventResource;
use App\Http\Resources\Compact\CompactObjectResource;
use App\Services\Api\LocationMutationService;
use App\Services\Api\ResourceVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationsController extends Controller
{
    public function __construct(private LocationMutationService $locations, private ResourceVersion $versions) {}

    public function set(Request $request, string $kind, string $id): JsonResponse
    {
        $data = $request->validate(['latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180'], 'address' => ['nullable', 'string', 'max:500']]);

        return $this->response($request, $kind, $this->locations->set($request->user(), $kind, $id, (float) $data['latitude'], (float) $data['longitude'], $data['address'] ?? null));
    }

    public function clear(Request $request, string $kind, string $id): JsonResponse
    {
        return $this->response($request, $kind, $this->locations->clear($request->user(), $kind, $id));
    }

    public function geocode(Request $request, string $kind, string $id): JsonResponse
    {
        $data = $request->validate(['address' => ['required', 'string', 'max:500']]);
        $entity = $this->locations->geocode($request->user(), $kind, $id, $data['address']);
        if (! $entity) {
            return response()->json(['message' => 'Entity not found or address could not be geocoded.'], 422);
        }

        return $this->response($request, $kind, $entity);
    }

    private function response(Request $request, string $kind, mixed $entity): JsonResponse
    {
        if (! $entity) {
            return response()->json(['message' => 'Entity not found.'], 404);
        }
        $payload = rtrim($kind, 's') === 'event' ? (new CompactEventResource($entity))->resolve($request) : (new CompactObjectResource($entity))->resolve($request);

        return response()->json($payload)->header('ETag', $this->versions->etag($entity));
    }
}
