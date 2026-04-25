<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\CompactBlockResource;
use App\Services\Mobile\BlockLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlocksController extends Controller
{
    public function __construct(protected BlockLookup $lookup) {}

    /**
     * GET /api/v1/mobile/blocks/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $block = $this->lookup->find($request->user(), $id);

        if (! $block) {
            return response()->json(['message' => 'Block not found.'], 404);
        }

        $response = response()->json(
            (new CompactBlockResource($block))->resolve($request),
        );

        if ($block->updated_at) {
            $response->header('Last-Modified', $block->updated_at->toRfc7231String());
        }

        return $response;
    }
}
