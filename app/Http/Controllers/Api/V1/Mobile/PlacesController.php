<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\CompactPlaceResource;
use App\Services\Mobile\ObjectLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlacesController extends Controller
{
    public function __construct(protected ObjectLookup $lookup) {}

    /**
     * GET /api/v1/mobile/places/{id}
     *
     * Places are EventObjects with concept='place' — reusing ObjectLookup keeps
     * authorization consistent with /objects/{id}; this endpoint just adds a
     * concept guard and returns the geo-aware resource shape.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $object = $this->lookup->find($request->user(), $id);

        if (! $object || $object->concept !== 'place') {
            return response()->json(['message' => 'Place not found.'], 404);
        }

        $response = response()->json(
            (new CompactPlaceResource($object))->resolve($request),
        );

        if ($object->updated_at) {
            $response->header('Last-Modified', $object->updated_at->toRfc7231String());
        }

        return $response;
    }
}
