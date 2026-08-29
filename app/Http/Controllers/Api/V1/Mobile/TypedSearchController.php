<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlockResource;
use App\Http\Resources\EventObjectResource;
use App\Http\Resources\EventResource;
use App\Services\Api\TypedSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TypedSearchController extends Controller
{
    public function __construct(private TypedSearchService $search) {}

    public function index(Request $request, string $type): JsonResponse
    {
        abort_unless(in_array($type, ['events', 'objects', 'blocks'], true), 404);
        $data = $request->validate([
            'query' => ['required', 'string', 'max:500'],
            'semantic' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'service' => ['nullable', 'string', 'max:100'], 'domain' => ['nullable', 'string', 'max:100'],
            'concept' => ['nullable', 'string', 'max:100'], 'object_type' => ['nullable', 'string', 'max:100'],
            'block_type' => ['nullable', 'string', 'max:100'], 'from_date' => ['nullable', 'date'], 'to_date' => ['nullable', 'date'],
        ]);
        $semantic = $request->boolean('semantic', true);
        $results = $this->search->search($request->user(), $type, $data['query'], $semantic, $data['limit'] ?? 20, $data);
        $resource = match ($type) {
            'events' => EventResource::collection($results)->resolve($request),
            'objects' => EventObjectResource::collection($results)->resolve($request),
            'blocks' => BlockResource::collection($results)->resolve($request),
        };
        if ($semantic) {
            $resource = $results->map(function ($model) use ($type, $request): array {
                $data = match ($type) {
                    'events' => (new EventResource($model))->resolve($request),
                    'objects' => (new EventObjectResource($model))->resolve($request),
                    'blocks' => (new BlockResource($model))->resolve($request),
                };
                if (isset($model->similarity)) {
                    $data['similarity'] = round(1 - $model->similarity, 4);
                }

                return $data;
            })->values()->all();
        }

        return response()->json([$type => $resource, 'meta' => ['query' => $data['query'], 'semantic' => $semantic, 'count' => $results->count(), 'limit' => $data['limit'] ?? 20]]);
    }
}
