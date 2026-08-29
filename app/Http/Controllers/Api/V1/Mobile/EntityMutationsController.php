<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\CompactBlockResource;
use App\Http\Resources\Compact\CompactEventResource;
use App\Http\Resources\Compact\CompactObjectResource;
use App\Services\Api\EntityMutationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntityMutationsController extends Controller
{
    public function __construct(private EntityMutationService $mutations) {}

    public function update(Request $request, string $kind, string $id): JsonResponse
    {
        $kind = rtrim($kind, 's');
        if (! in_array($kind, ['event', 'object', 'block'], true)) {
            abort(404);
        }
        $attributes = $this->mutations->validateUpdate($kind, $request->all());
        $entity = match ($kind) {
            'event' => $this->mutations->updateEvent($request->user(), $id, $attributes),
            'object' => $this->mutations->updateObject($request->user(), $id, $attributes),
            'block' => $this->mutations->updateBlock($request->user(), $id, $attributes),
        };
        if (! $entity) {
            return response()->json(['message' => ucfirst($kind) . ' not found.'], 404);
        }

        return response()->json($this->resource($kind, $entity, $request));
    }

    public function relationships(Request $request, string $kind, string $id): JsonResponse
    {
        $kind = rtrim($kind, 's');
        $relationships = $this->mutations->relationships($request->user(), $kind, $id);
        if ($relationships === null) {
            return response()->json(['message' => ucfirst($kind) . ' not found.'], 404);
        }

        return response()->json(['data' => $relationships]);
    }

    public function storeRelationship(Request $request, string $kind, string $id): JsonResponse
    {
        $kind = rtrim($kind, 's');
        $attributes = $this->mutations->validateRelationship($request->all());
        $relationship = $this->mutations->createRelationship($request->user(), $kind, $id, $attributes);
        if (! $relationship) {
            return response()->json(['message' => 'Relationship endpoints, type, or ownership are invalid.'], 422);
        }

        return response()->json($this->mutations->relationshipPayload($relationship), 201);
    }

    public function destroyRelationship(Request $request, string $relationship): JsonResponse
    {
        if (! $this->mutations->deleteRelationship($request->user(), $relationship)) {
            return response()->json(['message' => 'Relationship not found.'], 404);
        }

        return response()->json([], 204);
    }

    private function resource(string $kind, mixed $entity, Request $request): array
    {
        return match ($kind) {
            'event' => (new CompactEventResource($entity))->resolve($request),
            'object' => (new CompactObjectResource($entity))->resolve($request),
            'block' => (new CompactBlockResource($entity))->resolve($request),
        };
    }
}
